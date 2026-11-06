<?php

namespace Tests\Unit\Revenue;

use App\Services\Revenue\RecurringRevenueProjector;
use App\Support\Revenue\AgreementProjection;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedOverage;
use App\Support\Revenue\ProjectedRecurringItem;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueProjection;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The projector is pure: every case here builds its inputs in memory, with no
 * database, and reads the billing services' own placement and pricing rules
 * through it. As of 2026-11-06 the window is November 2026 through November
 * 2027.
 */
final class RecurringRevenueProjectorTest extends TestCase
{
    private const string AS_OF = '2026-11-06';

    private int $nextItemId = 100;

    public function test_the_window_is_the_current_month_and_the_twelve_full_months_after_it(): void
    {
        $projection = $this->project([$this->agreement()]);

        $this->assertSame('2026-11', $projection->months[0]);
        $this->assertSame('2027-11', $projection->months[12]);
        $this->assertCount(13, $projection->months);
    }

    public function test_a_monthly_retainer_is_contracted_in_every_month_it_is_in_force(): void
    {
        $months = $this->months($this->project([$this->agreement(retainerAmount: 100000)])->agreements[0]);

        $this->assertSame(array_fill(0, 13, 100000), array_values(array_column($months, 'retainer')));
    }

    public function test_a_charge_already_on_a_charged_invoice_is_not_counted_again(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000);
        $projection = (new RecurringRevenueProjector)->project(new ProjectionInputs(
            CarbonImmutable::parse(self::AS_OF),
            [$agreement],
            billedCharges: [ProjectionInputs::retainerKey($agreement->id, '2026-11-01') => true],
        ));

        $months = $this->months($projection->agreements[0]);
        $this->assertSame(0, $months['2026-11']['retainer']);
        $this->assertSame(100000, $months['2026-12']['retainer']);
    }

    public function test_a_signed_agreement_not_yet_started_counts_only_from_its_start_prorated(): void
    {
        $months = $this->months($this->project([$this->agreement(startsOn: '2027-02-15', retainerAmount: 100000, signedAt: '2026-10-20')])->agreements[0]);

        foreach (['2026-11', '2026-12', '2027-01'] as $before) {
            $this->assertSame(0, $months[$before]['retainer'], $before);
        }
        // 14 of February's 28 days.
        $this->assertSame(50000, $months['2027-02']['retainer']);
        $this->assertSame(100000, $months['2027-03']['retainer']);
    }

    public function test_full_period_proration_charges_the_whole_opening_month(): void
    {
        $months = $this->months($this->project([$this->agreement(startsOn: '2027-02-15', retainerAmount: 100000, proration: 'full_period')])->agreements[0]);

        $this->assertSame(100000, $months['2027-02']['retainer']);
    }

    public function test_an_end_date_prorates_the_last_month_and_stops_everything_after_it(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000, endsOn: '2027-03-15', items: [$this->item('monthly', anchorDay: 20, amount: 5000)]);
        $months = $this->months($this->project([$agreement], [$agreement->id => $this->steadyHistory(30000)])->agreements[0]);

        // 15 of March's 31 days.
        $this->assertSame(48390, $months['2027-03']['retainer']);
        // The 20 March charge falls after the end.
        $this->assertSame(0, $months['2027-03']['recurring_items']);
        $this->assertSame(5000, $months['2027-02']['recurring_items']);
        $this->assertSame(30000, $months['2027-03']['projected_overage']);
        foreach (['2027-04', '2027-08', '2027-11'] as $after) {
            $this->assertSame(['retainer' => 0, 'recurring_items' => 0, 'projected_overage' => 0], $months[$after], $after);
        }
    }

    public function test_a_terminated_agreement_projects_nothing_after_its_end_and_assumes_no_renewal(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000, status: 'terminated', endsOn: '2026-12-31');
        $months = $this->months($this->project([$agreement])->agreements[0]);

        $this->assertSame(100000, $months['2026-12']['retainer']);
        $this->assertSame(0, array_sum(array_column(array_slice($months, 2), 'retainer')));
    }

    public function test_a_terminated_agreement_with_no_end_date_ended_when_it_was_terminated(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000, status: 'terminated', terminatedAt: '2026-10-31');

        $this->assertSame(0, $this->project([$agreement])->agreements[0]->contractedTotal());
        $this->assertSame('2026-10-31', $agreement->effectiveEnd()?->toDateString());
    }

    public function test_a_quarterly_retainer_is_placed_on_the_first_day_of_each_cycle(): void
    {
        $months = $this->months($this->project([$this->agreement(cadence: 'quarterly', retainerAmount: 100000, retainerMinutes: 600)])->agreements[0]);

        $charged = array_filter(array_map(static fn (array $month): int => $month['retainer'], $months));
        $this->assertSame(['2027-01' => 300000, '2027-04' => 300000, '2027-07' => 300000, '2027-10' => 300000], $charged);
    }

    public function test_a_period_fee_override_is_the_cycle_fee(): void
    {
        $months = $this->months($this->project([$this->agreement(cadence: 'semi_annual', startsOn: '2026-03-01', periodRetainerAmount: 540000)])->agreements[0]);

        $charged = array_filter(array_map(static fn (array $month): int => $month['retainer'], $months));
        $this->assertSame(['2027-03' => 540000, '2027-09' => 540000], $charged);
    }

    public function test_an_annual_cycle_cut_short_by_its_end_is_prorated(): void
    {
        $months = $this->months($this->project([$this->agreement(cadence: 'annual', startsOn: '2026-01-01', endsOn: '2027-06-30', periodRetainerAmount: 1200000)])->agreements[0]);

        // 181 of 365 days of the 2027 cycle.
        $this->assertSame((int) round(12000 * 181 / 365 * 100), $months['2027-01']['retainer']);
    }

    public function test_recurring_items_are_placed_at_their_own_cadence(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', items: [
            $monthly = $this->item('monthly', anchorDay: 15, amount: 1000),
            $quarterly = $this->item('quarterly', anchorMonth: 1, anchorDay: 1, amount: 3000),
            $semiAnnual = $this->item('semi_annual', anchorMonth: 3, anchorDay: 10, amount: 6000),
            $annual = $this->item('annual', anchorMonth: 6, anchorDay: 1, amount: 12000),
            $oneTime = $this->item('one_time', effectiveOn: '2027-02-10', amount: 50000),
            $this->item('one_time', effectiveOn: '2026-05-01', amount: 70000),
        ]);
        $projection = $this->project([$agreement])->agreements[0];
        $months = $this->months($projection);

        $this->assertSame(1000 + 3000, $months['2027-01']['recurring_items']);
        $this->assertSame(1000 + 50000, $months['2027-02']['recurring_items']);
        $this->assertSame(1000 + 6000, $months['2027-03']['recurring_items']);
        $this->assertSame(1000 + 3000, $months['2027-04']['recurring_items']);
        $this->assertSame(1000 + 12000, $months['2027-06']['recurring_items']);
        $this->assertSame(1000 + 6000, $months['2027-09']['recurring_items']);
        $this->assertSame(1000, $months['2026-12']['recurring_items']);
        $this->assertSame('2026-11-15', $projection->nextChargeOn[$monthly->id]);
        $this->assertSame('2027-01-01', $projection->nextChargeOn[$quarterly->id]);
        $this->assertSame('2027-03-10', $projection->nextChargeOn[$semiAnnual->id]);
        $this->assertSame('2027-06-01', $projection->nextChargeOn[$annual->id]);
        $this->assertSame('2027-02-10', $projection->nextChargeOn[$oneTime->id]);
        // The one-time charge before the window counts nowhere.
        $this->assertSame(13 * 1000 + 4 * 3000 + 2 * 6000 + 12000 + 50000, array_sum(array_column($months, 'recurring_items')));
    }

    public function test_an_item_stops_after_it_expires_and_a_deactivated_item_never_charges(): void
    {
        $agreement = $this->agreement(items: [
            $this->item('monthly', anchorDay: 1, amount: 2000, expiresOn: '2027-03-31'),
            $this->item('monthly', anchorDay: 1, amount: 9000, active: false),
        ]);
        $months = $this->months($this->project([$agreement])->agreements[0]);

        $this->assertSame(2000, $months['2027-03']['recurring_items']);
        $this->assertSame(0, $months['2027-04']['recurring_items']);
        $this->assertSame(5 * 2000, array_sum(array_column($months, 'recurring_items')));
    }

    public function test_an_already_charged_item_incidence_is_not_counted_again(): void
    {
        $agreement = $this->agreement(items: [$item = $this->item('monthly', anchorDay: 1, amount: 2000)]);
        $projection = (new RecurringRevenueProjector)->project(new ProjectionInputs(
            CarbonImmutable::parse(self::AS_OF),
            [$agreement],
            billedCharges: [ProjectionInputs::itemKey($item->id, '2026-11-01') => true],
        ))->agreements[0];

        $this->assertSame(0, $this->months($projection)['2026-11']['recurring_items']);
        $this->assertSame('2026-12-01', $projection->nextChargeOn[$item->id]);
    }

    public function test_an_item_with_an_unrecognised_cadence_is_left_out_rather_than_refused(): void
    {
        $agreement = $this->agreement(items: [$this->item('fortnightly', amount: 2000), $this->item('monthly', amount: 100)]);

        $this->assertSame(13 * 100, $this->project([$agreement])->agreements[0]->contractedTotal());
    }

    public function test_tax_is_excluded_and_quantity_is_included(): void
    {
        $agreement = $this->agreement(items: [$this->item('monthly', amount: 2500, quantity: '2.000', taxable: true)]);
        $months = $this->months($this->project([$agreement])->agreements[0]);

        $this->assertSame(5000, $months['2026-11']['recurring_items']);
    }

    public function test_overage_is_the_trailing_six_month_average_carried_forward_flat(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000);
        $history = ['2026-05' => 60000, '2026-06' => 0, '2026-07' => 30000, '2026-08' => 30000, '2026-09' => 0, '2026-10' => 60000, '2026-04' => 999999];
        $projection = $this->project([$agreement], [$agreement->id => $history]);

        $overage = $projection->agreements[0]->overage;
        $this->assertSame([6, 180000, 30000, ProjectedOverage::PROJECTED], [$overage->historyMonths, $overage->billedInWindow, $overage->monthlyAverage, $overage->status]);
        $this->assertSame(array_fill(0, 13, 30000), array_values(array_column($this->months($projection->agreements[0]), 'projected_overage')));
        $this->assertSame(1300000, $projection->agreements[0]->contractedTotal());
    }

    public function test_an_agreement_in_force_for_fewer_than_three_months_projects_no_overage(): void
    {
        $agreement = $this->agreement(startsOn: '2026-09-01', retainerAmount: 100000);
        $projection = $this->project([$agreement], [$agreement->id => ['2026-09' => 40000, '2026-10' => 40000]]);

        $overage = $projection->agreements[0]->overage;
        $this->assertSame([2, 80000, 0, ProjectedOverage::INSUFFICIENT_HISTORY], [$overage->historyMonths, $overage->billedInWindow, $overage->monthlyAverage, $overage->status]);
        $this->assertSame(0, $projection->agreements[0]->projectedOverageTotal());
    }

    public function test_three_months_in_force_is_enough_and_averages_over_those_months(): void
    {
        $agreement = $this->agreement(startsOn: '2026-08-20', retainerAmount: 100000);
        $overage = $this->project([$agreement], [$agreement->id => ['2026-08' => 10000, '2026-10' => 50000]])->agreements[0]->overage;

        $this->assertSame([3, 20000, ProjectedOverage::PROJECTED], [$overage->historyMonths, $overage->monthlyAverage, $overage->status]);
    }

    public function test_a_one_time_agreement_is_never_projected(): void
    {
        $projection = $this->project([$this->agreement(cadence: 'one_time', retainerAmount: 100000)]);

        $this->assertSame([], $projection->agreements);
        $this->assertSame([], $projection->rows);
    }

    public function test_rows_are_one_per_client_per_month_summed_over_its_agreements(): void
    {
        $first = $this->agreement(id: 1, retainerAmount: 100000);
        $second = $this->agreement(id: 2, retainerAmount: 50000, items: [$this->item('monthly', amount: 700)]);
        $other = $this->agreement(id: 3, clientId: 20, retainerAmount: 10000);
        $projection = $this->project([$first, $second, $other], [1 => $this->steadyHistory(6000)]);

        $this->assertCount(2 * 13, $projection->rows);
        $this->assertSame(150700, $projection->contracted('2027-05', 10));
        $this->assertSame(6000, $projection->projectedOverage('2027-05', 10));
        $this->assertSame(160700, $projection->contracted('2027-05'));
        $this->assertCount(2, $projection->rowsFor('2027-05'));
        $row = $projection->rowsFor('2027-05')[0];
        $this->assertSame([150000, 700, 150700], [$row->contractedRetainer, $row->contractedRecurringItems, $row->contracted()]);
    }

    public function test_monthly_recurring_normalises_each_cadence_and_excludes_one_time(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', periodRetainerAmount: 300000, items: [
            $this->item('monthly', amount: 1000),
            $this->item('quarterly', amount: 3000),
            $this->item('semi_annual', amount: 6000),
            $this->item('annual', amount: 12000),
            $this->item('one_time', amount: 99000),
        ]);

        $this->assertSame(100000 + 1000 + 1000 + 1000 + 1000, $agreement->monthlyRecurringOn(CarbonImmutable::parse(self::AS_OF)));
        $this->assertSame(0, $this->agreement(startsOn: '2027-01-01', retainerAmount: 100000)->monthlyRecurringOn(CarbonImmutable::parse(self::AS_OF)));
    }

    public function test_an_agreement_after_a_one_time_one_is_still_projected(): void
    {
        $projection = $this->project([$this->agreement(id: 1, cadence: 'one_time', retainerAmount: 9000), $this->agreement(id: 2, retainerAmount: 100000)]);

        $this->assertSame(['agreement-2'], array_map(static fn (AgreementProjection $row): string => $row->agreement->publicId, $projection->agreements));
        $this->assertCount(13, $projection->rows);
        $this->assertSame(100000, $projection->contracted('2027-05'));
    }

    public function test_every_item_has_a_next_charge_date_or_null(): void
    {
        $agreement = $this->agreement(items: [$monthly = $this->item('monthly', anchorDay: 3), $past = $this->item('one_time', effectiveOn: '2026-05-01')]);

        $this->assertSame([$monthly->id => '2026-11-03', $past->id => null], $this->project([$agreement])->agreements[0]->nextChargeOn);
    }

    public function test_a_prorated_month_is_priced_to_the_cent_then_to_minor_units(): void
    {
        // 1000.03 x 17/31 (0.5484) = 548.4165: 548.42, not 548.4.
        $this->assertSame(54842, $this->months($this->project([$this->agreement(retainerAmount: 100003, endsOn: '2027-03-17')])->agreements[0])['2027-03']['retainer']);
        // 1000 x 5/31 (0.1613) = 161.30 exactly, whatever the binary representation.
        $this->assertSame(16130, $this->months($this->project([$this->agreement(retainerAmount: 100000, endsOn: '2027-03-05')])->agreements[0])['2027-03']['retainer']);
        // 1000.01 x 14/31 (0.4516) = 451.6045: 451.60, where rounding to tenths of a cent first would give 451.61.
        $this->assertSame(45160, $this->months($this->project([$this->agreement(retainerAmount: 100001, endsOn: '2027-03-14')])->agreements[0])['2027-03']['retainer']);
    }

    public function test_a_cadence_cycle_without_overrides_is_priced_by_the_hours_its_months_grant(): void
    {
        // 7.5 hours a month, ending 1 February: January's 7.5 and one day of February's, over 7.5 = 1.0357.
        $this->assertSame(103570, $this->months($this->project([$this->agreement(cadence: 'quarterly', retainerAmount: 100000, retainerMinutes: 450, endsOn: '2027-02-01')])->agreements[0])['2027-01']['retainer']);
        // No monthly hours: the cycle is priced by its month count.
        $this->assertSame(300000, $this->months($this->project([$this->agreement(cadence: 'quarterly', retainerAmount: 100000)])->agreements[0])['2027-01']['retainer']);
    }

    public function test_a_monthly_agreement_sells_items_from_the_first_of_its_opening_month(): void
    {
        $agreement = $this->agreement(startsOn: '2026-12-15', items: [$this->item('monthly', anchorDay: 1, amount: 700, effectiveOn: '2026-12-01')]);

        $this->assertSame(700, $this->months($this->project([$agreement])->agreements[0])['2026-12']['recurring_items']);
    }

    public function test_a_cadence_agreement_sells_items_from_its_start_day(): void
    {
        $agreement = $this->agreement(cadence: 'quarterly', startsOn: '2027-01-15', items: [$this->item('monthly', anchorDay: 1, amount: 700, effectiveOn: '2027-01-01')]);
        $months = $this->months($this->project([$agreement])->agreements[0]);

        $this->assertSame([0, 700], [$months['2027-01']['recurring_items'], $months['2027-02']['recurring_items']]);
    }

    public function test_a_monthly_row_reports_its_client_and_amounts(): void
    {
        $agreement = $this->agreement(retainerAmount: 100000, items: [$this->item('monthly', amount: 700)]);
        $row = $this->project([$agreement], [$agreement->id => $this->steadyHistory(6000)])->rowsFor('2027-02')[0];

        $this->assertSame(['month' => '2027-02', 'client_id' => 'client-10', 'client_name' => 'Synthetic client 10', 'contracted' => 100700, 'contracted_retainer' => 100000, 'contracted_recurring_items' => 700, 'projected_overage' => 6000], $row->toArray());
        $this->assertSame(10, $row->clientId);
    }

    /**
     * @param  list<ProjectedAgreement>  $agreements
     * @param  array<int, array<string, int>>  $history
     */
    private function project(array $agreements, array $history = []): RevenueProjection
    {
        return (new RecurringRevenueProjector)->project(new ProjectionInputs(CarbonImmutable::parse(self::AS_OF), $agreements, $history));
    }

    /** @return array<string, array{retainer: int, recurring_items: int, projected_overage: int}> */
    private function months(AgreementProjection $projection): array
    {
        return $projection->months;
    }

    /** @return array<string, int> */
    private function steadyHistory(int $amount): array
    {
        return array_fill_keys(['2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], $amount);
    }

    /** @param list<ProjectedRecurringItem> $items */
    private function agreement(
        int $id = 1,
        int $clientId = 10,
        string $cadence = 'monthly',
        string $startsOn = '2026-01-01',
        ?string $endsOn = null,
        string $status = 'active',
        ?int $retainerAmount = null,
        ?int $retainerMinutes = null,
        ?int $periodRetainerAmount = null,
        ?string $proration = null,
        ?string $signedAt = null,
        ?string $terminatedAt = null,
        array $items = [],
    ): ProjectedAgreement {
        return new ProjectedAgreement(
            id: $id,
            publicId: 'agreement-'.$id,
            workspaceId: 1,
            clientId: $clientId,
            clientPublicId: 'client-'.$clientId,
            clientName: 'Synthetic client '.$clientId,
            title: 'Synthetic retainer '.$id,
            status: $status,
            currency: 'USD',
            billingCadence: $cadence,
            firstCycleProration: $proration,
            startsOn: CarbonImmutable::parse($startsOn),
            endsOn: $endsOn === null ? null : CarbonImmutable::parse($endsOn),
            terminatedAt: $terminatedAt === null ? null : CarbonImmutable::parse($terminatedAt),
            signedAt: $signedAt === null ? null : CarbonImmutable::parse($signedAt),
            retainerMinutes: $retainerMinutes,
            retainerAmount: $retainerAmount,
            periodRetainerMinutes: null,
            periodRetainerAmount: $periodRetainerAmount,
            recurringItems: $items,
        );
    }

    private function item(
        string $cadence,
        ?int $anchorMonth = null,
        int $anchorDay = 1,
        int $amount = 1000,
        ?string $effectiveOn = null,
        ?string $expiresOn = null,
        ?string $quantity = '1.000',
        bool $taxable = false,
        bool $active = true,
    ): ProjectedRecurringItem {
        $id = $this->nextItemId++;

        return new ProjectedRecurringItem(
            id: $id,
            publicId: 'item-'.$id,
            description: 'Synthetic item '.$id,
            cadence: $cadence,
            anchorMonth: $anchorMonth,
            anchorDay: $anchorDay,
            effectiveOn: $effectiveOn === null ? null : CarbonImmutable::parse($effectiveOn),
            expiresOn: $expiresOn === null ? null : CarbonImmutable::parse($expiresOn),
            amount: $amount,
            quantity: $quantity,
            isTaxable: $taxable,
            isActive: $active,
        );
    }
}
