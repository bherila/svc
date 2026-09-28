<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * Deferred work is booked against the pool that absorbed it.
 *
 * The capacity ledger booked an applied deferred entry in the month it was
 * worked, while the invoice that applied it said a later month's pool took it.
 * Where the old month's unused capacity had already expired, the restatement
 * spent that expired capacity instead - and the pool the invoice really used
 * was handed out again to the next month's work.
 *
 * Here: 10 retainer hours, one month of rollover. January leaves 6 hours
 * unused, which expire at the end of February. A 6-hour deferred entry worked
 * in January is applied by the April invoice against March's pool. March's
 * pool therefore has 4 hours left for April, not 10.
 */
final class DeferredCapacityPlacementTest extends TestCase
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
        $this->workspace = Workspace::query()->create(['name' => 'Placement', 'slug' => 'placement']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Cobalt Survey', 'slug' => 'cobalt-survey',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Survey',
        ]);
        $this->user = User::factory()->create();
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'retainer_minutes' => 600,
            'retainer_amount' => 150000,
            'catch_up_threshold_minutes' => 60,
            'hourly_rate_amount' => 15000,
            'billing_cadence' => 'monthly',
            'rollover_months' => 1,
        ]);
    }

    public function test_the_ledger_books_applied_deferred_work_in_the_month_that_absorbed_it(): void
    {
        $this->history();

        $ledger = $this->ledgerThrough('2026-04-30');

        $this->assertSame(4.0, $this->month($ledger, '2026-01')->hoursWorked, 'January keeps only its own work');
        $this->assertSame(6.0, $this->month($ledger, '2026-03')->hoursWorked, 'March absorbed the deferred hours');
        // 10 of April's own plus 4 left in March - not 10 + 10.
        $this->assertSame(14.0, $this->month($ledger, '2026-04')->opening->totalAvailable);
    }

    public function test_the_next_invoice_draws_only_on_what_the_pool_really_has_left(): void
    {
        $this->history();
        $this->entry('2026-04-14', 900);

        $invoice = app(ClientInvoicingService::class)->generateInvoice(
            $this->company, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), $this->agreement,
        );

        $descriptions = $invoice->lines->pluck('description')->all();
        $this->assertContains('Work items applied to retainer (14:00 applied to April 2026 pool)', $descriptions);
        $this->assertContains('Work items applied to retainer (1:00 applied to May 2026 pool)', $descriptions);
    }

    /**
     * The absorbing line decides the month only when it is this workspace's.
     */
    public function test_another_workspaces_line_does_not_place_this_workspaces_hours(): void
    {
        $this->history();
        $other = Workspace::query()->create(['name' => 'Other', 'slug' => 'other']);
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Other Co', 'slug' => 'other-co']);
        $foreignInvoice = $this->invoice($other, $otherCompany, null, 'OTHE-202609-001', '2026-08-01', '2026-08-31');
        $foreignLine = $this->line($foreignInvoice, 'Deferred work items applied to retainer (2:00)', '2026-08-31');
        $stray = $this->entry('2026-02-10', 120, deferred: true);
        $this->writingLegacyCrossTenantRows(fn () => $foreignLine->timeEntries()->attach($stray->id, ['workspace_id' => $other->id]));

        $ledger = $this->ledgerThrough('2026-09-30');

        // Never applied in this workspace, so it has drawn on nothing here -
        // in February or in August.
        $this->assertSame(10.0, $this->month($ledger, '2026-02')->hoursWorked);
        $this->assertSame(0.0, $this->month($ledger, '2026-08')->hoursWorked);
    }

    private function history(): void
    {
        $feb = $this->invoice($this->workspace, $this->company, $this->agreement, 'COBA-202602-001', '2026-01-01', '2026-01-31');
        $this->line($feb, 'Work items applied to retainer', '2026-01-31', [$this->entry('2026-01-12', 240)]);
        $mar = $this->invoice($this->workspace, $this->company, $this->agreement, 'COBA-202603-001', '2026-02-01', '2026-02-28');
        $this->line($mar, 'Work items applied to retainer', '2026-02-28', [$this->entry('2026-02-12', 600)]);
        $apr = $this->invoice($this->workspace, $this->company, $this->agreement, 'COBA-202604-001', '2026-03-01', '2026-03-31');
        $this->line($apr, 'Deferred work items applied to retainer (6:00)', '2026-03-31', [$this->entry('2026-01-20', 360, deferred: true)]);
    }

    /** @return array<int, MonthSummary> */
    private function ledgerThrough(string $through): array
    {
        return app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->company, $this->agreement, Carbon::parse($through),
        );
    }

    /** @param array<int, MonthSummary> $ledger */
    private function month(array $ledger, string $yearMonth): MonthSummary
    {
        foreach ($ledger as $summary) {
            if ($summary->yearMonth === $yearMonth) {
                return $summary;
            }
        }
        $this->fail("No ledger row for {$yearMonth}");
    }

    private function invoice(Workspace $workspace, ClientCompany $company, ?ClientAgreement $agreement, string $number, string $start, string $end): ClientInvoice
    {
        $cycle = Carbon::parse($end)->addDay();

        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement?->id,
            'invoice_number' => $number,
            'status' => 'issued',
            'invoice_kind' => InvoiceKind::CadencePeriod->value,
            'issue_date' => $cycle->toDateString(),
            'service_period_start' => $start,
            'service_period_end' => $end,
            'cycle_start' => $cycle->toDateString(),
            'cycle_end' => $cycle->copy()->endOfMonth()->toDateString(),
            'currency' => 'USD',
            'subtotal_amount' => 150000,
            'tax_amount' => 0,
            'total_amount' => 150000,
            'balance_amount' => 150000,
            'hours_billed_at_rate' => 0,
        ]);
    }

    /** @param list<ClientTimeEntry> $entries */
    private function line(ClientInvoice $invoice, string $description, string $lineDate, array $entries = []): ClientInvoiceLine
    {
        $line = ClientInvoiceLine::query()->create([
            'workspace_id' => $invoice->workspace_id,
            'client_invoice_id' => $invoice->id,
            'client_agreement_id' => $invoice->client_agreement_id,
            'type' => InvoiceLineType::PriorMonthRetainer->value,
            'description' => $description,
            'quantity' => '0',
            'unit_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'hours' => array_sum(array_map(fn (ClientTimeEntry $entry): int => (int) $entry->minutes, $entries)) / 60,
            'line_date' => $lineDate,
            'sort_order' => 1,
        ]);
        foreach ($entries as $entry) {
            $line->timeEntries()->attach($entry->id, ['workspace_id' => $invoice->workspace_id]);
        }

        return $line;
    }

    private function entry(string $workedOn, int $minutes, bool $deferred = false): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'client_project_id' => $this->project->id,
            'user_id' => $this->user->id,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'description' => 'Work',
            'is_billable' => true,
            'is_deferred' => $deferred,
            'status' => 'approved',
            'billing_rate_amount' => 15000,
            'currency' => 'USD',
        ]);
    }
}
