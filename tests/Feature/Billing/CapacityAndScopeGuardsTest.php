<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\AgreementSelector;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\CorrectionFacts;
use App\Support\Billing\DeliberateCorrections;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Second-round review findings, pinned.
 *
 * Every one of these is a rule that was written correctly in one place and
 * then answered differently somewhere else - the shape this port keeps
 * producing. They are grouped here because the fix in each case was to give the
 * rule a single definition, and a test that fails when a caller writes its own
 * again is the only thing that keeps it that way.
 */
final class CapacityAndScopeGuardsTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Guards2', 'slug' => 'guards2']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Second Client', 'slug' => 'second-client',
        ]);
        $this->user = User::factory()->create();
    }

    // ── Capacity ─────────────────────────────────────────────────────────────

    /**
     * Allocation attaches a deferred entry to a retainer line and leaves
     * `is_deferred` set. Four ledger queries read that flag alone, so once the
     * invoice was issued the hours disappeared from every later rebuild, the
     * same pool rolled forward again, and the next overage was understated.
     */
    public function test_deferred_work_counts_against_the_retainer_once_it_has_been_billed(): void
    {
        $project = $this->project('Main');
        $agreement = $this->agreement();
        $entry = $this->entry($project, '2024-01-10', 300, deferred: true);

        $unallocated = $this->hoursIn($agreement, '2024-01-31');
        $this->assertSame(0.0, $unallocated, 'Deferred work waiting on the allocator draws nothing');

        // Standing in for allocation: the entry is now on an invoice line.
        $invoice = $this->invoice($agreement);
        $line = $invoice->lines()->create([
            'workspace_id' => $this->workspace->id, 'type' => 'retainer', 'description' => 'Allocated',
            'quantity' => '5', 'unit_amount' => 0, 'total_amount' => 0, 'tax_amount' => 0, 'sort_order' => 1,
        ]);
        $entry->invoiceLines()->attach($line->id, ['workspace_id' => $this->workspace->id]);

        $this->assertSame(
            5.0,
            $this->hoursIn($agreement, '2024-01-31'),
            'Billed is billed, whatever the deferred flag still says',
        );
    }

    /**
     * A draft catch-up invoice may never be issued. Counting its hours as debt
     * the client has settled grants capacity against money nobody was asked
     * for, so the sum reads charged statuses rather than everything non-void.
     */
    public function test_a_draft_overage_invoice_is_not_counted_as_already_charged(): void
    {
        $agreement = $this->agreement();

        $draft = $this->invoice($agreement);
        $draft->forceFill([
            'status' => 'draft',
            'hours_billed_at_rate' => '5',
            'service_period_end' => '2024-01-31',
        ])->save();

        $this->assertSame(0.0, $this->billedOverages($agreement, '2024-02-29'), 'A draft has charged nobody');

        $draft->forceFill(['status' => 'issued'])->save();
        $this->assertSame(5.0, $this->billedOverages($agreement, '2024-02-29'));

        $draft->forceFill(['status' => 'partially_paid'])->save();
        $this->assertSame(5.0, $this->billedOverages($agreement, '2024-02-29'), 'Part-paid is charged');

        $draft->forceFill(['status' => 'void'])->save();
        $this->assertSame(0.0, $this->billedOverages($agreement, '2024-02-29'), 'A void invoice never happened');
    }

    /**
     * `service_period_end <= $end` is false for a null rather than unknown, so
     * an invoice whose period could not be placed left the sum silently. The
     * overage it had already charged was then invisible to the next period's
     * balance, which billed the same hours a second time.
     *
     * Pinned against a placed invoice still outside the window, so it cannot
     * pass by the window having stopped filtering at all - the failure mode a
     * bare `orWhereNull` in the wrong place would produce.
     */
    public function test_a_charged_invoice_with_no_service_period_is_still_counted_as_billed(): void
    {
        $agreement = $this->agreement();

        $unplaceable = $this->invoice($agreement);
        $unplaceable->forceFill([
            'status' => 'issued', 'hours_billed_at_rate' => '5', 'service_period_end' => '2024-01-31',
        ])->save();

        $later = $this->invoice($agreement);
        $later->forceFill([
            'status' => 'issued', 'hours_billed_at_rate' => '9', 'service_period_end' => '2024-06-30',
        ])->save();

        $this->assertSame(5.0, $this->billedOverages($agreement, '2024-02-29'), 'The window has an end');

        $unplaceable->forceFill(['service_period_end' => null])->save();

        $this->assertSame(
            5.0,
            $this->billedOverages($agreement, '2024-02-29'),
            'An unplaceable period reads as inside the window, not outside it',
        );
        // Asked past the later invoice rather than on its own period end: the
        // stored value carries a midnight time component, so `<=` against a
        // bare date excludes an invoice ending exactly on it. That is a second
        // defect on this same line, tracked separately - pinning it here would
        // make this test fail for a reason it is not about.
        $this->assertSame(
            14.0,
            $this->billedOverages($agreement, '2024-07-31'),
            'And it is counted once, not once per period it could belong to',
        );
    }

    /**
     * The null case is a widening of the date window and nothing else. Every
     * other condition on the sum still has to hold, which is what stops the
     * repair for one fail-open read from opening three more.
     */
    public function test_an_unplaceable_period_does_not_excuse_the_rest_of_the_window(): void
    {
        $agreement = $this->agreement();
        $other = $this->agreement();

        $uncharged = $this->invoice($agreement);
        $uncharged->forceFill([
            'status' => 'draft', 'hours_billed_at_rate' => '5', 'service_period_end' => null,
        ])->save();

        $elsewhere = $this->invoice($other);
        $elsewhere->forceFill([
            'status' => 'issued', 'hours_billed_at_rate' => '7', 'service_period_end' => null,
        ])->save();

        $this->assertSame(
            0.0,
            $this->billedOverages($agreement, '2024-02-29'),
            'A missing period does not make a draft charged, nor another agreement this one',
        );

        $uncharged->forceFill(['status' => 'issued'])->save();

        $this->assertSame(5.0, $this->billedOverages($agreement, '2024-02-29'), 'Its own charged hours do count');
        $this->assertSame(7.0, $this->billedOverages($other, '2024-02-29'), 'Each against its own agreement');
    }

    /**
     * `full_period` says what happens to the *first* cycle. Applied to any
     * partial month it charges a whole fee for a termination month whose
     * capacity is already prorated - the client pays for a month and gets part
     * of one.
     */
    public function test_a_full_period_agreement_still_prorates_its_termination_month(): void
    {
        $agreement = $this->agreement();
        $agreement->forceFill([
            'first_cycle_proration' => 'full_period',
            'ends_on' => '2024-03-15',
        ])->save();

        $calculator = new RetainerCalculator;

        $this->assertSame(
            1.0,
            $calculator->monthRetainerMultiplier($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-01-31')),
            'The opening month is what full_period is for',
        );
        $this->assertLessThan(
            1.0,
            $calculator->monthRetainerMultiplier($agreement, Carbon::parse('2024-03-01'), Carbon::parse('2024-03-31')),
            'A termination month is not a first cycle',
        );
    }

    /**
     * The invoice line is built from `period_retainer_minutes` when it is set.
     * Capacity read `retainer_minutes` directly, so an imported agreement
     * carrying both sold one number of hours and granted another.
     */
    public function test_capacity_uses_the_same_retainer_hours_the_invoice_sells(): void
    {
        $agreement = $this->agreement();
        $agreement->forceFill(['period_retainer_minutes' => 1200])->save(); // 20h, against retainer_minutes' 10h

        $this->assertSame(20.0, $agreement->fresh()->periodRetainerHours());
        $this->assertSame(
            20.0,
            (new RetainerCalculator)->retainerHoursForMonth(
                $agreement->fresh(),
                Carbon::parse('2024-02-01'),
                Carbon::parse('2024-02-29'),
            ),
            'Capacity must grant the hours the invoice charged for',
        );
    }

    // ── Scope ────────────────────────────────────────────────────────────────

    /**
     * Ordinary time was project-scoped; milestones were not. Whichever
     * agreement generated first claimed the other project's deliverable and
     * attached it permanently to the wrong invoice.
     */
    public function test_a_project_scoped_agreement_does_not_claim_another_projects_milestone(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');
        $agreement = $this->agreement($mine);

        $task = ClientTask::query()->create([
            'workspace_id' => $this->workspace->id, 'client_project_id' => $theirs->id,
            'title' => 'Their deliverable', 'status' => 'completed',
            'completed_at' => '2024-02-20', 'milestone_price_amount' => 25000,
        ]);

        $invoice = $this->invoice($agreement);
        $sort = 1;
        app(InvoiceLineComposer::class)->addBillableMilestoneTasks(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-29'),
            $sort,
        );

        $this->assertNull($task->refresh()->client_invoice_line_id, "The other project's milestone is not this agreement's");
        $this->assertSame(0, $invoice->lines()->where('type', 'milestone')->count());
    }

    /**
     * The same omission on the flat-hourly subcontractor path.
     */
    public function test_a_project_scoped_agreement_does_not_bill_another_projects_subcontractor_time(): void
    {
        $mine = $this->project('Mine');
        $theirs = $this->project('Theirs');
        $agreement = $this->agreement($mine);

        $entry = $this->entry($theirs, '2024-02-10', 120);
        $entry->forceFill(['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD'])->save();

        $invoice = $this->invoice($agreement);
        $sort = 1;
        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $sort,
        );

        $this->assertSame(0, $invoice->lines()->where('type', 'subcontractor')->count());
    }

    /**
     * The cross-currency refusal reads one entry per group, so the currency has
     * to be part of what makes a group. Two entries costed at the same number
     * in different currencies otherwise share one, and only the first is
     * checked - which bills the other currency's minutes at this one's rate.
     */
    public function test_two_currencies_at_the_same_rate_do_not_share_a_subcontractor_group(): void
    {
        $project = $this->project('Mixed currency');
        $agreement = $this->agreement($project);

        // Same number, different currencies, same worker and project. The USD
        // one sorts first, so it is the sample the refusal would inspect.
        $this->entry($project, '2024-02-10', 60)
            ->forceFill(['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD'])->save();
        $this->entry($project, '2024-02-11', 60)
            ->forceFill(['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'EUR'])->save();

        $invoice = $this->invoice($agreement);
        $sort = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('costed in EUR');

        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $sort,
        );
    }

    public function test_flat_hourly_time_without_a_complete_snapshot_is_refused(): void
    {
        $project = $this->project('Missing terms');
        $agreement = $this->agreement($project);
        $this->entry($project, '2024-02-10', 60)
            ->forceFill(['subcontractor_billing_mode' => 'flat_hourly'])->save();

        $invoice = $this->invoice($agreement);
        $sort = 1;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a snapshotted amount and currency');

        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $sort,
        );
    }

    public function test_direct_subcontractor_time_never_reaches_the_flat_hourly_composer(): void
    {
        $project = $this->project('Direct billing');
        $agreement = $this->agreement($project);
        $direct = $this->entry($project, '2024-02-10', 60);
        $direct->forceFill(['subcontractor_billing_mode' => 'direct'])->save();

        $invoice = $this->invoice($agreement);
        $sort = 1;
        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $sort,
        );

        $this->assertSame(0, $invoice->lines()->where('type', 'subcontractor')->count());
        $this->assertFalse($direct->invoiceLines()->exists());
    }

    public function test_another_tenants_malformed_flat_hourly_snapshot_cannot_block_ours(): void
    {
        $project = $this->project('Tenant boundary');
        $agreement = $this->agreement($project);
        $otherWorkspace = Workspace::query()->create(['name' => 'Foreign modes', 'slug' => 'foreign-modes']);

        // Unstorable since #113; written with enforcement suspended so the
        // composer's own scoping stays the subject of the test.
        $this->writingLegacyCrossTenantRows(fn () => ClientTimeEntry::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2024-02-09',
            'minutes' => 60,
            'description' => 'Malformed foreign snapshot',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
            'currency' => 'USD',
            'subcontractor_billing_mode' => 'flat_hourly',
        ]));
        $local = $this->entry($project, '2024-02-10', 60);
        $local->forceFill([
            'subcontractor_billing_mode' => 'flat_hourly',
            'subcontractor_cost_amount' => 5000,
            'subcontractor_cost_currency' => 'USD',
        ])->save();

        $invoice = $this->invoice($agreement);
        $sort = 1;
        app(InvoiceLineComposer::class)->addSubcontractorFlatHourlyLines(
            $this->company,
            $invoice,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-29'),
            $sort,
        );

        $line = $invoice->lines()->where('type', 'subcontractor')->sole();
        $this->assertSame(5000, $line->total_amount);
        $this->assertTrue($line->timeEntries()->whereKey($local->id)->exists());
    }

    /**
     * A company id is globally unique, so an agreement row carrying it under
     * another tenant's workspace is reachable through the foreign key alone.
     * The explicit-agreement path validates both keys; the automatic selectors
     * never passed through it.
     */
    public function test_automatic_agreement_selection_ignores_another_tenants_row(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        // Unstorable since #113; written with enforcement suspended so the
        // selector's own scoping stays the subject of the test.
        $this->writingLegacyCrossTenantRows(fn () => ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id, // malformed: our company, their tenant
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'EUR', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 999900, 'hourly_rate_amount' => 99900,
            'rollover_months' => 0,
        ]));

        $selector = app(AgreementSelector::class);

        $this->expectException(RuntimeException::class);
        $selector->agreementForInvoiceGeneration($this->company->fresh());
    }

    public function test_a_date_based_selector_ignores_another_tenants_row(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        // Unstorable since #113; see the sibling test above.
        $this->writingLegacyCrossTenantRows(fn () => ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'EUR', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 999900, 'hourly_rate_amount' => 99900,
            'rollover_months' => 0,
        ]));

        $this->assertNull(
            app(AgreementSelector::class)->agreementCoveringDate($this->company, CarbonImmutable::parse('2024-06-01')),
        );
    }

    /**
     * The cadence path was hardened against a foreign agreement; the delegated
     * interim path performed only a null check.
     */
    public function test_interim_generation_refuses_another_companys_agreement(): void
    {
        $other = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Other', 'slug' => 'other-co',
        ]);
        $foreign = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $other->id,
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2024-01-01',
            'billing_cadence' => 'quarterly', 'bill_overage_interim' => true,
            'retainer_minutes' => 600, 'retainer_amount' => 150000, 'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('different client company');

        app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            $foreign,
        );
    }

    public function test_interim_generation_rechecks_project_chains_when_given_a_cached_ledger(): void
    {
        $project = $this->project('Cached Ledger Project');
        $agreement = $this->quarterlyAgreement();
        $entry = $this->entry($project, '2024-01-15', 1800);
        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse('2024-01-31'),
            true,
        );

        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Cached Ledger Other Client',
            'slug' => 'cached-ledger-other-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Cached Ledger Other Project',
        ]);
        $entry->forceFill(['client_project_id' => $otherProject->id])->save();

        try {
            app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
                $this->company,
                Carbon::parse('2024-01-01'),
                $agreement,
                $ledger,
            );
            $this->fail('A cached ledger must not bypass the project-chain guard.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('project outside this client company', $exception->getMessage());
        }

        $this->assertDatabaseCount('client_invoices', 0);
        $this->assertDatabaseCount('client_invoice_lines', 0);
        $this->assertDatabaseCount('client_invoice_line_time_entries', 0);
    }

    /**
     * A migrated invoice carries no `invoice_kind`, and a null kind reads as
     * cadence everywhere else. Excluding it here made the sold-cycle guard
     * blind to exactly the data it most needs to see.
     */
    public function test_a_migrated_invoice_with_no_kind_still_counts_as_having_sold_the_cycle(): void
    {
        $project = $this->project('Main');
        $agreement = $this->agreement();
        $this->entry($project, '2024-02-05', 120);

        $sold = $this->invoice($agreement);
        $sold->forceFill([
            'invoice_kind' => null,
            'cycle_start' => '2024-02-01',
            'cycle_end' => '2024-02-29',
            'service_period_start' => '2024-01-01',
            'service_period_end' => '2024-01-31',
        ])->save();
        $sold->lines()->create([
            'workspace_id' => $this->workspace->id, 'type' => 'retainer', 'description' => 'Retainer',
            'quantity' => '1', 'unit_amount' => 150000, 'total_amount' => 150000, 'tax_amount' => 0, 'sort_order' => 1,
        ]);

        $correction = app(ClientInvoicingService::class)->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-15'),
        )->refresh();

        $this->assertSame(
            0,
            $correction->lines()->where('type', 'retainer')->count(),
            'The migrated invoice sold this cycle even though it carries no kind',
        );
    }

    /**
     * An unrecognised status is one this code cannot show is safe to rewrite.
     * `NOT IN (settled)` reads it as releasable, which is the opposite.
     */
    public function test_an_interim_invoice_with_an_unknown_status_is_not_released(): void
    {
        $agreement = $this->quarterlyAgreement();
        $interim = $this->invoice($agreement);
        $interim->forceFill([
            'invoice_kind' => 'interim_overage',
            'status' => 'awaiting_dispute_resolution',
            'cycle_start' => '2024-01-01',
            'cycle_end' => '2024-03-31',
        ])->save();
        $interim->lines()->create([
            'workspace_id' => $this->workspace->id, 'type' => 'additional_hours', 'description' => 'Overage',
            'quantity' => '1', 'unit_amount' => 20000, 'total_amount' => 20000, 'tax_amount' => 0, 'sort_order' => 1,
        ]);

        app(InterimOverageGenerator::class)->releaseUnchargedInterimClaims(
            $this->company,
            $agreement,
            app(BillingCycleResolver::class)->cycleContaining($agreement, Carbon::parse('2024-01-15')),
        );

        $this->assertSame(1, $interim->refresh()->lines()->count(), 'A status this code does not know is left alone');
    }

    /**
     * Releasing deletes the lines. Leaving the stored totals behind means the
     * draft displays a charge it no longer has, and `issue()` would send it.
     */
    public function test_releasing_an_interim_draft_leaves_its_totals_consistent(): void
    {
        $agreement = $this->quarterlyAgreement();
        $interim = $this->invoice($agreement);
        $interim->forceFill([
            'invoice_kind' => 'interim_overage',
            'cycle_start' => '2024-01-01',
            'cycle_end' => '2024-03-31',
            'subtotal_amount' => 20000, 'total_amount' => 20000, 'balance_amount' => 20000,
        ])->save();
        $interim->lines()->create([
            'workspace_id' => $this->workspace->id, 'type' => 'additional_hours', 'description' => 'Overage',
            'quantity' => '1', 'unit_amount' => 20000, 'total_amount' => 20000, 'tax_amount' => 0, 'sort_order' => 1,
        ]);

        app(InterimOverageGenerator::class)->releaseUnchargedInterimClaims(
            $this->company,
            $agreement,
            app(BillingCycleResolver::class)->cycleContaining($agreement, Carbon::parse('2024-01-15')),
        );

        $interim->refresh();
        $this->assertSame(0, $interim->lines()->count());
        $this->assertSame(0, (int) $interim->total_amount, 'An emptied draft must not still claim a charge');
        $this->assertSame(0, (int) $interim->balance_amount);
    }

    /**
     * The release runs a tenant-owned query and then deletes rows. A malformed
     * row carrying this company's id under another workspace must not be
     * reachable through it.
     */
    public function test_releasing_interim_claims_cannot_reach_another_tenants_invoice(): void
    {
        $agreement = $this->quarterlyAgreement();

        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere-interim']);
        // Unstorable since #113; written with enforcement suspended so the
        // release query's own scoping stays the subject of the test.
        $foreign = $this->writingLegacyCrossTenantRows(function () use ($otherWorkspace, $agreement): ClientInvoice {
            $invoice = ClientInvoice::query()->create([
                'workspace_id' => $otherWorkspace->id,
                'client_company_id' => $this->company->id,   // malformed: our company, their tenant
                'client_agreement_id' => $agreement->id,     // and our agreement
                'invoice_number' => 'X-'.uniqid(),
                'status' => 'draft',
                'currency' => 'USD',
                'invoice_kind' => 'interim_overage',
                'cycle_start' => '2024-01-01',
                'cycle_end' => '2024-03-31',
                'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            ]);
            $invoice->lines()->create([
                'workspace_id' => $otherWorkspace->id, 'type' => 'additional_hours', 'description' => 'Theirs',
                'quantity' => '1', 'unit_amount' => 20000, 'total_amount' => 20000, 'tax_amount' => 0, 'sort_order' => 1,
            ]);

            return $invoice;
        });

        app(InterimOverageGenerator::class)->releaseUnchargedInterimClaims(
            $this->company,
            $agreement,
            app(BillingCycleResolver::class)->cycleContaining($agreement, Carbon::parse('2024-01-15')),
        );

        $this->assertSame(1, $foreign->refresh()->lines()->count(), "Another tenant's invoice is untouched");
    }

    /**
     * A correction range lands on a cycle an earlier invoice already sold.
     *
     * Billing 1-15 February as a correction derives the February cycle that
     * January's ordinary invoice sold. The service-period overlap guard cannot
     * see it - the periods genuinely do not overlap - so the retainer and every
     * recurring item were charged a second time.
     */
    public function test_a_correction_range_does_not_resell_a_cycle_already_sold(): void
    {
        $project = $this->project('Main');
        $agreement = $this->agreement();
        $this->entry($project, '2024-01-10', 120);
        $this->entry($project, '2024-02-05', 120);

        $service = app(ClientInvoicingService::class);

        // January's work sells the February retainer.
        $ordinary = $service->generateInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            Carbon::parse('2024-01-31'),
        )->refresh();

        $this->assertSame('2024-02-01', Carbon::parse((string) $ordinary->cycle_start)->toDateString());
        $this->assertSame(1, $ordinary->lines()->where('type', 'retainer')->count());

        // A correction covering part of February derives the same cycle.
        $correction = $service->generateInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            Carbon::parse('2024-02-15'),
        )->refresh();

        $this->assertSame(
            0,
            $correction->lines()->where('type', 'retainer')->count(),
            'The February retainer was sold by the January invoice and must not be sold again',
        );
        $this->assertSame(0, $correction->lines()->where('type', 'recurring_item')->count());
    }

    /**
     * An interim draft claims its entries immediately, but only a charged
     * interim counts toward the cadence reconciliation - so work held by a
     * draft was invisible to the selector and absent from the reconciliation,
     * and nothing billed it.
     */
    public function test_an_uncharged_interim_draft_gives_its_work_back(): void
    {
        $project = $this->project('Main');
        $agreement = $this->quarterlyAgreement();

        // Comfortably over the quarter's retainer, in a completed month.
        $this->entry($project, '2024-01-15', 1800);

        $interim = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            $agreement,
        );

        $this->assertInstanceOf(ClientInvoice::class, $interim);
        $this->assertSame('draft', (string) $interim->status);
        $this->assertGreaterThan(0, $interim->lines()->count());

        $released = app(InterimOverageGenerator::class)->releaseUnchargedInterimClaims(
            $this->company,
            $agreement,
            app(BillingCycleResolver::class)->cycleContaining($agreement, Carbon::parse('2024-01-15')),
        );

        $this->assertSame(1, $released);
        $this->assertSame(0, $interim->refresh()->lines()->count(), 'The draft no longer holds the work');
        // The interim split the entry into a billed fragment and a covered one,
        // so the row count is not the invariant. Every worked minute being
        // available again is.
        $this->assertSame(
            1800,
            (int) ClientTimeEntry::query()
                ->where('client_company_id', $this->company->id)
                ->unbilled()
                ->sum('minutes'),
            'Every minute is available to the cadence invoice again',
        );
    }

    /**
     * The interim path keeps its own already-billed sum, bounded by
     * `service_period_end < period start` - false for a null, so a charged
     * interim invoice whose period was lost would leave the subtraction and
     * its hours would be billed a second time. The same fail-closed widening
     * as `totalBilledOveragesThrough`: a null period reads as already billed.
     */
    public function test_a_charged_interim_invoice_with_no_service_period_still_reduces_the_next_interim(): void
    {
        $project = $this->project('Main');
        $agreement = $this->quarterlyAgreement();

        // 30h in January and 20h in February against a 10h/month retainer.
        $this->entry($project, '2024-01-15', 1800);
        $this->entry($project, '2024-02-10', 1200);

        $first = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-01-01'),
            $agreement,
        );

        $this->assertInstanceOf(ClientInvoice::class, $first);
        $this->assertSame(20.0, (float) $first->hours_billed_at_rate, "January's excess over its retainer");
        $first->forceFill(['status' => 'issued', 'service_period_end' => null])->save();

        $second = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            $agreement,
        );

        $this->assertInstanceOf(ClientInvoice::class, $second);
        $this->assertSame(
            10.0,
            (float) $second->hours_billed_at_rate,
            "Only February's new excess: the unplaceable interim already charged January's",
        );
    }

    /**
     * The widening is grouped inside the date window and nothing else. An
     * unplaceable *draft* has charged nobody, so it must not suppress the
     * interim that would actually bill the work - the leak an ungrouped
     * `orWhereNull` would open.
     */
    public function test_an_unplaceable_interim_draft_does_not_suppress_interim_billing(): void
    {
        $project = $this->project('Main');
        $agreement = $this->quarterlyAgreement();
        $this->entry($project, '2024-01-15', 1800);
        $this->entry($project, '2024-02-10', 1200);

        $draft = $this->invoice($agreement);
        $draft->forceFill([
            'invoice_kind' => 'interim_overage',
            'status' => 'draft',
            'hours_billed_at_rate' => '20',
            'cycle_start' => '2024-01-01',
            'cycle_end' => '2024-03-31',
            'service_period_end' => null,
        ])->save();

        $interim = app(InterimOverageGenerator::class)->generateInterimOverageInvoice(
            $this->company,
            Carbon::parse('2024-02-01'),
            $agreement,
        );

        $this->assertInstanceOf(
            ClientInvoice::class,
            $interim,
            'A draft with no period has still charged nobody, so the overage is still owed',
        );
        $this->assertSame(
            20.0,
            (float) $interim->hours_billed_at_rate,
            "All of February's billable work is still owed, not the remainder after the draft",
        );
    }

    // ── Replay attribution ───────────────────────────────────────────────────

    /**
     * The load-bearing error in the first classifier. None of the three
     * capacity corrections can move a contracted fee, but `retainer` sat in
     * their allowlist, so ten whole-invoice disappearances - every generated
     * line gone, the fee with them - were reported as deliberate.
     */
    public function test_no_capacity_correction_explains_a_change_to_the_retainer_fee(): void
    {
        $facts = $this->facts(rolloverMonths: 3, fullyUsedMonth: true, deferredWork: true);

        $this->assertSame([], DeliberateCorrections::explaining(['retainer'], ['subtotal'], $facts));
        $this->assertNotSame([], DeliberateCorrections::explaining(['additional_hours'], ['subtotal'], $facts));

        // A line type is whatever string its source used, so no spelling of the
        // invoice-field marker is safe to reserve. Naming fields separately is
        // what stops a line literally typed "subtotal" - or "#subtotal" - being
        // read as the invoice's own subtotal and waived by a correction that
        // says nothing about it.
        $this->assertSame([], DeliberateCorrections::explaining(['subtotal'], [], $facts));
        $this->assertSame([], DeliberateCorrections::explaining(['#subtotal'], [], $facts));
    }

    /**
     * Enabling rollover is opportunity, not causation. The correction only
     * reaches a period where some month consumed its whole retainer, because a
     * month with hours left over aged correctly in the original too.
     */
    public function test_rollover_only_explains_a_divergence_when_a_month_was_fully_used(): void
    {
        $this->assertSame(
            [],
            DeliberateCorrections::explaining(
                ['additional_hours'],
                [],
                $this->facts(rolloverMonths: 3, fullyUsedMonth: false),
            ),
            'rollover_months > 0 alone is not the trigger',
        );

        $explained = DeliberateCorrections::explaining(
            ['additional_hours'],
            [],
            $this->facts(rolloverMonths: 3, fullyUsedMonth: true),
        );
        $this->assertSame('rollover_expiry_ages_by_calendar', $explained[0]['key']);
    }

    /**
     * A mid-month cycle is not the trigger either: the fallback only re-bills
     * an anchor the previous cycle already covered.
     */
    public function test_the_recurring_fallback_needs_an_anchor_the_last_cycle_covered(): void
    {
        $this->assertSame([], DeliberateCorrections::explaining(
            ['recurring_item'],
            [],
            $this->facts(cycleOpensMidMonth: true, recurringAnchoredBefore: false),
        ));

        $this->assertNotSame([], DeliberateCorrections::explaining(
            ['recurring_item'],
            [],
            $this->facts(cycleOpensMidMonth: true, recurringAnchoredBefore: true),
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function facts(
        int $rolloverMonths = 0,
        bool $fullyUsedMonth = false,
        bool $projectScoped = false,
        bool $otherProjectWork = false,
        bool $deferredWork = false,
        bool $cycleOpensMidMonth = false,
        bool $recurringAnchoredBefore = false,
    ): CorrectionFacts {
        return new CorrectionFacts(
            rolloverMonths: $rolloverMonths,
            fullyUsedMonthInRolloverWindow: $fullyUsedMonth,
            projectScoped: $projectScoped,
            otherProjectWork: $otherProjectWork,
            deferredWork: $deferredWork,
            cycleOpensMidMonth: $cycleOpensMidMonth,
            recurringItemAnchoredBeforeCycleOpens: $recurringAnchoredBefore,
        );
    }

    /**
     * The private sum that decides how much overage debt is already settled.
     * Reached directly because generation exposes it only as one term inside a
     * balance, where a wrong answer is indistinguishable from ordinary rounding.
     */
    private function billedOverages(ClientAgreement $agreement, string $through): float
    {
        $method = new \ReflectionMethod(ClientInvoicingService::class, 'totalBilledOveragesThrough');

        return (float) $method->invoke(app(ClientInvoicingService::class), $agreement, Carbon::parse($through));
    }

    private function hoursIn(ClientAgreement $agreement, string $through): float
    {
        $ledger = (new InvoiceLedgerBuilder)->buildAgreementLedgerThrough(
            $this->company,
            $agreement,
            Carbon::parse($through),
            false,
        );

        return round(array_sum(array_map(static fn ($m): float => $m->hoursWorked, $ledger)), 4);
    }

    private function invoice(ClientAgreement $agreement): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'T-'.uniqid(),
            'status' => 'draft',
            'currency' => 'USD',
            'subtotal_amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
        ]);
    }

    private function project(string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => $name,
        ]);
    }

    private function quarterlyAgreement(?ClientProject $project = null): ClientAgreement
    {
        $agreement = $this->agreement($project);
        $agreement->forceFill([
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => true,
        ])->save();

        return $agreement->refresh();
    }

    private function agreement(?ClientProject $project = null): ClientAgreement
    {
        return ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project?->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2024-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 20000,
            'rollover_months' => 0,
        ]);
    }

    private function entry(ClientProject $project, string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }
}
