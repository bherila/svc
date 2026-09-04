<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\DeferredAllocationResult;
use App\Services\Billing\Balances\DeferredEntryCandidate;
use App\Services\Billing\Balances\TimeEntryFragment;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Billing\TimeEntrySplitter;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\SubcontractorBillingMode;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The composer is where the ported engine meets this schema's pivot. The
 * predecessor stored the invoice link as a column on the time entry; here it is
 * a many-to-many with a unique index per entry, so linking, releasing and
 * splitting all behave differently even though the composition rules do not.
 */
final class InvoiceLineComposerTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = Workspace::query()->create(['name' => 'Composer', 'slug' => 'composer']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Composer Client', 'slug' => 'composer-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Composer Project',
        ]);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'title' => 'Retainer', 'status' => 'active', 'currency' => 'USD',
            'hourly_rate_amount' => 30000, 'starts_on' => '2026-01-01',
        ]);
        $this->user = User::factory()->create();
    }

    public function test_a_completed_milestone_bills_once_and_not_again(): void
    {
        $task = $this->milestone(18750);
        $invoice = $this->invoice();
        $sort = 0;

        app(InvoiceLineComposer::class)->addBillableMilestoneTasks(
            $this->company, $invoice, Carbon::parse('2026-03-31'), $sort,
        );

        $line = $invoice->lines()->where('type', 'milestone')->firstOrFail();
        $this->assertSame(18750, (int) $line->total_amount);
        $this->assertSame($line->id, $task->refresh()->client_invoice_line_id);

        // Regenerating must not find it unbilled and charge again.
        app(InvoiceLineComposer::class)->addBillableMilestoneTasks(
            $this->company, $invoice, Carbon::parse('2026-03-31'), $sort,
        );

        $this->assertSame(1, $invoice->lines()->where('type', 'milestone')->count());
    }

    public function test_regeneration_releases_time_and_milestones_it_had_claimed(): void
    {
        $task = $this->milestone(18750);
        $entry = $this->entry(60);
        $invoice = $this->invoice();
        $sort = 0;

        $composer = app(InvoiceLineComposer::class);
        $composer->addBillableMilestoneTasks($this->company, $invoice, Carbon::parse('2026-03-31'), $sort);
        $composer->addDeferredRetainerLine(
            $invoice,
            $this->agreement,
            new DeferredAllocationResult([DeferredEntryCandidate::fromEntry($entry)], [], 1.0),
            Carbon::parse('2026-03-31'),
            $sort,
        );

        $this->assertTrue($entry->refresh()->invoiceLines()->exists());
        $this->assertNotNull($task->refresh()->client_invoice_line_id);

        $composer->resetSystemGeneratedLines($invoice->refresh());

        // Both must come free, or the next pass silently skips them forever.
        $this->assertFalse($entry->refresh()->invoiceLines()->exists());
        $this->assertNull($task->refresh()->client_invoice_line_id);
        $this->assertSame(0, $invoice->lines()->count());
    }

    public function test_regeneration_refuses_a_generated_line_owned_by_another_workspace(): void
    {
        $invoice = $this->invoice();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other composer', 'slug' => 'other-composer']);
        // Unstorable since #113; the composer's own refusal is the subject here.
        $foreignLine = $this->writingLegacyCrossTenantRows(fn () => ClientInvoiceLine::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => InvoiceLineType::AdditionalHours->value,
            'description' => 'Foreign generated line',
            'quantity' => '1',
            'unit_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'sort_order' => 1,
        ]));

        try {
            app(InvoiceLineComposer::class)->resetSystemGeneratedLines($invoice);
            $this->fail('A generated line from another workspace must stop the reset.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('line owned by another workspace', $exception->getMessage());
        }

        $this->assertNotNull($foreignLine->fresh());
    }

    public function test_invoice_totals_refuse_a_line_owned_by_another_workspace(): void
    {
        $invoice = $this->invoice();
        $otherWorkspace = Workspace::query()->create(['name' => 'Other totals', 'slug' => 'other-totals']);
        // Unstorable since #113; the totals guard is the subject here.
        $foreignLine = $this->writingLegacyCrossTenantRows(fn () => ClientInvoiceLine::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => InvoiceLineType::Adjustment->value,
            'description' => 'Foreign total',
            'quantity' => '1',
            'unit_amount' => 100,
            'tax_amount' => 0,
            'total_amount' => 100,
            'sort_order' => 1,
        ]));

        try {
            $invoice->recalculateTotals();
            $this->fail('A foreign line must not contribute to local invoice totals.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('line owned by another workspace', $exception->getMessage());
        }

        $this->assertNotNull($foreignLine->fresh());
    }

    public function test_regeneration_refuses_a_milestone_claim_owned_by_another_workspace(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, InvoiceLineType::Milestone->value, 100, 100);
        $otherWorkspace = Workspace::query()->create(['name' => 'Other milestone', 'slug' => 'other-milestone']);
        $otherCompany = ClientCompany::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Other Milestone Client',
            'slug' => 'other-milestone-client',
        ]);
        $otherProject = ClientProject::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_company_id' => $otherCompany->id,
            'name' => 'Other Milestone Project',
        ]);
        $foreignTask = ClientTask::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'client_project_id' => $otherProject->id,
            'client_invoice_line_id' => $line->id,
            'title' => 'Foreign claimed milestone',
            'status' => 'completed',
            'completed_at' => '2026-03-20',
            'milestone_price_amount' => 100,
        ]);

        try {
            app(InvoiceLineComposer::class)->resetSystemGeneratedLines($invoice);
            $this->fail('A milestone claim from another workspace must stop the reset.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('milestone allocation owned by another workspace', $exception->getMessage());
        }

        $this->assertSame($line->id, $foreignTask->fresh()?->client_invoice_line_id);
        $this->assertNotNull($line->fresh());
    }

    public function test_a_manual_adjustment_survives_regeneration(): void
    {
        $invoice = $this->invoice();
        ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id,
            'type' => InvoiceLineType::Adjustment->value, 'description' => 'Goodwill discount',
            'quantity' => '1', 'unit_amount' => -2500, 'tax_amount' => 0, 'total_amount' => -2500, 'sort_order' => 9,
        ]);

        app(InvoiceLineComposer::class)->resetSystemGeneratedLines($invoice);

        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame('Goodwill discount', $invoice->lines()->first()->description);
    }

    public function test_a_retainer_draw_down_bills_the_hours_at_no_charge(): void
    {
        $entry = $this->entry(90);
        $invoice = $this->invoice();
        $sort = 0;

        app(InvoiceLineComposer::class)->addDeferredRetainerLine(
            $invoice,
            $this->agreement,
            new DeferredAllocationResult([DeferredEntryCandidate::fromEntry($entry)], [], 1.5),
            Carbon::parse('2026-03-31'),
            $sort,
        );

        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('prior_month_retainer', $line->type);
        // The capacity was already paid for, so the line carries hours but no money.
        $this->assertSame(0, (int) $line->total_amount);
        $this->assertSame('1.5000', (string) $line->hours);
    }

    public function test_termination_force_bills_outstanding_deferred_time(): void
    {
        $first = $this->entry(60);
        $second = $this->entry(30);
        $invoice = $this->invoice();
        $sort = 0;

        app(InvoiceLineComposer::class)->addDeferredTerminationLine(
            $invoice, $this->agreement, collect([$first, $second]), $sort,
        );

        $line = $invoice->lines()->firstOrFail();
        $this->assertSame('additional_hours', $line->type);
        // 1.5h at 300.00/hr.
        $this->assertSame(45000, (int) $line->total_amount);
        $this->assertTrue($first->refresh()->invoiceLines()->exists());
        $this->assertTrue($second->refresh()->invoiceLines()->exists());
    }

    public function test_termination_preserves_each_subcontractor_billing_mode(): void
    {
        $consultant = $this->entry(60);
        $retainer = $this->entry(30);
        $retainer->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Retainer,
        ]);
        $flat = $this->entry(120);
        $flat->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
            'subcontractor_cost_amount' => 12500,
            'subcontractor_cost_currency' => 'USD',
        ]);
        $direct = $this->entry(90);
        $direct->update([
            'subcontractor_billing_mode' => SubcontractorBillingMode::Direct,
        ]);
        $invoice = $this->invoice();
        $sort = 0;

        app(InvoiceLineComposer::class)->addDeferredTerminationLine(
            $invoice,
            $this->agreement,
            collect([$consultant, $retainer, $flat, $direct]),
            $sort,
        );

        $ordinaryLine = $invoice->lines()->where('type', InvoiceLineType::AdditionalHours->value)->firstOrFail();
        $flatLine = $invoice->lines()->where('type', InvoiceLineType::Subcontractor->value)->firstOrFail();
        $this->assertSame(45000, (int) $ordinaryLine->total_amount);
        $this->assertSame(30000, (int) $ordinaryLine->unit_amount);
        $this->assertSame(25000, (int) $flatLine->total_amount);
        $this->assertSame(12500, (int) $flatLine->unit_amount);
        $this->assertSame('2026-03-31', $flatLine->line_date?->format('Y-m-d'));
        $this->assertTrue($consultant->refresh()->invoiceLines()->exists());
        $this->assertTrue($retainer->refresh()->invoiceLines()->exists());
        $this->assertTrue($flat->refresh()->invoiceLines()->exists());
        $this->assertFalse($direct->refresh()->invoiceLines()->exists());
    }

    /**
     * An invoice with no period end dates its termination work as nothing, and
     * its subcontractor work as today.
     *
     * `addDeferredTerminationLine()` reads `service_period_end` twice and reads
     * it two different ways. The ordinary line takes it as a value, so a null
     * lands as a null `line_date` and the charge falls out of every dated
     * window - the service-period widening, the replay line key, the audit
     * queries. The flat-hourly line takes it through `Carbon::parse()`, and
     * `parse(null)` is *now*: the subcontractor charge is dated to whenever the
     * termination happened to be run, which can be months after the work and
     * outside the agreement's own term.
     *
     * Two readings, one column, opposite failure modes, and neither of them is
     * the period the client is being billed for. The control below is the same
     * two entries on an invoice that states its end, where both lines take that
     * date.
     */
    public function test_a_termination_line_on_an_undated_invoice_dates_nothing_and_subcontractors_today(): void
    {
        Carbon::setTestNow('2026-09-04 11:30:00');

        $composed = function (?string $periodEnd): ClientInvoice {
            $ordinary = $this->entry(60);
            $flat = $this->entry(120);
            $flat->update([
                'subcontractor_billing_mode' => SubcontractorBillingMode::FlatHourly,
                'subcontractor_cost_amount' => 12500,
                'subcontractor_cost_currency' => 'USD',
            ]);
            $invoice = $this->invoice();
            $invoice->forceFill(['service_period_end' => $periodEnd])->save();
            $sort = 0;

            app(InvoiceLineComposer::class)->addDeferredTerminationLine(
                $invoice->refresh(),
                $this->agreement,
                collect([$ordinary, $flat]),
                $sort,
            );

            return $invoice->refresh();
        };

        $dated = $composed('2026-03-31');
        $this->assertSame(
            '2026-03-31',
            $dated->lines()->where('type', InvoiceLineType::AdditionalHours->value)->firstOrFail()->line_date?->format('Y-m-d'),
        );
        $this->assertSame(
            '2026-03-31',
            $dated->lines()->where('type', InvoiceLineType::Subcontractor->value)->firstOrFail()->line_date?->format('Y-m-d'),
        );

        $undated = $composed(null);
        $this->assertNull(
            $undated->lines()->where('type', InvoiceLineType::AdditionalHours->value)->firstOrFail()->line_date,
            'The termination charge carries no date at all',
        );
        $this->assertSame(
            '2026-09-04',
            $undated->lines()->where('type', InvoiceLineType::Subcontractor->value)->firstOrFail()->line_date?->format('Y-m-d'),
            'The subcontractor charge is dated to the run, not to the period',
        );
    }

    public function test_an_entry_spanning_two_lines_is_split_into_rows_that_can_recombine(): void
    {
        $entry = $this->entry(120);
        $invoice = $this->invoice();
        $retainer = $this->line($invoice, 'prior_month_retainer', 0, 0);
        $overage = $this->line($invoice, 'additional_hours', 30000, 30000, 1);

        app(InvoiceLineComposer::class)->linkAllFragmentsToLines($this->company, [
            $retainer->id => [new TimeEntryFragment($entry->id, 60, '2026-03-14', 'Work', $this->user->id)],
            $overage->id => [new TimeEntryFragment($entry->id, 60, '2026-03-14', 'Work', $this->user->id)],
        ], app(TimeEntrySplitter::class));

        // One entry became two rows, one per line, because the pivot allows a
        // single line per entry.
        $rows = ClientTimeEntry::query()->where('client_company_id', $this->company->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(120, (int) $rows->sum('minutes'));
        $this->assertSame(1, $retainer->timeEntries()->count());
        $this->assertSame(1, $overage->timeEntries()->count());

        // And the fragment knows where it came from, so it can be put back.
        $fragment = $rows->firstWhere('split_from_time_entry_id', '!=', null);
        $this->assertNotNull($fragment);
        $this->assertSame($entry->id, $fragment->split_from_time_entry_id);
    }

    /**
     * A fragment naming another client's entry is not billed on this invoice.
     *
     * `split_from_time_entry_id` is unconstrained lineage - a row migrated from
     * before #113's composite keys can name an entry belonging to someone else -
     * and this resolved that id with `ClientTimeEntry::find()`, which reaches
     * every workspace. Whatever row carried the id was attached to this invoice.
     *
     * Two entries, because the two halves fail differently and only one of them
     * was ever going to be noticed:
     *
     * - The **foreign tenant's** entry is caught by the pivot's composite
     *   foreign key. Unscoped, the insert raises rather than mis-attributing, so
     *   #113 already contains that half - loudly, mid-invoice-run.
     * - The **sibling client's** entry shares this workspace, so no foreign key
     *   objects. Unscoped it is silently attached, and one client is invoiced
     *   for work done for another client of the same firm. Verified by running
     *   this test against the unscoped lookup with the foreign entry removed:
     *   the line comes back holding one row instead of none.
     *
     * That second case is why the lookup names the company as well as the
     * workspace. A workspace check alone reads as sufficient and is not.
     *
     * It raises rather than passing the fragment over, and the difference is
     * money rather than tidiness: the line charging for these minutes was
     * created by the caller before this ran, and `recalculateTotals()` sums each
     * line's own `total_amount` without consulting the pivot. Skipping the link
     * therefore billed the client for work the invoice could not show.
     */
    public function test_a_fragment_naming_another_clients_entry_is_refused(): void
    {
        $invoice = $this->invoice();
        $line = $this->line($invoice, 'additional_hours', 30000, 30000);

        $sibling = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Sibling Client', 'slug' => 'sibling-client',
        ]);
        $siblingEntry = $this->entryFor($sibling, $this->workspace, "Another client's work");

        $foreignWorkspace = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign-composer']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreignWorkspace->id, 'name' => 'Foreign Client', 'slug' => 'foreign-composer-client',
        ]);
        $foreignEntry = $this->entryFor($foreignCompany, $foreignWorkspace, "Another tenant's work");

        $this->expectException(RuntimeException::class);

        try {
            app(InvoiceLineComposer::class)->linkAllFragmentsToLines($this->company, [
                $line->id => [
                    new TimeEntryFragment($siblingEntry->id, 60, '2026-03-14', 'Work', $this->user->id),
                    new TimeEntryFragment($foreignEntry->id, 60, '2026-03-14', 'Work', $this->user->id),
                ],
            ], app(TimeEntrySplitter::class));
        } finally {
            // Asserted in `finally` so they are checked on the throwing path
            // rather than after it: neither entry is attached, and neither has
            // been split, so the refusal leaves no partial lineage behind for
            // the caller's transaction to roll back around.
            $this->assertSame(0, $line->timeEntries()->count(), "Neither entry is this invoice's to bill");
            $this->assertNull($siblingEntry->fresh()?->split_from_time_entry_id);
            $this->assertNull($foreignEntry->fresh()?->split_from_time_entry_id);
        }
    }

    /**
     * The refusal is what keeps the charge and the work in step.
     *
     * The narrow version of this test asserts only that nothing is attached,
     * which the old skipping behaviour also satisfied - it passed while the
     * client was being billed. This one runs the real caller inside its own
     * transaction and asserts the invoice carries no money afterwards, which
     * only refusing can achieve.
     */
    public function test_an_unattributable_fragment_leaves_no_charge_behind(): void
    {
        $invoice = $this->invoice();

        $sibling = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Sibling Two', 'slug' => 'sibling-two',
        ]);
        $siblingEntry = $this->entryFor($sibling, $this->workspace, "Another client's work");

        try {
            // The line is created *inside* the transaction because that is where
            // production creates it - `generateInterimOverageInvoice` and both
            // cadence paths build the line and link its fragments in one
            // `DB::transaction`. Creating it outside would be testing a
            // rollback that production never performs.
            DB::transaction(function () use ($invoice, $siblingEntry): void {
                $line = $this->line($invoice, 'additional_hours', 30000, 30000);

                app(InvoiceLineComposer::class)->linkAllFragmentsToLines($this->company, [
                    $line->id => [new TimeEntryFragment($siblingEntry->id, 60, '2026-03-14', 'Work', $this->user->id)],
                ], app(TimeEntrySplitter::class));

                $invoice->recalculateTotals();
            });
            $this->fail('An unattributable fragment should not compose an invoice.');
        } catch (RuntimeException) {
            // Expected: the transaction unwound with it.
        }

        // The line is gone with the transaction, so there is no `total_amount`
        // left to sum. Under the previous skipping behaviour the line survived
        // at its full 30000 and the client owed it.
        $this->assertSame(0, $invoice->lines()->count());
        $this->assertSame(0, (int) $invoice->fresh()?->total_amount);
    }

    /** An approved, billable entry belonging to someone other than the subject. */
    private function entryFor(ClientCompany $company, Workspace $workspace, string $description): ClientTimeEntry
    {
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $description.' project',
        ]);

        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-03-14',
            'minutes' => 60,
            'description' => $description,
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    public function test_an_entry_covered_by_one_line_is_not_split(): void
    {
        $entry = $this->entry(60);
        $invoice = $this->invoice();
        $line = $this->line($invoice, 'additional_hours', 30000, 30000);

        app(InvoiceLineComposer::class)->linkAllFragmentsToLines($this->company, [
            $line->id => [new TimeEntryFragment($entry->id, 60, '2026-03-14', 'Work', $this->user->id)],
        ], app(TimeEntrySplitter::class));

        $this->assertSame(1, ClientTimeEntry::query()->where('client_company_id', $this->company->id)->count());
        $this->assertSame(1, $line->timeEntries()->count());
    }

    private function invoice(): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_agreement_id' => $this->agreement->id,
            'invoice_number' => 'SVC-COMP-'.uniqid(),
            'currency' => 'USD',
            'status' => 'draft',
            'service_period_end' => '2026-03-31',
        ]);
    }

    private function line(ClientInvoice $invoice, string $type, int $unit, int $total, int $sort = 0): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => $type,
            'description' => ucfirst(str_replace('_', ' ', $type)),
            'quantity' => '1',
            'unit_amount' => $unit,
            'tax_amount' => 0,
            'total_amount' => $total,
            'sort_order' => $sort,
        ]);
    }

    private function entry(int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => '2026-03-14',
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => true,
            'status' => 'approved',
            'currency' => 'USD',
        ]);
    }

    private function milestone(int $priceAmount): ClientTask
    {
        return ClientTask::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'title' => 'Deliverable',
            'status' => 'completed',
            'completed_at' => '2026-03-20',
            'milestone_price_amount' => $priceAmount,
        ]);
    }
}
