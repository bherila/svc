<?php

namespace Tests\Unit\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\RecurringItemBiller;
use App\Support\Billing\ChargeCadence;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertEquals('1.000', $attrs['quantity']);
        $this->assertSame(5000, $attrs['unit_amount']);
        $this->assertSame(5000, $attrs['total_amount']);
        $this->assertEquals('2024-03-01', $attrs['line_date']);
        $this->assertEquals(42, $attrs['client_agreement_recurring_item_id']);
        $this->assertEquals(3, $attrs['sort_order']);
    }

    public function test_build_line_bills_every_unit_of_a_multi_unit_item(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null);
        $item->id = 43;
        $item->quantity = 3;

        $line = $this->biller->buildLine([
            'item' => $item,
            'line_date' => Carbon::parse('2024-03-01'),
            'amount' => 50.00,
            'description' => 'Seats',
        ], 0);

        $attrs = $line->getAttributes();
        $this->assertSame(5000, $attrs['unit_amount']);
        $this->assertSame(15000, $attrs['total_amount']);
        $this->assertEquals('3.000', $attrs['quantity']);
    }

    public function test_a_deactivated_item_stops_charging(): void
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes(['starts_on' => '2024-01-01', 'billing_cadence' => 'monthly']);
        $agreement->syncOriginal();

        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null);
        $item->is_active = false;
        $agreement->setRelation('recurringItems', collect([$item]));

        $this->assertSame([], $this->biller->linesForCycle(
            $agreement,
            Carbon::parse('2024-03-01'),
            Carbon::parse('2024-03-31'),
        ));
    }

    public function test_an_item_without_an_effective_date_starts_with_the_agreement(): void
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes(['starts_on' => '2024-03-01', 'billing_cadence' => 'monthly']);
        $agreement->syncOriginal();

        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null);
        $item->effective_on = null;
        $item->is_active = true;
        $agreement->setRelation('recurringItems', collect([$item]));

        $lines = $this->biller->linesForCycle(
            $agreement,
            Carbon::parse('2024-03-01'),
            Carbon::parse('2024-03-31'),
        );

        $this->assertCount(1, $lines);
    }

    // =========================================================================
    // The agreement's end
    // =========================================================================

    public function test_an_item_without_an_expiry_ends_with_its_agreement(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null, anchorDay: 20);
        $agreement = $this->makeAgreement([$item], endsOn: '2024-02-15');

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-01-01'), Carbon::parse('2024-03-31'));

        $this->assertSame(['2024-01-20'], array_map(fn (array $line): string => $line['line_date']->toDateString(), $lines));
    }

    public function test_an_incidence_on_the_agreements_last_day_is_still_billed(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null, anchorDay: 15);
        $agreement = $this->makeAgreement([$item], endsOn: '2024-02-15');

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-02-01'), Carbon::parse('2024-02-29'));

        $this->assertCount(1, $lines);
        $this->assertSame('2024-02-15', $lines[0]['line_date']->toDateString());
    }

    public function test_an_item_expiring_after_its_agreement_still_ends_with_the_agreement(): void
    {
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', '2024-12-31', anchorDay: 1);
        $agreement = $this->makeAgreement([$item], endsOn: '2024-03-31');

        $lines = $this->biller->linesForCycle($agreement, Carbon::parse('2024-04-01'), Carbon::parse('2024-06-30'));

        $this->assertSame([], $lines);
    }

    public function test_a_cycle_opening_after_the_agreement_ends_sells_no_periodic_item(): void
    {
        // The item's own opening would otherwise stand in for an anchor that
        // precedes it, and land on 10 July, after the end.
        $item = $this->makeItem(ChargeCadence::Annual, '2024-07-10', null, anchorDay: 1, anchorMonth: 1);
        $agreement = $this->makeAgreement([$item], endsOn: '2024-06-30');

        $this->assertSame([], $this->biller->linesForCycle($agreement, Carbon::parse('2024-07-10'), Carbon::parse('2024-12-31')));
    }

    public function test_a_cycle_opening_after_the_agreement_ends_reads_no_item_at_all(): void
    {
        // Nothing is left to sell past the end, so not even an item the
        // engine would refuse to bill (an unrecognised cadence) is consulted.
        $item = $this->makeItem(ChargeCadence::Monthly, '2024-01-01', null);
        $item->setRawAttributes(['cadence' => 'fortnightly'] + $item->getAttributes());
        $agreement = $this->makeAgreement([$item], endsOn: '2024-06-30');

        $this->assertSame([], $this->biller->linesForCycle($agreement, Carbon::parse('2024-07-01'), Carbon::parse('2024-07-31')));
        // The same item inside the agreement's life is refused, not skipped.
        $this->expectException(\DomainException::class);
        $this->biller->linesForCycle($agreement, Carbon::parse('2024-06-01'), Carbon::parse('2024-06-30'));
    }

    // =========================================================================
    // An unanchored item on its agreement's longer cadence (#685)
    // =========================================================================

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function itemsOnTheBillingPeriods(): iterable
    {
        // Each period's first day, as BillingCycleResolver lays them out: on
        // the start, and from the 31st by Carbon's month arithmetic.
        yield 'quarterly from the 9th' => ['quarterly', '2026-01-09', ['2026-01-09', '2026-04-09', '2026-07-09', '2026-10-09']];
        yield 'quarterly from the 31st' => ['quarterly', '2026-01-31', ['2026-01-31', '2026-05-01', '2026-08-01', '2026-11-01']];
        yield 'semi-annual from the 31st' => ['semi_annual', '2026-01-31', ['2026-01-31', '2026-07-31']];
        yield 'annual from the 9th' => ['annual', '2026-01-09', ['2026-01-09']];
        yield 'quarterly from the 1st, as the anchor rule placed it' => ['quarterly', '2026-01-01', ['2026-01-01', '2026-04-01', '2026-07-01', '2026-10-01']];
    }

    /** @param array<int, string> $expected */
    #[DataProvider('itemsOnTheBillingPeriods')]
    public function test_an_unanchored_item_on_its_agreements_cadence_charges_on_each_period_start(string $cadence, string $startsOn, array $expected): void
    {
        $agreement = $this->agreementOn($cadence, $startsOn, [$item = $this->unanchoredItem(ChargeCadence::from($cadence), null)]);

        $this->assertTrue(RecurringItemBiller::followsBillingCycles($agreement, $item));
        $this->assertSame($expected, $this->dates($agreement, $startsOn, '2026-12-31'));
        // A window selects charges and never makes one: asked month by month,
        // a projector sees the same dates.
        $monthly = [];
        for ($month = Carbon::parse($startsOn)->startOfMonth(); $month->lte(Carbon::parse('2026-12-31')); $month->addMonth()) {
            $monthly = [...$monthly, ...$this->dates($agreement, $month->max(Carbon::parse($startsOn))->toDateString(), $month->copy()->endOfMonth()->toDateString())];
        }
        $this->assertSame($expected, $monthly);
    }

    public function test_the_first_period_of_a_mid_month_quarter_charges_once(): void
    {
        $agreement = $this->agreementOn('quarterly', '2026-01-09', [$this->unanchoredItem(ChargeCadence::Quarterly, null)]);

        $this->assertSame(['2026-01-09'], $this->dates($agreement, '2026-01-09', '2026-04-08'));
        $this->assertSame(['2026-04-09'], $this->dates($agreement, '2026-04-09', '2026-07-08'));
    }

    public function test_an_item_starting_inside_a_period_charges_on_its_start_then_each_period_start(): void
    {
        $agreement = $this->agreementOn('quarterly', '2026-01-09', [$this->unanchoredItem(ChargeCadence::Quarterly, '2026-02-20', '2026-07-08')]);

        $this->assertSame(['2026-02-20', '2026-04-09'], $this->dates($agreement, '2026-01-09', '2026-12-31'));
        $this->assertSame([], $this->dates($agreement, '2026-03-01', '2026-03-31'), 'a window opening after the start inside its period sells nothing');
    }

    public function test_an_item_started_before_its_agreement_charges_on_the_agreements_periods(): void
    {
        // A move's successor: its items start when the old ones did.
        $agreement = $this->agreementOn('quarterly', '2026-04-09', [$this->unanchoredItem(ChargeCadence::Quarterly, '2026-01-09')]);

        $this->assertSame(['2026-04-09', '2026-07-09'], $this->dates($agreement, '2026-01-01', '2026-09-30'));
    }

    /** @return iterable<string, array{string, ChargeCadence, int|null, int|null}> */
    public static function itemsOnTheirAnchors(): iterable
    {
        yield 'a monthly agreement' => ['monthly', ChargeCadence::Quarterly, null, null];
        yield 'another cadence than the agreement\'s' => ['quarterly', ChargeCadence::Annual, null, null];
        yield 'a monthly item in a quarterly agreement' => ['quarterly', ChargeCadence::Monthly, null, null];
        yield 'an anchor day' => ['quarterly', ChargeCadence::Quarterly, 1, null];
        yield 'an anchor month' => ['quarterly', ChargeCadence::Quarterly, null, 1];
    }

    #[DataProvider('itemsOnTheirAnchors')]
    public function test_any_other_item_keeps_its_anchor_rule(string $agreementCadence, ChargeCadence $cadence, ?int $anchorDay, ?int $anchorMonth): void
    {
        $item = $this->unanchoredItem($cadence, null);
        $item->setRawAttributes(['anchor_day' => $anchorDay, 'anchor_month' => $anchorMonth] + $item->getAttributes());
        $agreement = $this->agreementOn($agreementCadence, '2026-01-09', [$item]);

        $this->assertFalse(RecurringItemBiller::followsBillingCycles($agreement, $item));
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
            'is_active' => true,
            'quantity' => 1,
        ]);
        $item->syncOriginal();

        return $item;
    }

    /** @param list<ClientAgreementRecurringItem> $items */
    private function agreementOn(string $cadence, string $startsOn, array $items): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes(['starts_on' => $startsOn, 'ends_on' => null, 'billing_cadence' => $cadence]);
        $agreement->syncOriginal();
        $agreement->setRelation('recurringItems', collect($items));

        return $agreement;
    }

    private function unanchoredItem(ChargeCadence $cadence, ?string $startDate, ?string $endDate = null): ClientAgreementRecurringItem
    {
        $item = $this->makeItem($cadence, '2000-01-01', $endDate);
        $item->setRawAttributes(['anchor_day' => null, 'anchor_month' => null, 'effective_on' => $startDate] + $item->getAttributes());
        $item->syncOriginal();

        return $item;
    }

    /** @return array<int, string> */
    private function dates(ClientAgreement $agreement, string $from, string $to): array
    {
        return array_map(static fn (array $line): string => $line['line_date']->toDateString(), $this->biller->linesForCycle($agreement, Carbon::parse($from), Carbon::parse($to)));
    }

    /** @param list<ClientAgreementRecurringItem> $items */
    private function makeAgreement(array $items, ?string $endsOn = null): ClientAgreement
    {
        $agreement = new ClientAgreement;
        $agreement->setRawAttributes([
            'active_date' => '2024-01-01',
            'ends_on' => $endsOn,
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
