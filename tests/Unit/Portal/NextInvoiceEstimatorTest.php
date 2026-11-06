<?php

namespace Tests\Unit\Portal;

use App\Services\Portal\NextInvoiceEstimator;
use App\Services\Revenue\RecurringRevenueProjector;
use App\Support\Portal\NextInvoiceEstimate;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedRecurringItem;
use App\Support\Revenue\ProjectionInputs;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The portal's "Next invoice (estimate)": pure, so every case builds its terms
 * in memory. Today is 2026-11-06 throughout.
 */
final class NextInvoiceEstimatorTest extends TestCase
{
    private const string TODAY = '2026-11-06';

    private int $nextItemId = 500;

    public function test_a_monthly_retainer_is_the_current_month_until_it_has_been_sold(): void
    {
        $estimate = $this->estimate($this->agreement(retainerAmount: 800000));

        $this->assertNotNull($estimate);
        $this->assertSame('2026-11-01', $estimate->periodStart);
        $this->assertSame('2026-11-30', $estimate->periodEnd);
        $this->assertSame('USD', $estimate->currency);
        $this->assertSame([['kind' => 'retainer', 'description' => 'Retainer', 'amount' => 800000, 'date' => '2026-11-01', 'hours' => null]], $estimate->parts);
        $this->assertSame(800000, $estimate->total());
    }

    public function test_a_retainer_already_on_a_charged_invoice_moves_the_estimate_to_the_next_month(): void
    {
        $agreement = $this->agreement(retainerAmount: 800000);

        $estimate = $this->estimate($agreement, [ProjectionInputs::retainerKey($agreement->id, '2026-11-01') => true]);

        $this->assertNotNull($estimate);
        $this->assertSame('2026-12-01', $estimate->periodStart);
        $this->assertSame('2026-12-31', $estimate->periodEnd);
        $this->assertSame(800000, $estimate->total());
    }

    public function test_the_estimate_looks_at_most_three_cycles_ahead(): void
    {
        $agreement = $this->agreement(retainerAmount: 800000);
        $billed = [];
        foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $date) {
            $billed[ProjectionInputs::retainerKey($agreement->id, $date)] = true;
        }

        $this->assertNull($this->estimate($agreement, $billed));

        unset($billed[ProjectionInputs::retainerKey($agreement->id, '2027-01-01')]);
        $this->assertSame('2027-01-01', $this->estimate($agreement, $billed)?->periodStart);
    }

    public function test_a_recurring_item_is_listed_on_the_date_it_is_charged_and_skipped_once_sold(): void
    {
        $item = $this->item('monthly', anchorDay: 20, amount: 15000);
        $agreement = $this->agreement(retainerAmount: 800000, items: [$item]);

        $estimate = $this->estimate($agreement);

        $this->assertNotNull($estimate);
        $this->assertCount(2, $estimate->parts);
        $this->assertSame('recurring_item', $estimate->parts[1]['kind']);
        $this->assertSame($item->description, $estimate->parts[1]['description']);
        $this->assertSame(15000, $estimate->parts[1]['amount']);
        $this->assertSame('2026-11-20', $estimate->parts[1]['date']);
        $this->assertSame(815000, $estimate->total());

        $sold = $this->estimate($agreement, [ProjectionInputs::itemKey($item->id, '2026-11-20') => true]);
        $this->assertNotNull($sold);
        $this->assertCount(1, $sold->parts);
        $this->assertSame(800000, $sold->total());
    }

    public function test_an_item_quantity_multiplies_its_price(): void
    {
        $agreement = $this->agreement(items: [$this->item('monthly', amount: 15000, quantity: '3.000')]);

        $this->assertSame(45000, $this->estimate($agreement)?->total());
    }

    public function test_a_cycle_with_nothing_to_sell_is_skipped_rather_than_estimated_at_zero(): void
    {
        // No retainer, and the only item is charged in January.
        $agreement = $this->agreement(items: [$this->item('annual', anchorMonth: 1, anchorDay: 15, amount: 24000)]);

        $estimate = $this->estimate($agreement);

        $this->assertNotNull($estimate);
        $this->assertSame('2027-01-01', $estimate->periodStart);
        $this->assertSame(24000, $estimate->total());
    }

    public function test_an_agreement_with_nothing_scheduled_has_no_estimate(): void
    {
        $this->assertNull($this->estimate($this->agreement()));
    }

    public function test_a_one_time_agreement_has_no_recurring_estimate(): void
    {
        $this->assertNull($this->estimate($this->agreement(cadence: 'one_time', retainerAmount: 800000)));
    }

    public function test_overage_is_hours_times_the_rate_and_is_the_last_part(): void
    {
        $estimate = $this->estimate($this->agreement(retainerAmount: 800000), overageHours: 2.5, hourlyRate: 20000);

        $this->assertNotNull($estimate);
        $last = $estimate->parts[count($estimate->parts) - 1];
        $this->assertSame(['kind' => 'overage', 'description' => 'Hours past the retainer so far', 'amount' => 50000, 'date' => null, 'hours' => 2.5], $last);
        $this->assertSame(850000, $estimate->total());
    }

    public function test_overage_with_no_stated_rate_shows_its_hours_and_adds_nothing(): void
    {
        $estimate = $this->estimate($this->agreement(retainerAmount: 800000), overageHours: 1.25, hourlyRate: null);

        $this->assertNotNull($estimate);
        $last = $estimate->parts[count($estimate->parts) - 1];
        $this->assertNull($last['amount']);
        $this->assertSame(1.25, $last['hours']);
        $this->assertSame(800000, $estimate->total());
    }

    public function test_no_overage_part_is_added_at_zero_hours(): void
    {
        $estimate = $this->estimate($this->agreement(retainerAmount: 800000), overageHours: 0.0, hourlyRate: 20000);

        $this->assertNotNull($estimate);
        $this->assertCount(1, $estimate->parts);
    }

    public function test_overage_alone_does_not_make_an_estimate(): void
    {
        $this->assertNull($this->estimate($this->agreement(), overageHours: 4.0, hourlyRate: 20000));
    }

    public function test_a_quarterly_retainer_is_sold_at_the_start_of_its_cycle(): void
    {
        // Cycles run from 2026-01-01: Jan-Mar, Apr-Jun, Jul-Sep, Oct-Dec.
        $estimate = $this->estimate($this->agreement(cadence: 'quarterly', retainerAmount: 800000));

        $this->assertNotNull($estimate);
        $this->assertSame('2026-10-01', $estimate->periodStart);
        $this->assertSame('2026-12-31', $estimate->periodEnd);
        $this->assertSame(2400000, $estimate->total());
    }

    public function test_a_quarterly_cycle_already_sold_moves_to_the_next(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', retainerAmount: 800000);

        $estimate = $this->estimate($agreement, [ProjectionInputs::retainerKey($agreement->id, '2026-10-01') => true]);

        $this->assertNotNull($estimate);
        $this->assertSame('2027-01-01', $estimate->periodStart);
        $this->assertSame('2027-03-31', $estimate->periodEnd);
    }

    public function test_a_period_fee_override_prices_a_quarterly_cycle(): void
    {
        $estimate = $this->estimate($this->agreement(cadence: 'quarterly', retainerAmount: 800000, periodRetainerAmount: 2000000, retainerMinutes: 6000));

        $this->assertSame(2000000, $estimate?->total());
    }

    public function test_a_quarterly_cycle_carries_each_months_incidence_of_a_monthly_item(): void
    {
        $item = $this->item('monthly', anchorDay: 10, amount: 1000);
        $estimate = $this->estimate($this->agreement(cadence: 'quarterly', retainerAmount: 800000, items: [$item]));

        $this->assertNotNull($estimate);
        $dates = array_column(array_filter($estimate->parts, static fn (array $part): bool => $part['kind'] === 'recurring_item'), 'date');
        $this->assertSame(['2026-10-10', '2026-11-10', '2026-12-10'], array_values($dates));
    }

    public function test_an_agreement_not_yet_started_is_estimated_from_its_start(): void
    {
        $estimate = $this->estimate($this->agreement(startsOn: '2027-02-01', retainerAmount: 800000));

        $this->assertSame('2027-02-01', $estimate?->periodStart);
    }

    public function test_an_agreement_that_has_ended_has_no_estimate(): void
    {
        $this->assertNull($this->estimate($this->agreement(startsOn: '2026-01-01', endsOn: '2026-10-31', retainerAmount: 800000)));
    }

    public function test_the_last_month_is_prorated_and_nothing_follows_it(): void
    {
        $agreement = $this->agreement(endsOn: '2026-11-15', retainerAmount: 800000);

        $estimate = $this->estimate($agreement);

        $this->assertNotNull($estimate);
        // 15 of November's 30 days.
        $this->assertSame(400000, $estimate->total());

        $this->assertNull($this->estimate($agreement, [ProjectionInputs::retainerKey($agreement->id, '2026-11-01') => true]));
    }

    public function test_an_item_is_not_estimated_past_the_agreements_end(): void
    {
        $item = $this->item('monthly', anchorDay: 20, amount: 15000);
        $agreement = $this->agreement(endsOn: '2026-11-15', items: [$item]);

        $this->assertNull($this->estimate($agreement));
    }

    public function test_a_deactivated_item_is_not_estimated(): void
    {
        $agreement = $this->agreement(items: [$this->item('monthly', amount: 15000, active: false)]);

        $this->assertNull($this->estimate($agreement));
    }

    public function test_an_item_with_a_cadence_no_case_names_is_left_out(): void
    {
        $agreement = $this->agreement(retainerAmount: 800000, items: [$this->item('fortnightly', amount: 15000)]);

        $this->assertSame(800000, $this->estimate($agreement)?->total());
    }

    public function test_the_retainer_agrees_with_the_revenue_projector(): void
    {
        // Each is read on a day whose coming cycle starts inside the
        // projector's window, which opens on the first of the current month.
        foreach ([
            ['2026-11-06', $this->agreement(retainerAmount: 800000)],
            ['2026-11-06', $this->agreement(startsOn: '2026-11-15', retainerAmount: 800000)],
            ['2026-11-06', $this->agreement(startsOn: '2026-11-15', retainerAmount: 800000, proration: 'full_period')],
            ['2026-10-06', $this->agreement(cadence: 'quarterly', retainerAmount: 800000)],
            ['2026-08-20', $this->agreement(cadence: 'quarterly', retainerAmount: 800000, startsOn: '2026-08-12')],
            ['2026-03-20', $this->agreement(cadence: 'annual', retainerAmount: 800000, startsOn: '2026-03-01')],
            ['2026-10-06', $this->agreement(cadence: 'quarterly', retainerAmount: 800000, periodRetainerAmount: 2000000, retainerMinutes: 6000)],
        ] as [$today, $agreement]) {
            $day = CarbonImmutable::parse($today);
            $estimate = (new NextInvoiceEstimator)->estimate($agreement, $day, [], 0.0, null);
            $this->assertNotNull($estimate);

            $projection = (new RecurringRevenueProjector)->project(new ProjectionInputs($day, [$agreement]));
            $expected = $projection->agreements[0]->months[substr($estimate->periodStart, 0, 7)]['retainer'];

            $this->assertSame($expected, $estimate->parts[0]['amount'], $agreement->billingCadence.' from '.$agreement->startsOn->toDateString().' read '.$today);
        }
    }

    public function test_overage_is_priced_to_the_nearest_minor_unit_and_its_hours_to_two_places(): void
    {
        $agreement = $this->agreement(retainerAmount: 800000);

        // 133.3 rounds down, 2.5 rounds up: neither floor nor ceil agrees with both.
        $this->assertSame(133, $this->estimate($agreement, overageHours: 1.333, hourlyRate: 100)?->parts[1]['amount']);
        $this->assertSame(3, $this->estimate($agreement, overageHours: 2.5, hourlyRate: 1)?->parts[1]['amount']);
        $this->assertSame(1.23, $this->estimate($agreement, overageHours: 1.2345, hourlyRate: 100)?->parts[1]['hours']);
        $this->assertSame(1.24, $this->estimate($agreement, overageHours: 1.2351, hourlyRate: 100)?->parts[1]['hours']);
    }

    public function test_four_cycles_unsold_is_still_no_estimate_beyond_the_lookahead(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', retainerAmount: 800000);
        $billed = [];
        foreach (['2026-10-01', '2027-01-01', '2027-04-01'] as $date) {
            $billed[ProjectionInputs::retainerKey($agreement->id, $date)] = true;
        }

        // The fourth cycle (July 2027) is unsold but is not looked at.
        $this->assertNull($this->estimate($agreement, $billed));
    }

    public function test_a_sold_incidence_is_skipped_and_the_ones_after_it_are_kept(): void
    {
        $item = $this->item('monthly', anchorDay: 10, amount: 1000);
        $agreement = $this->agreement(cadence: 'quarterly', retainerAmount: 800000, items: [$item]);

        $estimate = $this->estimate($agreement, [ProjectionInputs::itemKey($item->id, '2026-10-10') => true]);

        $this->assertNotNull($estimate);
        $dates = array_values(array_column(array_filter($estimate->parts, static fn (array $part): bool => $part['kind'] === 'recurring_item'), 'date'));
        $this->assertSame(['2026-11-10', '2026-12-10'], $dates);
    }

    public function test_a_cycle_priced_from_granted_hours_agrees_with_the_projector_on_every_start_day(): void
    {
        // Large amounts so a digit lost in the multiplier shows in the price.
        foreach (['quarterly', 'semi_annual', 'annual'] as $cadence) {
            foreach (range(1, 28) as $day) {
                $startsOn = sprintf('2026-08-%02d', $day);
                $agreement = $this->agreement(cadence: $cadence, startsOn: $startsOn, retainerAmount: 99999937, retainerMinutes: 7777);
                $today = CarbonImmutable::parse($startsOn);
                $estimate = (new NextInvoiceEstimator)->estimate($agreement, $today, [], 0.0, null);
                $this->assertNotNull($estimate);

                $expected = (new RecurringRevenueProjector)->project(new ProjectionInputs($today, [$agreement]))->agreements[0]->months[substr($estimate->periodStart, 0, 7)]['retainer'];

                $this->assertSame($expected, $estimate->parts[0]['amount'], "$cadence from $startsOn");
            }
        }
    }

    public function test_prices_agree_with_the_projector_across_many_amounts_and_hour_grants(): void
    {
        $day = CarbonImmutable::parse('2026-11-06');
        $cases = [];
        foreach (range(1, 300) as $amount) {
            $cases[] = ['monthly', '2026-01-01', $amount, null];
            $cases[] = ['monthly', '2026-11-15', $amount * 7, null];
            $cases[] = ['quarterly', '2026-01-01', $amount, null];
        }
        foreach ([7777, 6001, 9999, 10007, 3333, 1, 7, 11, 13, 17, 19, 23] as $minutes) {
            foreach ([99999937, 12345679, 7] as $amount) {
                foreach (range(1, 28) as $start) {
                    $cases[] = ['quarterly', sprintf('2026-08-%02d', $start), $amount, $minutes];
                }
            }
        }

        foreach ($cases as [$cadence, $startsOn, $amount, $minutes]) {
            $agreement = $this->agreement(cadence: $cadence, startsOn: $startsOn, retainerAmount: $amount, retainerMinutes: $minutes);
            $today = $minutes === null ? $day : CarbonImmutable::parse($startsOn);
            $estimate = (new NextInvoiceEstimator)->estimate($agreement, $today, [], 0.0, null);
            $this->assertNotNull($estimate);
            $expected = (new RecurringRevenueProjector)->project(new ProjectionInputs($today, [$agreement]))->agreements[0]->months[substr($estimate->periodStart, 0, 7)]['retainer'] ?? null;
            if ($expected === null) {
                continue;
            }

            $this->assertSame($expected, $estimate->parts[0]['amount'] ?? 0, "$cadence $startsOn $amount $minutes");
        }
    }

    public function test_a_cent_that_floats_just_under_a_whole_number_is_not_truncated(): void
    {
        // 0.29 * 100 is 28.999999999999996: only rounding gives 29 minor units.
        foreach ([29, 57, 58, 113, 114, 115, 116, 201] as $cents) {
            $estimate = $this->estimate($this->agreement(cadence: 'quarterly', periodRetainerAmount: $cents, retainerAmount: 1));

            $this->assertSame($cents, $estimate?->parts[0]['amount'], (string) $cents);
        }
    }

    public function test_a_monthly_retainer_agrees_with_the_projector_on_every_start_day(): void
    {
        foreach (range(1, 28) as $day) {
            $startsOn = sprintf('2026-11-%02d', $day);
            $agreement = $this->agreement(startsOn: $startsOn, retainerAmount: 99999937);
            $today = CarbonImmutable::parse('2026-11-01');
            $estimate = (new NextInvoiceEstimator)->estimate($agreement, $today, [], 0.0, null);
            $expected = (new RecurringRevenueProjector)->project(new ProjectionInputs($today, [$agreement]))->agreements[0]->months['2026-11']['retainer'];

            $this->assertSame($expected, $estimate?->parts[0]['amount'], $startsOn);
        }
    }

    /** @param array<string, true> $billed */
    private function estimate(ProjectedAgreement $agreement, array $billed = [], float $overageHours = 0.0, ?int $hourlyRate = null): ?NextInvoiceEstimate
    {
        return (new NextInvoiceEstimator)->estimate($agreement, CarbonImmutable::parse(self::TODAY), $billed, $overageHours, $hourlyRate);
    }

    /** @param list<ProjectedRecurringItem> $items */
    private function agreement(
        string $cadence = 'monthly',
        string $startsOn = '2026-01-01',
        ?string $endsOn = null,
        ?int $retainerAmount = null,
        ?int $retainerMinutes = null,
        ?int $periodRetainerAmount = null,
        ?string $proration = null,
        array $items = [],
    ): ProjectedAgreement {
        return new ProjectedAgreement(
            id: 1,
            publicId: 'agreement-1',
            workspaceId: 1,
            clientId: 10,
            clientPublicId: 'client-10',
            clientName: 'Synthetic client',
            title: 'Synthetic retainer',
            status: 'active',
            currency: 'USD',
            billingCadence: $cadence,
            firstCycleProration: $proration,
            startsOn: CarbonImmutable::parse($startsOn),
            endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            terminatedAt: null,
            signedAt: null,
            retainerMinutes: $retainerMinutes,
            retainerAmount: $retainerAmount,
            periodRetainerMinutes: null,
            periodRetainerAmount: $periodRetainerAmount,
            recurringItems: $items,
        );
    }

    private function item(string $cadence, ?int $anchorMonth = null, int $anchorDay = 1, int $amount = 1000, ?string $quantity = '1.000', bool $active = true): ProjectedRecurringItem
    {
        $id = $this->nextItemId++;

        return new ProjectedRecurringItem(
            id: $id,
            publicId: 'item-'.$id,
            description: 'Synthetic item '.$id,
            cadence: $cadence,
            anchorMonth: $anchorMonth,
            anchorDay: $anchorDay,
            effectiveOn: null,
            expiresOn: null,
            amount: $amount,
            quantity: $quantity,
            isTaxable: false,
            isActive: $active,
        );
    }
}
