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
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\CorrectionFacts;
use App\Support\Billing\DeliberateCorrections;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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
        $entry->forceFill(['subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD'])->save();

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
     * A company id is globally unique, so an agreement row carrying it under
     * another tenant's workspace is reachable through the foreign key alone.
     * The explicit-agreement path validates both keys; the automatic selectors
     * never passed through it.
     */
    public function test_automatic_agreement_selection_ignores_another_tenants_row(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $this->company->id, // malformed: our company, their tenant
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'EUR', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 999900, 'hourly_rate_amount' => 99900,
            'rollover_months' => 0,
        ]);

        $selector = app(AgreementSelector::class);

        $this->expectException(RuntimeException::class);
        $selector->agreementForInvoiceGeneration($this->company->fresh());
    }

    public function test_a_date_based_selector_ignores_another_tenants_row(): void
    {
        $otherWorkspace = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        ClientAgreement::query()->create([
            'workspace_id' => $otherWorkspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Theirs', 'status' => 'active', 'currency' => 'EUR', 'starts_on' => '2024-01-01',
            'retainer_minutes' => 600, 'retainer_amount' => 999900, 'hourly_rate_amount' => 99900,
            'rollover_months' => 0,
        ]);

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

        $this->assertSame([], DeliberateCorrections::explaining(['retainer', 'subtotal'], $facts));
        $this->assertNotSame([], DeliberateCorrections::explaining(['additional_hours', 'subtotal'], $facts));
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
                $this->facts(rolloverMonths: 3, fullyUsedMonth: false),
            ),
            'rollover_months > 0 alone is not the trigger',
        );

        $explained = DeliberateCorrections::explaining(
            ['additional_hours'],
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
            $this->facts(cycleOpensMidMonth: true, recurringAnchoredBefore: false),
        ));

        $this->assertNotSame([], DeliberateCorrections::explaining(
            ['recurring_item'],
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
