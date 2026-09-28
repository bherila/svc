<?php

namespace Tests\Feature\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLedgerBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsDeferredBacklogHistory;
use Tests\TestCase;

/**
 * The October invoice for a client with nine paid months and a deferred
 * backlog, end to end. See {@see BuildsDeferredBacklogHistory} for the shape.
 *
 * Before these fixes the same history produced `SVC-00001`, undated, and a
 * capacity ledger whose debt dipped to 26.58 hours in June because deferred
 * work the paid June-September invoices applied was booked back in the months
 * it was worked. The debt that is left - 9.25 hours from the spring, rolled
 * forward every month since - is what the recorded history says: 98.67 hours
 * drawn through August against 90 hours of retainer and 9.42 billed. It is why
 * September's pool has 0.75 hours, why September's work borrows October's
 * retainer, and why no deferred work fits.
 */
final class DeferredBacklogScenarioTest extends TestCase
{
    use BuildsDeferredBacklogHistory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildDeferredBacklogHistory();
        $this->travelTo(Carbon::parse('2026-09-27 12:00:00'));
    }

    public function test_the_october_draft(): void
    {
        $settledBefore = $this->settledFingerprint();

        $results = app(ClientInvoicingService::class)->generateAllInvoices($this->backlogCompany);

        $this->assertSame(1, $results['summary']['generated_count']);
        $this->assertSame(9, $results['summary']['skipped_count']);
        $this->assertSame($settledBefore, $this->settledFingerprint(), 'Paid history must not move');

        $draft = ClientInvoice::query()->where('status', 'draft')->sole();
        $this->assertSame('ATLA-202610-001', $draft->invoice_number);
        $this->assertSame('2026-10-01', $draft->issue_date?->toDateString());
        $this->assertSame('2026-09-01', $draft->service_period_start?->toDateString());
        $this->assertSame('2026-09-30', $draft->service_period_end?->toDateString());
        $this->assertSame('2026-10-01', $draft->cycle_start?->toDateString());
        $this->assertSame(440625, (int) $draft->total_amount);

        $lines = $draft->lines()->orderBy('sort_order')->get()->map(fn ($line): array => [
            (string) $line->description,
            (int) $line->timeEntries()->sum('minutes'),
            (int) $line->total_amount,
        ])->all();
        $this->assertSame([
            ['Work items applied to retainer (0:45 applied to September 2026 pool)', 45, 0],
            ['Work items applied to retainer (10:00 applied to October 2026 pool)', 600, 0],
            ['Catch-up hours for prior month overage and minimum availability', 45, 65625],
            ['Monthly Retainer (10:00 hours) - Oct 1, 2026 through Oct 31, 2026', 0, 375000],
        ], $lines);

        // Carried, not dropped: every deferred minute is either on a paid
        // invoice exactly once or still waiting.
        $deferred = ClientTimeEntry::query()->where('workspace_id', $this->backlogWorkspace->id)->where('is_deferred', true);
        $this->assertSame(2950, (int) (clone $deferred)->whereDoesntHave('invoiceLines')->sum('minutes'));
        $this->assertSame(2400, (int) (clone $deferred)->whereHas('invoiceLines')->sum('minutes'));
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')
            ->whereIn('client_invoice_line_id', $draft->lines()->pluck('id'))
            ->whereIn('client_time_entry_id', (clone $deferred)->pluck('id'))
            ->count());
    }

    public function test_the_ledger_carries_one_debt_rather_than_restating_old_months(): void
    {
        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->backlogCompany, $this->backlogAgreement, Carbon::parse('2026-09-30'),
        );
        $closing = [];
        foreach ($ledger as $month) {
            $closing[$month->yearMonth] = $this->signed($month);
        }

        $this->assertSame(-9.25, round($closing['2026-05'], 2));
        $this->assertSame(-9.25, round($closing['2026-06'], 2), 'Not the -26.58 of booking June-September absorption back in April-July');
        $this->assertSame(-9.25, round($closing['2026-07'], 2));
        $this->assertSame(-9.25, round($closing['2026-08'], 2));
        $this->assertSame(0.75, round($this->month($ledger, '2026-09')->opening->totalAvailable, 2));
    }

    public function test_the_rehearsal_shows_the_october_draft_and_writes_nothing(): void
    {
        $before = $this->everythingFingerprint();

        $exit = Artisan::call('svc:billing:rehearse-generation', [
            '--workspace' => $this->backlogWorkspace->public_id,
            '--company' => $this->backlogCompany->public_id,
            '--show' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertSame($before, $this->everythingFingerprint(), 'The rehearsal must leave the database exactly as it found it');
        foreach ([
            'New ATLA-202610-001 [cadence_period, draft] issue 2026-10-01 | work 2026-09-01..2026-09-30 | sells 2026-10-01..2026-10-31',
            'total USD 4,406.25',
            'Work items applied to retainer (0:45 applied to September 2026 pool) | 0.75 h',
            'linked 600 min in 1 entry worked 2026-09-15..2026-09-15',
            'Catch-up hours for prior month overage and minimum availability | 1.75 h',
            '= USD 656.25 | linked 45 min',
            'Monthly Retainer (10:00 hours) - Oct 1, 2026 through Oct 31, 2026',
            '2026-08 retainer 10.00 | worked 10.00 | billed overage 0.00 | opening available 0.75 | closing -9.25',
            'Deferred work carried forward: 2950 min in 28 entries, worked 2026-04-24..2026-09-09',
            'No settled invoice was touched',
        ] as $expected) {
            $this->assertStringContainsString($expected, $output);
        }
        $this->assertStringNotContainsString('! ', $output, 'Every recorded overage matches its lines here');
    }

    /**
     * The ledger reads only `hours_billed_at_rate`. A charged invoice whose
     * hourly lines say otherwise moves every later month, so it is flagged.
     */
    public function test_the_rehearsal_flags_a_charged_invoice_whose_recorded_overage_disagrees_with_its_lines(): void
    {
        $this->backlogInvoices['2026-05']->forceFill(['hours_billed_at_rate' => 0])->save();

        Artisan::call('svc:billing:rehearse-generation', [
            '--workspace' => $this->backlogWorkspace->public_id,
            '--company' => $this->backlogCompany->public_id,
            '--show' => true,
        ]);

        $this->assertStringContainsString(
            '! ATLA-202605-001 (work 2026-04-30): records 0.00 h billed at rate, its additional-hours lines charge 9.42 h',
            Artisan::output(),
        );
    }

    public function test_the_rehearsal_names_only_a_company_of_its_own_workspace(): void
    {
        $foreignSlug = 'atlas-elsewhere';
        $home = $this->backlogWorkspace;
        $this->buildDeferredBacklogHistory($foreignSlug);
        $foreign = $this->backlogCompany;

        $this->artisan('svc:billing:rehearse-generation', [
            '--workspace' => $home->public_id,
            '--company' => $foreign->public_id,
            '--show' => true,
        ])->expectsOutputToContain('No client company in that workspace matches that public id.')->assertFailed();
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

    private function signed(MonthSummary $month): float
    {
        return $month->closing->unusedHours + $month->closing->remainingRollover - $month->closing->negativeBalance;
    }

    private function settledFingerprint(): string
    {
        $invoices = DB::table('client_invoices')->where('status', 'paid')->orderBy('id')->get()
            ->map(fn (object $row): array => array_diff_key((array) $row, ['updated_at' => 1, 'lock_version' => 1]))->all();
        $lines = DB::table('client_invoice_lines')->whereIn('client_invoice_id', array_column($invoices, 'id'))->orderBy('id')->get()->all();
        $links = DB::table('client_invoice_line_time_entries')->whereIn('client_invoice_line_id', array_column($lines, 'id'))->orderBy('client_time_entry_id')->get()->all();

        return md5((string) json_encode([$invoices, $lines, $links]));
    }

    private function everythingFingerprint(): string
    {
        $parts = [];
        foreach (['client_invoices', 'client_invoice_lines', 'client_invoice_line_time_entries', 'client_time_entries', 'workspace_invoice_counters'] as $table) {
            $parts[] = md5((string) json_encode(DB::table($table)->get()->all()));
        }

        return implode('|', $parts);
    }
}
