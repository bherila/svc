<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\RecurringItemBiller;
use App\Support\Billing\ChargeCadence;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecurringItemBiller.
 *
 * Tests cover each ChargeCadence × billing cycle combination, mid-period starts,
 * anchor-month edge cases, and the one-time incidence type.
 */
class RecurringItemBillerTest extends TestCase
{
    private RecurringItemBiller $biller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->biller = new RecurringItemBiller;
    }

    // =========================================================================
    // Monthly charge cadence
    // =========================================================================

    public function test_monthly_item_on_monthly_cycle_produces_one_incidence(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null, anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-03-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(1, $lines);
        $this->assertEquals('2024-03-01', $lines[0]['line_date']->toDateString());
    }

    public function test_monthly_item_on_quarterly_cycle_produces_three_incidences(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null, anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(3, $lines);
        $this->assertEquals('2024-01-01', $lines[0]['line_date']->toDateString());
        $this->assertEquals('2024-02-01', $lines[1]['line_date']->toDateString());
        $this->assertEquals('2024-03-01', $lines[2]['line_date']->toDateString());
    }

    public function test_monthly_item_on_annual_cycle_produces_twelve_incidences(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null, anchorDay: 15);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31'));

        $this->assertCount(12, $lines);
        $this->assertEquals('2024-01-15', $lines[0]['line_date']->toDateString());
        $this->assertEquals('2024-12-15', $lines[11]['line_date']->toDateString());
    }

    public function test_monthly_item_respects_start_date(): void
    {
        // Item starts Feb 15; Q1 cycle is Jan–Mar → only Feb and Mar incidences
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-02-15', null, anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(2, $lines);
        $this->assertEquals('2024-02-15', $lines[0]['line_date']->toDateString()); // anchor is 1 but start_date is Feb 15
        $this->assertEquals('2024-03-01', $lines[1]['line_date']->toDateString());
    }

    public function test_monthly_item_respects_end_date(): void
    {
        // Item ends Feb 15; Q1 cycle → only Jan and Feb (partial) incidences
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', '2024-02-15', anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(2, $lines);
        $this->assertEquals('2024-01-01', $lines[0]['line_date']->toDateString());
        $this->assertEquals('2024-02-01', $lines[1]['line_date']->toDateString());
    }

    public function test_item_entirely_before_cycle_produces_no_incidences(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2023-01-01', '2023-12-31', anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(0, $lines);
    }

    public function test_item_entirely_after_cycle_produces_no_incidences(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2025-01-01', null, anchorDay: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertCount(0, $lines);
    }

    // =========================================================================
    // Annual charge cadence
    // =========================================================================

    public function test_annual_item_with_anchor_in_march_on_monthly_cycle_only_bills_march(): void
    {
        // Annual item with anchor month=3 (March)
        $item = $this->makeItem(ChargeCadence::Annual, '2024-01-01', null, anchorDay: 1, anchorMonth: 3);
        $agreement = $this->makeAgreement([$item]);

        // January cycle — no incidence
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-01-31'));
        $this->assertCount(0, $lines);

        // March cycle — one incidence
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-03-01'), Carbon::parse('2024-03-31'));
        $this->assertCount(1, $lines);
        $this->assertEquals('2024-03-01', $lines[0]['line_date']->toDateString());
    }

    public function test_annual_item_on_annual_cycle_produces_one_incidence(): void
    {
        $item = $this->makeItem(ChargeCadence::Annual, '2024-01-01', null, anchorDay: 1, anchorMonth: 3);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31'));

        $this->assertCount(1, $lines);
        $this->assertEquals('2024-03-01', $lines[0]['line_date']->toDateString());
    }

    // =========================================================================
    // Quarterly charge cadence
    // =========================================================================

    public function test_quarterly_item_produces_one_incidence_per_quarter(): void
    {
        $item = $this->makeItem(ChargeCadence::Quarterly, '2024-01-01', null, anchorDay: 1, anchorMonth: 1);
        $agreement = $this->makeAgreement([$item]);

        // Q1 should have one
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));
        $this->assertCount(1, $lines);
        $this->assertEquals('2024-01-01', $lines[0]['line_date']->toDateString());

        // Q2 should have one (3 months later: April)
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-04-01'), Carbon::parse('2024-06-30'));
        $this->assertCount(1, $lines);
    }

    public function test_semi_annual_item_on_annual_cycle_produces_two_incidences(): void
    {
        $item = $this->makeItem(ChargeCadence::SemiAnnual, '2024-01-01', null, anchorDay: 1, anchorMonth: 2);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31'));

        $this->assertCount(2, $lines);
        $this->assertEquals('2024-02-01', $lines[0]['line_date']->toDateString());
        $this->assertEquals('2024-08-01', $lines[1]['line_date']->toDateString());
    }

    public function test_periodic_item_bills_first_incidence_at_item_start_when_anchor_precedes_start(): void
    {
        $item = $this->makeItem(ChargeCadence::Annual, '2024-02-15', null, anchorDay: 1, anchorMonth: 1);
        $agreement = $this->makeAgreement([$item]);

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-12-31'));

        $this->assertCount(1, $lines);
        $this->assertEquals('2024-02-15', $lines[0]['line_date']->toDateString());
    }

    // =========================================================================
    // One-time charge cadence
    // =========================================================================

    public function test_one_time_item_billed_in_cycle_containing_start_date(): void
    {
        $item = $this->makeItem(ChargeCadence::OneTime, '2024-02-20', null);
        $agreement = $this->makeAgreement([$item]);

        // Feb cycle — should bill
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-02-01'), Carbon::parse('2024-02-29'));
        $this->assertCount(1, $lines);
        $this->assertEquals('2024-02-20', $lines[0]['line_date']->toDateString());
    }

    public function test_one_time_item_not_billed_in_other_cycles(): void
    {
        $item = $this->makeItem(ChargeCadence::OneTime, '2024-02-20', null);
        $agreement = $this->makeAgreement([$item]);

        // Jan cycle — no bill
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-01-31'));
        $this->assertCount(0, $lines);

        // Mar cycle — no bill
        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-03-01'), Carbon::parse('2024-03-31'));
        $this->assertCount(0, $lines);
    }

    // =========================================================================
    // buildLine helper
    // =========================================================================

    public function test_build_line_creates_recurring_item_line(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null);
        $item->id = 42;
        $item->client_agreement_id = 10;

        $lineData = [
            'item' => $item,
            'line_date' => Carbon::parse('2024-03-01'),
            'amount' => 50.00,
            'description' => 'Web hosting',
        ];

        $line = $this->biller->buildLine($lineData, 3);

        $attrs = $line->getAttributes();
        // `line_type` is `type` here, and money is integer minor units.
        $this->assertEquals('recurring_item', $attrs['type']);
        $this->assertEquals('Web hosting', $attrs['description']);
        $this->assertEquals('1', $attrs['quantity']);
        $this->assertSame(5000, $attrs['unit_amount']);
        $this->assertSame(5000, $attrs['total_amount']);
        $this->assertEquals('2024-03-01', $attrs['line_date']);
        $this->assertEquals(42, $attrs['client_agreement_recurring_item_id']);
        $this->assertEquals(3, $attrs['sort_order']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeItem(
        ChargeCadence $cadence,
        string $startDate,
        ?string $endDate,
        int $anchorDay = 1,
        ?int $anchorMonth = null
    ): ClientAgreementRecurringItem {
        $item = new ClientAgreementRecurringItem;
        // Only construction is translated to this schema's column names and units;
        // every assertion in this file is the one the predecessor shipped.
        $item->setRawAttributes([
            'workspace_id' => 1,
            'client_agreement_id' => 1,
            'description' => 'Test item',
            'amount' => 5000,
            'cadence' => $cadence->value,
            'anchor_month' => $anchorMonth,
            'anchor_day' => $anchorDay,
            'effective_on' => $startDate,
            'expires_on' => $endDate,
            'is_taxable' => false,
        ]);
        $item->syncOriginal();

        return $item;
    }

    private function makeAgreement(array $items): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes([
            'active_date' => '2024-01-01',
            'billing_cadence' => 'monthly',
            'first_cycle_proration' => 'prorate_hours',
            'monthly_retainer_hours' => 10,
            'catch_up_threshold_hours' => 1,
            'rollover_months' => 1,
            'hourly_rate' => 100,
            'monthly_retainer_fee' => 1000,
        ]);
        $agreement->syncOriginal();
        $agreement->setRelation('recurringItems', collect($items));

        return $agreement;
    }
}
