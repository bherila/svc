<?php

namespace Tests\Feature\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\InvoiceLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsDeferredBacklogHistory;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The October invoice for a client with nine paid months and a deferred
 * backlog, end to end. See {@see BuildsDeferredBacklogHistory} for the shape.
 *
 * Before these fixes the same history produced `SVC-00001`, undated, with
 * September's work spilling into October's pool and 1.75 catch-up hours
 * billed. The paid June-September invoices applied 10 deferred hours each
 * when May had only 0.75 free; the ledger now draws deferred work only on
 * free capacity and carries the 9.25-hour excess forward again instead of
 * treating it as debt. September keeps its whole pool and nothing is billed
 * at rate.
 */
final class DeferredBacklogScenarioTest extends TestCase
{
    use BuildsDeferredBacklogHistory;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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
        $this->assertSame(375000, (int) $draft->total_amount);
        $this->assertSame(0.0, (float) $draft->hours_billed_at_rate, 'No catch-up: the over-applied deferred work is carried, not owed');

        $lines = $draft->lines()->orderBy('sort_order')->get()->map(fn ($line): array => [
            (string) $line->description,
            (int) $line->timeEntries()->sum('minutes'),
            (int) $line->total_amount,
        ])->all();
        $this->assertSame([
            ['Work items applied to retainer (10:00 applied to September 2026 pool)', 600, 0],
            ['Work items applied to retainer (1:30 applied to October 2026 pool)', 90, 0],
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

    public function test_the_ledger_draws_deferred_work_only_on_free_capacity_and_carries_the_rest(): void
    {
        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->backlogCompany, $this->backlogAgreement, Carbon::parse('2026-09-30'),
        );
        $closing = [];
        foreach ($ledger as $month) {
            $closing[$month->yearMonth] = $this->signed($month);
        }

        // April's ordinary debt (9 hours) is repaid by May's retainer. The
        // deferred work the paid invoices applied draws only on what was free:
        // 0.75 hours in May, the whole pool June-August. The 9.25 hours May
        // could not hold are carried again, not owed and not forgiven.
        $this->assertSame(-9.0, round($closing['2026-04'], 2));
        foreach (['2026-05', '2026-06', '2026-07', '2026-08'] as $month) {
            $this->assertSame(0.0, round($closing[$month], 2), $month);
        }
        $this->assertSame(9.25, round($this->month($ledger, '2026-05')->recarriedDeferredHours, 2));
        $this->assertSame(9.25, round($this->month($ledger, '2026-09')->recarriedDeferredHours, 2));
        $this->assertSame(10.0, round($this->month($ledger, '2026-09')->opening->totalAvailable, 2));
    }

    /**
     * The re-carried 9.25 hours are applied by later months as they leave room
     * - never more than a month has free, before any unapplied entry, and
     * without linking any entry a second time.
     */
    public function test_re_carried_deferred_work_is_applied_later_only_from_free_capacity(): void
    {
        $service = app(ClientInvoicingService::class);
        $issue = function (string $on) use ($service): ClientInvoice {
            $service->generateAllInvoices($this->backlogCompany);
            $draft = ClientInvoice::query()->where('workspace_id', $this->backlogWorkspace->id)->where('status', 'draft')->orderBy('id')->firstOrFail();
            $this->travelTo(Carbon::parse($on.' 09:00:00'));

            return app(InvoiceLifecycleService::class)->issue($draft, $this->backlogWorkspace);
        };
        $carried = fn (ClientInvoice $invoice): float => (float) $invoice->lines()
            ->where('type', 'carried_deferred_applied')->sum('hours');

        $october = $issue('2026-10-01');
        $this->assertSame(0.0, $carried($october), 'September left nothing free');

        // October: 2 hours of ordinary work against October's 8.5 left.
        $this->backlogEntry('2026-10-14', 120);
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $november = $issue('2026-11-01');
        $this->assertSame(6.5, $carried($november), 'Only what October left free');
        $this->assertSame(0.0, (float) $november->hours_billed_at_rate);
        $this->assertSame(0, $november->lines()->where('type', 'additional_hours')->count());

        // A quiet November: the rest of the carried hours, then entries.
        $this->travelTo(Carbon::parse('2026-11-28 12:00:00'));
        $december = $issue('2026-12-01');
        $this->assertSame(2.75, round($carried($december), 2), 'The remainder: 9.25 carried less 6.5 applied');
        $deferredLine = $december->lines()->where('description', 'like', 'Deferred work items applied to retainer (%')->first();
        $this->assertNotNull($deferredLine);
        $this->assertLessThanOrEqual(10.0 - 2.75, (float) $deferredLine->hours);

        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->backlogCompany, $this->backlogAgreement, Carbon::parse('2026-11-30'),
        );
        $this->assertSame(0.0, round($this->month($ledger, '2026-11')->recarriedDeferredHours, 2));
        $this->assertGreaterThanOrEqual(0.0, $this->signed($this->month($ledger, '2026-11')), 'No month is pushed into debt');
        $this->assertSame(0, DB::table('client_invoice_line_time_entries')
            ->select('client_time_entry_id')->groupBy('client_time_entry_id')->havingRaw('count(*) > 1')->get()->count());
    }

    /**
     * The wording settles nothing. A line that merely reads like a settlement
     * - even on this agreement, even issued - leaves the carried hours owed.
     */
    public function test_a_line_that_only_reads_like_a_settlement_settles_nothing(): void
    {
        $september = $this->backlogInvoices['2026-09'];
        foreach (['prior_month_retainer' => 'Carried deferred work applied to retainer (9:15)', 'additional_hours' => 'Carried deferred work billed on agreement termination (9:15)'] as $type => $description) {
            $september->lines()->create([
                'workspace_id' => $september->workspace_id, 'client_agreement_id' => $september->client_agreement_id,
                'type' => $type, 'description' => $description, 'quantity' => '0', 'unit_amount' => 0,
                'tax_amount' => 0, 'total_amount' => 0, 'hours' => 9.25, 'line_date' => '2026-08-31', 'sort_order' => 20,
            ]);
        }

        $ledger = app(InvoiceLedgerBuilder::class)->buildAgreementLedgerThrough(
            $this->backlogCompany, $this->backlogAgreement, Carbon::parse('2026-09-30'),
        );

        $this->assertSame(9.25, round($this->month($ledger, '2026-09')->recarriedDeferredHours, 2));
    }

    /** Ending the agreement bills the re-carried hours at rate; they never lapse. */
    public function test_termination_bills_re_carried_deferred_work_at_the_hourly_rate(): void
    {
        $this->backlogAgreement->forceFill(['ends_on' => '2026-09-30'])->save();

        app(ClientInvoicingService::class)->generateAllInvoices($this->backlogCompany);

        $line = ClientInvoice::query()->where('status', 'draft')->sole()->lines()
            ->where('type', 'carried_deferred_billed')->sole();
        $this->assertSame('Carried deferred work billed on agreement termination (9:15)', $line->description);
        $this->assertSame(9.25, (float) $line->hours);
        $this->assertSame(346875, (int) $line->total_amount);
        $this->assertSame(0, $line->timeEntries()->count());
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
            'total USD 3,750.00',
            'Work items applied to retainer (10:00 applied to September 2026 pool) | 10.00 h',
            'linked 600 min in 1 entry worked 2026-09-15..2026-09-15',
            'Work items applied to retainer (1:30 applied to October 2026 pool) | 1.50 h',
            'Monthly Retainer (10:00 hours) - Oct 1, 2026 through Oct 31, 2026',
            '2026-08 retainer 10.00 | worked 10.00 | billed overage 0.00 | opening available 10.00 | closing 0.00',
            'Deferred work carried forward: 3505 min (2950 min in 28 entries, worked 2026-04-24..2026-09-09; 555 min re-carried, applied earlier beyond free capacity)',
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

    /**
     * Deferred work force-billed on termination is charged at rate without
     * drawing on the pool, so it is deliberately not in the recorded figure -
     * and a correct invoice must not be flagged for it.
     */
    public function test_the_rehearsal_does_not_flag_deferred_work_billed_at_rate_on_termination(): void
    {
        $september = $this->backlogInvoices['2026-09'];
        $line = $september->lines()->create([
            'workspace_id' => $september->workspace_id,
            'client_agreement_id' => $september->client_agreement_id,
            'type' => 'additional_hours',
            'description' => 'Deferred work items billed on agreement termination (2:00 @ $375.00/hr)',
            'quantity' => '2', 'unit_amount' => 37500, 'tax_amount' => 0, 'total_amount' => 75000,
            'hours' => 2, 'line_date' => '2026-08-31', 'sort_order' => 9,
        ]);
        $deferred = ClientTimeEntry::query()->where('workspace_id', $this->backlogWorkspace->id)
            ->where('is_deferred', true)->whereDoesntHave('invoiceLines')->orderBy('id')->firstOrFail();
        $line->timeEntries()->attach($deferred->id, ['workspace_id' => $september->workspace_id]);

        Artisan::call('svc:billing:rehearse-generation', [
            '--workspace' => $this->backlogWorkspace->public_id,
            '--company' => $this->backlogCompany->public_id,
            '--show' => true,
        ]);

        $this->assertStringNotContainsString('! ATLA-202609-001', Artisan::output());
    }

    /**
     * `--show` reads a draft's lines and their time in a fixed number of
     * queries, however many lines it has - not one query per line.
     */
    public function test_the_rehearsal_reads_a_drafts_lines_in_a_bounded_number_of_queries(): void
    {
        app(ClientInvoicingService::class)->generateAllInvoices($this->backlogCompany);
        $draft = ClientInvoice::query()->where('status', 'draft')->sole();

        $this->addAdjustments($draft, 2);
        $few = $this->queriesDuringShow();
        $this->addAdjustments($draft, 20);
        $many = $this->queriesDuringShow();

        $this->assertSame($few, $many, 'Twenty more lines must not cost twenty more queries');
    }

    /**
     * A legacy pivot row pointing one of this workspace's deferred entries at
     * another workspace's invoice line allocates nothing here, so the entry is
     * still carried forward - and still reported as such.
     */
    public function test_a_foreign_workspaces_line_does_not_hide_carried_deferred_work(): void
    {
        $home = $this->backlogWorkspace;
        $homeCompany = $this->backlogCompany;
        $stray = ClientTimeEntry::query()->where('workspace_id', $home->id)
            ->where('is_deferred', true)->whereDoesntHave('invoiceLines')->orderBy('id')->firstOrFail();

        $this->buildDeferredBacklogHistory('atlas-foreign');
        $foreignLine = $this->backlogInvoices['2026-09']->lines()->firstOrFail();
        $this->writingLegacyCrossTenantRows(fn () => $foreignLine->timeEntries()->attach($stray->id, ['workspace_id' => $foreignLine->workspace_id]));

        Artisan::call('svc:billing:rehearse-generation', [
            '--workspace' => $home->public_id,
            '--company' => $homeCompany->public_id,
            '--show' => true,
        ]);

        $this->assertStringContainsString('2950 min in 28 entries', Artisan::output());
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

    private function addAdjustments(ClientInvoice $draft, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $draft->lines()->create([
                'workspace_id' => $draft->workspace_id, 'type' => 'adjustment', 'description' => 'Synthetic adjustment',
                'quantity' => '1', 'unit_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'sort_order' => 50 + $i,
            ]);
        }
    }

    private function queriesDuringShow(): int
    {
        $count = 0;
        DB::listen(function ($query) use (&$count): void {
            $count++;
        });
        Artisan::call('svc:billing:rehearse-generation', [
            '--workspace' => $this->backlogWorkspace->public_id,
            '--company' => $this->backlogCompany->public_id,
            '--show' => true,
        ]);
        $counted = $count;
        $count = -1_000_000;

        return $counted;
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
