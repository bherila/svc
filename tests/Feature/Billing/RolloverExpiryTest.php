<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\RolloverCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Rollover hours expire by the calendar, not by how many months happened to
 * leave a balance behind.
 *
 * The predecessor aged balances by their position in a map that only stored
 * non-zero months, so a fully-consumed month was invisible to the ageing and
 * every balance behind it stayed spendable a month too long. That is a quiet
 * overcharge in the client\'s favour: hours that should have expired keep
 * absorbing work the retainer no longer covers, and the overage is never billed.
 */
final class RolloverExpiryTest extends TestCase
{
    /**
     * January leaves four hours. February consumes its retainer exactly, so it
     * stores nothing. By March those January hours are two months old and, with
     * a one-month window, gone.
     */
    public function test_a_fully_consumed_month_still_ages_the_balance_behind_it(): void
    {
        $results = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 6.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 10.0],
            ['year_month' => '2024-03', 'retainer_hours' => 10.0, 'hours_worked' => 12.0],
        ], rolloverMonths: 1);

        $march = $this->month($results, '2024-03');

        $this->assertSame(0.0, $march->opening->rolloverHours, 'January expired two months later');
        $this->assertSame(10.0, $march->opening->totalAvailable, 'March has only its own retainer');
        $this->assertSame(2.0, $march->closing->negativeBalance, 'The 2h beyond it is owed, not absorbed');
    }

    /**
     * The same shape one month earlier: February can still spend January.
     */
    public function test_a_balance_inside_the_window_is_still_spendable(): void
    {
        $results = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 6.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 12.0],
        ], rolloverMonths: 1);

        $february = $this->month($results, '2024-02');

        $this->assertSame(4.0, $february->opening->rolloverHours);
        $this->assertSame(14.0, $february->opening->totalAvailable);
        $this->assertSame(0.0, $february->closing->negativeBalance);
    }

    /**
     * A gap with no month recorded at all must age the same way.
     */
    public function test_hours_expire_across_a_gap_in_recorded_months(): void
    {
        $results = (new RolloverCalculator)->calculateMultipleMonths([
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 4.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 10.0],
            ['year_month' => '2024-03', 'retainer_hours' => 10.0, 'hours_worked' => 10.0],
            ['year_month' => '2024-04', 'retainer_hours' => 10.0, 'hours_worked' => 11.0],
        ], rolloverMonths: 2);

        $april = $this->month($results, '2024-04');

        $this->assertSame(0.0, $april->opening->rolloverHours, 'January is three months behind April');
        $this->assertSame(1.0, $april->closing->negativeBalance);
    }

    /**
     * @param  array<int, MonthSummary>  $results
     */
    private function month(array $results, string $yearMonth): MonthSummary
    {
        foreach ($results as $summary) {
            if ($summary->yearMonth === $yearMonth) {
                return $summary;
            }
        }

        $this->fail("No summary for {$yearMonth}.");
    }
}
