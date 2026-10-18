<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use App\Support\Billing\DeferredBacklog;
use App\Support\Billing\DeferredBacklogIssue;
use App\Support\Billing\DeferredBacklogMonth;
use Carbon\CarbonImmutable;
use LogicException;
use Tests\TestCase;

/**
 * The deferred backlog, computed without a database from loaded entries.
 *
 * The diff-scoped mutation lane runs the Unit suite alone, so every rule of
 * the calculation is pinned here; `DeferredBacklogParityTest` pins that the
 * loaded set is the termination collection.
 */
final class DeferredBacklogTest extends TestCase
{
    public function test_it_states_the_shelf_and_prices_it_as_termination_would(): void
    {
        $backlog = $this->calculate(outstanding: [
            $this->entry(1, 90, '2026-02-10'),
            $this->entry(2, 30, '2026-01-05', mode: 'retainer'),
            $this->entry(3, 60, '2026-03-01', mode: 'flat_hourly', cost: 5000),
        ]);

        $this->assertNull($backlog->issue);
        $this->assertNull($backlog->issueDetail);
        $this->assertSame('2026-03-31', $backlog->through);
        $this->assertSame('USD', $backlog->currency);
        $this->assertSame(180, $backlog->minutes);
        $this->assertSame(3.0, $backlog->hours());
        $this->assertSame(3, $backlog->entryCount);
        $this->assertSame([1, 2, 3], $backlog->entryIds);
        $this->assertSame('2026-01-05', $backlog->oldestWorkedOn);
        $this->assertSame(120, $backlog->agreementRateMinutes);
        $this->assertSame(2.0, $backlog->agreementRateHours());
        $this->assertSame(60, $backlog->flatHourlyMinutes);
        $this->assertSame(1.0, $backlog->flatHourlyHours());
        // Two agreement-rate hours at 150.00 and one flat-hourly hour at 50.00.
        $this->assertSame(30000 + 5000, $backlog->amount);
        $this->assertTrue($backlog->hasBacklog());
    }

    public function test_hours_are_stated_to_four_places(): void
    {
        $backlog = $this->calculate(outstanding: [
            $this->entry(1, 1, '2026-03-01'),
            $this->entry(2, 1, '2026-03-01', mode: 'flat_hourly', cost: 5000),
        ]);

        $this->assertSame(0.0333, $backlog->hours());
        $this->assertSame(0.0167, $backlog->agreementRateHours());
        $this->assertSame(0.0167, $backlog->flatHourlyHours());
    }

    public function test_an_agreement_without_a_currency_quotes_in_none(): void
    {
        $agreement = $this->agreement(15000);
        $agreement->setRawAttributes(['currency' => null] + $agreement->getAttributes(), true);

        $backlog = DeferredBacklog::calculate($agreement, CarbonImmutable::parse('2026-03-31'), true, [], [], []);

        $this->assertSame('', $backlog->currency);
    }

    public function test_an_amount_beyond_the_integer_range_is_unpriced_rather_than_thrown(): void
    {
        $backlog = $this->calculate(outstanding: [$this->entry(1, 120, '2026-03-01')], rate: PHP_INT_MAX);

        $this->assertSame(DeferredBacklogIssue::Unpriced, $backlog->issue);
        $this->assertSame('Line total exceeds the supported integer range.', $backlog->issueDetail);
        $this->assertSame(2.0, $backlog->hours());
    }

    public function test_scope_keys_read_as_strings_compare_as_numbers(): void
    {
        $agreement = $this->agreement(15000, projectId: 11);
        $stringly = (new ClientAgreement)->setRawAttributes([
            'workspace_id' => '3', 'client_company_id' => '5', 'client_project_id' => '11',
        ], true);
        $entry = $this->entry(1, 60, '2026-03-01');
        $entry->setRawAttributes(['workspace_id' => '3', 'client_company_id' => '5', 'client_project_id' => '11'] + $entry->getAttributes(), true);

        $this->assertTrue($entry->isWithinAgreementScope($agreement));
        $this->assertTrue($this->entry(2, 60, '2026-03-01')->isWithinAgreementScope($stringly));
    }

    public function test_an_empty_shelf_is_zero_rather_than_unknown(): void
    {
        $backlog = $this->calculate(outstanding: []);

        $this->assertNull($backlog->issue);
        $this->assertSame(0, $backlog->minutes);
        $this->assertSame(0.0, $backlog->hours());
        $this->assertSame(0, $backlog->entryCount);
        $this->assertNull($backlog->oldestWorkedOn);
        $this->assertSame(0, $backlog->amount);
        $this->assertFalse($backlog->hasBacklog());
    }

    public function test_a_disagreeing_project_chain_withholds_every_figure(): void
    {
        $backlog = $this->calculate(
            outstanding: [$this->entry(1, 60, '2026-03-01')],
            history: [$this->entry(1, 60, '2026-03-01', lines: [])],
            periods: ['2026-03'],
            chainsAgree: false,
        );

        $this->assertSame(DeferredBacklogIssue::ProjectChainMismatch, $backlog->issue);
        $this->assertNull($backlog->minutes);
        $this->assertNull($backlog->hours());
        $this->assertNull($backlog->entryCount);
        $this->assertNull($backlog->oldestWorkedOn);
        $this->assertNull($backlog->agreementRateHours());
        $this->assertNull($backlog->flatHourlyHours());
        $this->assertNull($backlog->amount);
        $this->assertSame([], $backlog->entryIds);
        $this->assertSame([], $backlog->trend);
        $this->assertNull($backlog->netHoursPerMonth());
        $this->assertTrue($backlog->hasBacklog());
        $this->assertSame('2026-03-31', $backlog->through);
    }

    public function test_work_termination_would_refuse_keeps_its_hours_and_withholds_its_amount(): void
    {
        $backlog = $this->calculate(outstanding: [$this->entry(1, 60, '2026-03-01')], rate: null);

        $this->assertSame(DeferredBacklogIssue::Unpriced, $backlog->issue);
        $this->assertStringContainsString('states no hourly rate', (string) $backlog->issueDetail);
        $this->assertSame(1.0, $backlog->hours());
        $this->assertSame(1, $backlog->entryCount);
        $this->assertNull($backlog->amount);
        $this->assertNull($backlog->agreementRateMinutes);
        $this->assertNull($backlog->flatHourlyMinutes);
    }

    public function test_a_flat_hourly_snapshot_in_another_currency_is_unpriced_by_name(): void
    {
        $backlog = $this->calculate(outstanding: [
            $this->entry(1, 60, '2026-03-01', mode: 'flat_hourly', cost: 5000, costCurrency: 'EUR'),
        ]);

        $this->assertSame(DeferredBacklogIssue::Unpriced, $backlog->issue);
        $this->assertSame('Subcontractor time is costed in EUR but the termination invoice is in USD; convert it before billing.', $backlog->issueDetail);
    }

    public function test_the_trend_books_each_movement_in_its_own_month(): void
    {
        $backlog = $this->calculate(
            outstanding: [],
            history: [
                // Added in January, still waiting.
                $this->entry(1, 60, '2026-01-10', lines: []),
                // Added in January, absorbed by February's pool.
                $this->entry(2, 30, '2026-01-20', lines: [$this->line('prior_month_retainer', '2026-02-28')]),
                // Added before the window, absorbed in March: applied only.
                $this->entry(3, 45, '2025-11-03', lines: [$this->line('prior_month_retainer', null, '2026-03-31')]),
                // Billed at the hourly rate on termination, in March.
                $this->entry(4, 120, '2026-02-02', lines: [$this->line('additional_hours', '2026-03-31')]),
                // Settled by a buy-down in February.
                $this->entry(5, 15, '2026-02-03', lines: [$this->line('deferred_buydown', '2026-02-15')]),
                // Flat-hourly work on its own subcontractor line is billed, not applied.
                $this->entry(6, 20, '2026-02-04', mode: 'flat_hourly', cost: 5000, lines: [$this->line('subcontractor', '2026-03-31')]),
                // Outside every requested month on both ends.
                $this->entry(7, 600, '2025-06-01', lines: [$this->line('prior_month_retainer', '2025-07-31')]),
            ],
            periods: ['2026-01', '2026-02', '2026-03'],
        );

        $this->assertSame([
            ['2026-01', 90, 0, 0, 90],
            ['2026-02', 155, 30, 15, 110],
            ['2026-03', 0, 45, 140, -185],
        ], array_map(static fn (DeferredBacklogMonth $month): array => [
            $month->period, $month->minutesAdded, $month->minutesApplied, $month->minutesBilled, $month->netMinutes(),
        ], $backlog->trend));
        // (90 + 110 - 185) minutes over three months.
        $this->assertSame(round(15 / 60 / 3, 4), $backlog->netHoursPerMonth());
    }

    public function test_no_requested_months_is_no_trend(): void
    {
        $backlog = $this->calculate(outstanding: [], history: [$this->entry(1, 60, '2026-03-01', lines: [])]);

        $this->assertSame([], $backlog->trend);
        $this->assertNull($backlog->netHoursPerMonth());
    }

    public function test_the_trend_refuses_history_loaded_without_its_allocations(): void
    {
        $this->expectException(LogicException::class);

        $this->calculate(outstanding: [], history: [$this->entry(1, 60, '2026-03-01')], periods: ['2026-03']);
    }

    public function test_an_entry_belongs_to_the_agreement_by_workspace_company_and_project_scope(): void
    {
        $companyWide = $this->agreement(15000);
        $projectScoped = $this->agreement(15000, projectId: 11);
        $entry = $this->entry(1, 60, '2026-03-01');

        $this->assertTrue($entry->isWithinAgreementScope($companyWide));
        $this->assertTrue($entry->isWithinAgreementScope($projectScoped));
        $this->assertFalse($this->entry(2, 60, '2026-03-01', projectId: 12)->isWithinAgreementScope($projectScoped));
        $this->assertTrue($this->entry(2, 60, '2026-03-01', projectId: 12)->isWithinAgreementScope($companyWide));
        $this->assertFalse($this->entry(3, 60, '2026-03-01', companyId: 6)->isWithinAgreementScope($companyWide));
        $this->assertFalse($this->entry(4, 60, '2026-03-01', workspaceId: 4)->isWithinAgreementScope($companyWide));
    }

    public function test_the_allocation_date_is_the_lines_never_before_the_work(): void
    {
        $this->assertSame('2026-02-15', $this->entry(1, 60, '2026-02-03', lines: [$this->line('deferred_buydown', '2026-02-15')])->allocatedOn()->toDateString());
        $this->assertSame('2026-02-03', $this->entry(1, 60, '2026-02-03', lines: [$this->line('deferred_buydown', '2026-01-15')])->allocatedOn()->toDateString());
        $this->assertSame('2026-04-30', $this->entry(1, 60, '2026-02-03', lines: [$this->line('deferred_buydown', null, '2026-04-30')])->allocatedOn()->toDateString());
        $this->assertSame('2026-02-03', $this->entry(1, 60, '2026-02-03', lines: [$this->line('deferred_buydown', null)])->allocatedOn()->toDateString());
        $this->assertSame('2026-02-03', $this->entry(1, 60, '2026-02-03', lines: [])->allocatedOn()->toDateString());
    }

    public function test_the_allocation_date_needs_the_scoped_eager_load(): void
    {
        $this->expectException(LogicException::class);

        $this->entry(1, 60, '2026-02-03')->allocatedOn();
    }

    /**
     * @param  list<ClientTimeEntry>  $outstanding
     * @param  list<ClientTimeEntry>  $history
     * @param  list<string>  $periods
     */
    private function calculate(array $outstanding, array $history = [], array $periods = [], bool $chainsAgree = true, ?int $rate = 15000): DeferredBacklog
    {
        return DeferredBacklog::calculate(
            $this->agreement($rate),
            CarbonImmutable::parse('2026-03-31'),
            $chainsAgree,
            $outstanding,
            $history,
            $periods,
        );
    }

    private function agreement(?int $rate, ?int $projectId = null): ClientAgreement
    {
        return (new ClientAgreement)->setRawAttributes([
            'id' => 9,
            'public_id' => '00000000-0000-4000-8000-000000000366',
            'workspace_id' => 3,
            'client_company_id' => 5,
            'client_project_id' => $projectId,
            'hourly_rate_amount' => $rate,
            'currency' => 'USD',
        ], true);
    }

    private function line(string $type, ?string $lineDate, ?string $periodEnd = null): ClientInvoiceLine
    {
        $line = (new ClientInvoiceLine)->setRawAttributes(['type' => $type, 'line_date' => $lineDate], true);

        return $line->setRelation('invoice', (new ClientInvoice)->setRawAttributes(['service_period_end' => $periodEnd], true));
    }

    /**
     * @param  list<ClientInvoiceLine>|null  $lines  null leaves the allocation unloaded
     */
    private function entry(
        int $id,
        int $minutes,
        string $workedOn,
        ?string $mode = null,
        ?int $cost = null,
        string $costCurrency = 'USD',
        ?array $lines = null,
        int $projectId = 11,
        int $companyId = 5,
        int $workspaceId = 3,
    ): ClientTimeEntry {
        $entry = (new ClientTimeEntry)->setRawAttributes([
            'id' => $id,
            'workspace_id' => $workspaceId,
            'client_company_id' => $companyId,
            'client_project_id' => $projectId,
            'user_id' => 7,
            'worked_on' => $workedOn,
            'minutes' => $minutes,
            'status' => 'approved',
            'is_billable' => true,
            'is_deferred' => true,
            'billing_rate_amount' => 9900,
            'currency' => 'USD',
            'subcontractor_billing_mode' => $mode,
            'subcontractor_cost_amount' => $cost,
            'subcontractor_cost_currency' => $cost === null ? null : $costCurrency,
        ], true);

        return $lines === null ? $entry : $entry->setRelation('invoiceLines', collect($lines));
    }
}
