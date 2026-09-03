<?php

namespace Tests\Unit\AgentApi;

use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Support\AgentApi\Presenters\CapacityLedgerPresenter;
use Tests\TestCase;

final class CapacityLedgerPresenterTest extends TestCase
{
    public function test_it_serializes_only_the_computed_capacity_ledger_values(): void
    {
        $summary = new MonthSummary(
            new OpeningBalance(10, 2, 1, 12, 0, 0, 10, 0),
            new ClosingBalance(8, 1, 3, 0, 0, 1),
            hoursWorked: 9,
            yearMonth: '2026-09',
            retainerHours: 10,
            billExcessImmediately: false,
            cycleStart: '2026-09-01',
        );

        $this->assertSame([
            'period' => '2026-09',
            'cycle_start' => '2026-09-01',
            'retainer_hours' => 10.0,
            'hours_worked' => 9.0,
            'opening_retainer_hours' => 10.0,
            'opening_rollover_hours' => 2.0,
            'opening_expired_hours' => 1.0,
            'opening_available_hours' => 12.0,
            'hours_used_from_retainer' => 8.0,
            'hours_used_from_rollover' => 1.0,
            'unused_hours' => 3.0,
            'excess_hours' => 0.0,
            'negative_hours' => 0.0,
            'signed_available_hours' => 3.0,
            'remaining_rollover_hours' => 1.0,
            'bill_excess_immediately' => false,
        ], (new CapacityLedgerPresenter)->present($summary));
    }

    public function test_the_signed_position_is_negative_when_the_month_closes_in_deficit(): void
    {
        $summary = new MonthSummary(
            new OpeningBalance(0, 0, 0, 0, 0, 2, 0, 2),
            new ClosingBalance(0, 0, 0, 2, 2, 0),
            hoursWorked: 2,
            yearMonth: '2026-09',
            retainerHours: 0,
        );

        $this->assertSame(
            -2.0,
            (new CapacityLedgerPresenter)->present($summary)['signed_available_hours'],
        );
    }
}
