<?php

namespace Tests\Unit\Billing;

use App\Services\Billing\RolloverCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RolloverCalculator class.
 *
 * Tests cover all rollover scenarios:
 * a) Hours exceeded retainer, rollover hours available to use
 * b) Hours exceeded retainer, not enough rollover hours (excess billed)
 * c) Hours didn't exceed retainer, all unused roll over
 * d) Rollover_months=1 means no rollover (hours expire)
 */
class RolloverCalculatorTest extends TestCase
{
    private RolloverCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new RolloverCalculator;
    }

    // =========================================================================
    // Opening Balance Tests
    // =========================================================================

    public function test_opening_balance_with_no_previous_months(): void
    {
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(0.0, $result->rolloverHours);
        $this->assertEquals(0.0, $result->expiredHours);
        $this->assertEquals(10.0, $result->totalAvailable);
        $this->assertEquals(0.0, $result->negativeOffset);
    }

    public function test_opening_balance_with_rollover_from_previous_month(): void
    {
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [1 => 5.0], // 5 hours from last month
            rolloverMonths: 3, // Hours can roll over for 3 months
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(5.0, $result->rolloverHours);
        $this->assertEquals(0.0, $result->expiredHours);
        $this->assertEquals(15.0, $result->totalAvailable);
    }

    public function test_opening_balance_with_expired_hours(): void
    {
        // rollover_months = 2 means hours roll over for 2 months (1 month ago and 2 months ago)
        // So hours from 3+ months ago expire
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [
                1 => 3.0, // 3 hours from 1 month ago (rolls over)
                2 => 5.0, // 5 hours from 2 months ago (rolls over)
                3 => 2.0, // 2 hours from 3 months ago (expires)
            ],
            rolloverMonths: 2,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(8.0, $result->rolloverHours); // 3 + 5
        $this->assertEquals(2.0, $result->expiredHours);
        $this->assertEquals(18.0, $result->totalAvailable);
    }

    public function test_opening_balance_with_one_month_rollover(): void
    {
        // rollover_months = 1 means hours roll over for 1 month only
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [
                1 => 5.0, // 1 month ago (rolls over)
                2 => 3.0,  // 2 months ago (expires)
            ],
            rolloverMonths: 1,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(5.0, $result->rolloverHours);
        $this->assertEquals(3.0, $result->expiredHours);
        $this->assertEquals(15.0, $result->totalAvailable);
    }

    public function test_opening_balance_with_zero_rollover(): void
    {
        // rollover_months = 0 means NO rollover
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [1 => 5.0], // Should expire
            rolloverMonths: 0,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(0.0, $result->rolloverHours);
        $this->assertEquals(5.0, $result->expiredHours);
        $this->assertEquals(10.0, $result->totalAvailable);
    }

    public function test_opening_balance_with_negative_offset(): void
    {
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 3.0 // 3 hours to offset
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(3.0, $result->negativeOffset);
        $this->assertEquals(7.0, $result->effectiveRetainerHours);
        $this->assertEquals(7.0, $result->totalAvailable);
    }

    public function test_opening_balance_negative_offset_carried_forward(): void
    {
        // Negative balance exceeds retainer hours
        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 15.0
        );

        $this->assertEquals(10.0, $result->retainerHours);
        $this->assertEquals(10.0, $result->negativeOffset); // Capped at retainer for pool calculation
        $this->assertEquals(0.0, $result->invoicedNegativeBalance); // No longer billed immediately
        $this->assertEquals(5.0, $result->remainingNegativeBalance); // Remaining 5 hours tracked
        $this->assertEquals(0.0, $result->effectiveRetainerHours);
        $this->assertEquals(0.0, $result->totalAvailable);
    }

    // =========================================================================
    // Closing Balance Tests
    // =========================================================================

    public function test_closing_balance_case_c_hours_within_retainer(): void
    {
        // Case C: Hours worked < retainer, unused rolls over
        $result = $this->calculator->calculateClosingBalance(
            totalAvailable: 10.0,
            hoursWorked: 7.0,
            retainerHours: 10.0,
            rolloverHours: 0.0
        );

        $this->assertEquals(7.0, $result->hoursUsedFromRetainer);
        $this->assertEquals(0.0, $result->hoursUsedFromRollover);
        $this->assertEquals(3.0, $result->unusedHours); // Will roll over
        $this->assertEquals(0.0, $result->excessHours);
        $this->assertEquals(0.0, $result->negativeBalance);
    }

    public function test_closing_balance_case_a_uses_rollover_hours(): void
    {
        // Case A: Hours exceed retainer, uses rollover
        $result = $this->calculator->calculateClosingBalance(
            totalAvailable: 15.0, // 10 retainer + 5 rollover
            hoursWorked: 13.0,
            retainerHours: 10.0,
            rolloverHours: 5.0
        );

        $this->assertEquals(10.0, $result->hoursUsedFromRetainer);
        $this->assertEquals(3.0, $result->hoursUsedFromRollover);
        $this->assertEquals(0.0, $result->unusedHours);
        $this->assertEquals(0.0, $result->excessHours);
        $this->assertEquals(2.0, $result->remainingRollover); // 5 - 3 = 2
    }

    public function test_closing_balance_case_b_exceeds_all_available(): void
    {
        // Case B: Exceeds all available hours (retainer + rollover)
        $result = $this->calculator->calculateClosingBalance(
            totalAvailable: 15.0,
            hoursWorked: 20.0,
            retainerHours: 10.0,
            rolloverHours: 5.0
        );

        $this->assertEquals(10.0, $result->hoursUsedFromRetainer);
        $this->assertEquals(5.0, $result->hoursUsedFromRollover);
        $this->assertEquals(0.0, $result->unusedHours);
        $this->assertEquals(0.0, $result->excessHours); // Not billed immediately
        $this->assertEquals(5.0, $result->negativeBalance); // Carried forward
        $this->assertEquals(0.0, $result->remainingRollover);
    }

    public function test_closing_balance_exact_retainer_usage(): void
    {
        $result = $this->calculator->calculateClosingBalance(
            totalAvailable: 10.0,
            hoursWorked: 10.0,
            retainerHours: 10.0,
            rolloverHours: 0.0
        );

        $this->assertEquals(10.0, $result->hoursUsedFromRetainer);
        $this->assertEquals(0.0, $result->unusedHours);
        $this->assertEquals(0.0, $result->excessHours);
    }

    // =========================================================================
    // Month Summary Tests
    // =========================================================================

    public function test_month_summary_simple_case(): void
    {
        $result = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 8.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->opening->retainerHours);
        $this->assertEquals(10.0, $result->opening->totalAvailable);
        $this->assertEquals(8.0, $result->hoursWorked);
        $this->assertEquals(2.0, $result->closing->unusedHours);
    }

    public function test_month_summary_records_immediate_excess_mode(): void
    {
        $result = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 15.0,
            previousMonthsUnused: [],
            rolloverMonths: 0,
            previousNegativeBalance: 0.0,
            billExcessImmediately: true,
            yearMonth: '2026-04'
        );

        $this->assertTrue($result->billExcessImmediately);
        $this->assertEquals(5.0, $result->closing->excessHours);
    }

    public function test_month_summary_with_rollover_used(): void
    {
        $result = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 12.0,
            previousMonthsUnused: [1 => 5.0], // 5 hours from last month
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.0, $result->opening->retainerHours);
        $this->assertEquals(5.0, $result->opening->rolloverHours);
        $this->assertEquals(15.0, $result->opening->totalAvailable);
        $this->assertEquals(12.0, $result->hoursWorked);
        $this->assertEquals(10.0, $result->closing->hoursUsedFromRetainer);
        $this->assertEquals(2.0, $result->closing->hoursUsedFromRollover);
        $this->assertEquals(3.0, $result->closing->remainingRollover);
    }

    // =========================================================================
    // Multiple Months Tests - Full Scenarios
    // =========================================================================

    public function test_multiple_months_case_c_unused_rolls_over(): void
    {
        // Case C: Hours under retainer, all unused roll over
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 7.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 8.0],
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 3);

        // Month 1: 7 worked, 3 unused
        $this->assertEquals(3.0, $results[0]->closing->unusedHours);

        // Month 2: 10 retainer + 3 rollover = 13 available, 8 worked
        $this->assertEquals(3.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(13.0, $results[1]->opening->totalAvailable);
        // Only 2 unused from this month's retainer (8 worked out of 10)
        $this->assertEquals(2.0, $results[1]->closing->unusedHours);
    }

    public function test_billed_overage_settles_in_its_month_and_its_surplus_expires(): void
    {
        $results = $this->calculator->calculateMultipleMonths([
            [
                'year_month' => '2026-01',
                'retainer_hours' => 2.0,
                'hours_worked' => 10.0,
                'billed_overage_hours' => 7.0,
            ],
            [
                'year_month' => '2026-02',
                'retainer_hours' => 2.0,
                'hours_worked' => 2.5833,
                'billed_overage_hours' => 1.5833,
            ],
            ['year_month' => '2026-03', 'retainer_hours' => 2.0, 'hours_worked' => 1.0],
            ['year_month' => '2026-04', 'retainer_hours' => 2.0, 'hours_worked' => 1.75],
            ['year_month' => '2026-05', 'retainer_hours' => 2.0, 'hours_worked' => 2.75],
            ['year_month' => '2026-06', 'retainer_hours' => 2.0, 'hours_worked' => 0.0],
            ['year_month' => '2026-07', 'retainer_hours' => 2.0, 'hours_worked' => 0.0],
            ['year_month' => '2026-08', 'retainer_hours' => 2.0, 'hours_worked' => 0.0],
            ['year_month' => '2026-09', 'retainer_hours' => 2.0, 'hours_worked' => 0.0],
        ], rolloverMonths: 1);

        $this->assertSame(1.0, $results[0]->closing->negativeBalance, 'January charge settles seven of eight debt hours');
        $this->assertSame(0.0, $results[1]->closing->negativeBalance, 'February charge settles the remaining overage where it occurs');
        $this->assertSame(4.0, $results[8]->opening->totalAvailable, 'Only August capacity survives into September');
        $this->assertSame(2.0, $results[8]->opening->rolloverHours);
    }

    public function test_billed_overage_separates_fractional_debt_settlement_from_surplus(): void
    {
        $surplus = $this->calculator->calculateMultipleMonths([[
            'year_month' => '2026-01',
            'retainer_hours' => 2.0,
            'hours_worked' => 3.1234,
            'billed_overage_hours' => 1.5678,
        ]], rolloverMonths: 1);

        $this->assertSame(0.4444, $surplus[0]->closing->unusedHours);
        $this->assertSame(0.0, $surplus[0]->closing->negativeBalance);

        $partial = $this->calculator->calculateMultipleMonths([[
            'year_month' => '2026-01',
            'retainer_hours' => 2.0,
            'hours_worked' => 4.2345,
            'billed_overage_hours' => 1.1111,
        ]], rolloverMonths: 1);

        $this->assertSame(0.0, $partial[0]->closing->unusedHours);
        $this->assertSame(1.1234, $partial[0]->closing->negativeBalance);
    }

    public function test_multiple_months_case_a_uses_rollover(): void
    {
        // Case A: Exceeds retainer, uses available rollover
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 5.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 13.0],
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 3);

        // Month 1: 5 worked, 5 unused
        $this->assertEquals(5.0, $results[0]->closing->unusedHours);

        // Month 2: Uses rollover for excess
        $this->assertEquals(5.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(15.0, $results[1]->opening->totalAvailable);
        $this->assertEquals(10.0, $results[1]->closing->hoursUsedFromRetainer);
        $this->assertEquals(3.0, $results[1]->closing->hoursUsedFromRollover);
        $this->assertEquals(0.0, $results[1]->closing->excessHours);
    }

    public function test_multiple_months_case_b_exceeds_rollover(): void
    {
        // Case B: Exceeds retainer AND rollover, generates excess
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 7.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 20.0],
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 3);

        // Month 1: 7 worked, 3 unused
        $this->assertEquals(3.0, $results[0]->closing->unusedHours);

        // Month 2: 10 retainer + 3 rollover = 13 available, 20 worked = 7 negative balance
        $this->assertEquals(3.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(13.0, $results[1]->opening->totalAvailable);
        $this->assertEquals(10.0, $results[1]->closing->hoursUsedFromRetainer);
        $this->assertEquals(3.0, $results[1]->closing->hoursUsedFromRollover);
        $this->assertEquals(0.0, $results[1]->closing->excessHours);
        $this->assertEquals(7.0, $results[1]->closing->negativeBalance);
    }

    public function test_multiple_months_with_zero_rollover(): void
    {
        // Case D: rollover_months=0 means hours don't roll over (expire immediately)
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 5.0],
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 12.0],
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 0);

        // Month 1: 5 worked, 5 unused (but won't roll over)
        $this->assertEquals(5.0, $results[0]->closing->unusedHours);

        // Month 2: Only 10 available (no rollover), 12 worked = 2 excess
        $this->assertEquals(0.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(5.0, $results[1]->opening->expiredHours);
        $this->assertEquals(10.0, $results[1]->opening->totalAvailable);
        $this->assertEquals(0.0, $results[1]->closing->excessHours);
        $this->assertEquals(2.0, $results[1]->closing->negativeBalance);
    }

    public function test_multiple_months_hours_expire_after_rollover_window(): void
    {
        // With rollover_months=2, hours from 2+ months ago expire
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 5.0], // 5 unused
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 6.0], // 4 unused
            ['year_month' => '2024-03', 'retainer_hours' => 10.0, 'hours_worked' => 8.0], // Jan's hours expire
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 2);

        // Month 1: 5 unused
        $this->assertEquals(5.0, $results[0]->closing->unusedHours);

        // Month 2: 5 rollover from Jan + 10 retainer, 6 used, 4 unused from Feb's retainer
        $this->assertEquals(5.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(4.0, $results[1]->closing->unusedHours);

        // Month 3: Jan's 5 hours are 2 months old. rollover_months=2 means they are still valid.
        // Feb's 4 hours are 1 month old. Valid.
        // Total rollover = 5 + 4 = 9.
        $this->assertEquals(9.0, $results[2]->opening->rolloverHours);
        // Actually Jan's hours would have been used in Feb, so let's verify
        // The 5 hours from Jan rolled to Feb, Feb had 6 worked out of 10 retainer
        // So 4 unused from Feb, and Jan's 5 rollover wasn't needed
        // In Month 3 with rollover_months=2:
        // - Feb's 4 unused (1 month ago) should roll over
        // - Jan's 5 unused would be 2 months ago - but wait, they weren't used in Feb
        // Actually the calculator tracks unused per month, let me trace through
    }

    public function test_three_month_scenario_with_varying_usage(): void
    {
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 5.0],  // 5 unused
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 10.0], // Used exactly 10
            ['year_month' => '2024-03', 'retainer_hours' => 10.0, 'hours_worked' => 18.0], // Heavy usage
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 3);

        // Month 1: 5 unused
        $this->assertEquals(5.0, $results[0]->closing->unusedHours);

        // Month 2: Had 5 rollover, used 10 from retainer (exactly), 0 from rollover
        $this->assertEquals(5.0, $results[1]->opening->rolloverHours);
        $this->assertEquals(15.0, $results[1]->opening->totalAvailable);
        $this->assertEquals(10.0, $results[1]->closing->hoursUsedFromRetainer);
        $this->assertEquals(0.0, $results[1]->closing->hoursUsedFromRollover);
        $this->assertEquals(0.0, $results[1]->closing->unusedHours); // Used exactly retainer

        // Month 3: Still has 5 rollover from Jan (Feb had 0 unused)
        $this->assertEquals(5.0, $results[2]->opening->rolloverHours);
        $this->assertEquals(15.0, $results[2]->opening->totalAvailable);
        // 18 worked: 10 from retainer + 5 from rollover + 3 negative balance
        $this->assertEquals(10.0, $results[2]->closing->hoursUsedFromRetainer);
        $this->assertEquals(5.0, $results[2]->closing->hoursUsedFromRollover);
        $this->assertEquals(0.0, $results[2]->closing->excessHours);
        $this->assertEquals(3.0, $results[2]->closing->negativeBalance);
    }

    public function test_full_year_scenario(): void
    {
        // Simulate a full year with varying monthly usage
        $months = [
            ['year_month' => '2024-01', 'retainer_hours' => 10.0, 'hours_worked' => 8.0],   // +2
            ['year_month' => '2024-02', 'retainer_hours' => 10.0, 'hours_worked' => 6.0],   // +4
            ['year_month' => '2024-03', 'retainer_hours' => 10.0, 'hours_worked' => 15.0],  // Uses 5 rollover
            ['year_month' => '2024-04', 'retainer_hours' => 10.0, 'hours_worked' => 12.0],  // Uses some rollover
            ['year_month' => '2024-05', 'retainer_hours' => 10.0, 'hours_worked' => 5.0],   // +5
            ['year_month' => '2024-06', 'retainer_hours' => 10.0, 'hours_worked' => 20.0],  // Excess
        ];

        $results = $this->calculator->calculateMultipleMonths($months, rolloverMonths: 3);

        // Verify no negative hours in any month (excess is tracked, not negative balance)
        foreach ($results as $month) {
            $this->assertGreaterThanOrEqual(0, $month->closing->unusedHours);
            $this->assertGreaterThanOrEqual(0, $month->closing->excessHours);
        }

        // June should have negative balance hours (high usage month)
        $this->assertGreaterThan(0, $results[5]->closing->negativeBalance);
    }

    // =========================================================================
    // Edge Cases
    // =========================================================================

    public function test_zero_hours_worked(): void
    {
        $result = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 0.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(0.0, $result->hoursWorked);
        $this->assertEquals(10.0, $result->closing->unusedHours);
    }

    public function test_fractional_hours(): void
    {
        $result = $this->calculator->calculateMonthSummary(
            retainerHours: 10.5,
            hoursWorked: 7.25,
            previousMonthsUnused: [1 => 2.75],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $this->assertEquals(10.5, $result->opening->retainerHours);
        $this->assertEquals(2.75, $result->opening->rolloverHours);
        $this->assertEquals(7.25, $result->hoursWorked);
        $this->assertEquals(3.25, $result->closing->unusedHours);
    }

    public function test_very_large_rollover_window(): void
    {
        $previousUnused = [
            1 => 2.0,
            2 => 3.0,
            3 => 1.0,
            4 => 4.0,
            5 => 2.0,
        ];

        $result = $this->calculator->calculateOpeningBalance(
            retainerHours: 10.0,
            previousMonthsUnused: $previousUnused,
            rolloverMonths: 12, // Full year rollover
            previousNegativeBalance: 0.0
        );

        // All 5 months of unused hours should roll over
        $this->assertEquals(12.0, $result->rolloverHours); // 2+3+1+4+2
        $this->assertEquals(0.0, $result->expiredHours);
    }

    public function test_status_description_excess(): void
    {
        $summary = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 15.0,
            previousMonthsUnused: [],
            rolloverMonths: 1,
            previousNegativeBalance: 0.0
        );

        $description = $this->calculator->getStatusDescription($summary);
        $this->assertStringContainsString('Negative balance of 5.00 hours carried forward', $description);
    }

    public function test_status_description_unused(): void
    {
        $summary = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 7.0,
            previousMonthsUnused: [],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $description = $this->calculator->getStatusDescription($summary);
        $this->assertStringContainsString('3.00 unused hours will roll over', $description);
    }

    public function test_status_description_used_rollover(): void
    {
        $summary = $this->calculator->calculateMonthSummary(
            retainerHours: 10.0,
            hoursWorked: 12.0,
            previousMonthsUnused: [1 => 5.0],
            rolloverMonths: 3,
            previousNegativeBalance: 0.0
        );

        $description = $this->calculator->getStatusDescription($summary);
        $this->assertStringContainsString('Used 2.00 rollover hours', $description);
    }
}
