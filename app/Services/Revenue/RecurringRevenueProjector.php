<?php

declare(strict_types=1);

namespace App\Services\Revenue;

use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RecurringItemBiller;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\BillingCadence;
use App\Support\Revenue\AgreementProjection;
use App\Support\Revenue\MonthlyProjection;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectedOverage;
use App\Support\Revenue\ProjectionInputs;
use App\Support\Revenue\RevenueAssumptions;
use App\Support\Revenue\RevenueProjection;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * The contracted and projected-overage revenue of the current month and the
 * twelve full months after it (docs/ux-overhaul.md section 2, "Projection
 * model"). Pure: everything comes from {@see ProjectionInputs}, and the
 * billing rules come from the services that bill, not a copy of them.
 *
 * - Retainers are priced by {@see RetainerCalculator} on the cycles
 *   {@see BillingCycleResolver} yields, honouring first-cycle proration,
 *   cadence and the end date, and placed on the day the cycle they sell
 *   begins - the date the generator gives the retainer line. A monthly
 *   agreement sells each calendar month on its first day, prorated as the
 *   monthly generator prorates it.
 * - Recurring items are placed by {@see RecurringItemBiller} on the days they
 *   are charged, at their own `ChargeCadence`, anchor, effective and expiry
 *   dates. A `one_time` item counts only if its charge date falls in the
 *   window.
 * - Nothing is projected before an agreement starts or after it ends, and no
 *   renewal is assumed.
 * - A charge a charged invoice already carries is actual revenue and is left
 *   out, so a cycle sold in advance is never counted twice.
 * - Overage is the trailing average of billed overage amount over the months
 *   the agreement was in force in the window, carried forward flat while it
 *   is in force; below the minimum history it is zero.
 * - Amounts are minor units before tax. Expenses are never projected.
 */
final class RecurringRevenueProjector
{
    public function __construct(
        private readonly RetainerCalculator $retainers = new RetainerCalculator,
        private readonly BillingCycleResolver $cycles = new BillingCycleResolver,
        private readonly RecurringItemBiller $items = new RecurringItemBiller,
    ) {}

    public function project(ProjectionInputs $inputs): RevenueProjection
    {
        $months = self::windowMonths($inputs);
        $agreements = [];
        /** @var array<int, array{agreement: ProjectedAgreement, months: array<string, array{retainer: int, recurring_items: int, projected_overage: int}>}> $byClient */
        $byClient = [];

        foreach ($inputs->agreements as $agreement) {
            // A one-time arrangement has no cycle; the generator never bills it on a cadence.
            if (! $agreement->billsOnARecurringCadence()) {
                continue;
            }
            $projection = $this->projectAgreement($agreement, $inputs, $months);
            $agreements[] = $projection;

            $byClient[$agreement->clientId] ??= ['agreement' => $agreement, 'months' => self::emptyMonths($months)];
            $byClient[$agreement->clientId]['months'] = self::addMonths($byClient[$agreement->clientId]['months'], $projection->months);
        }

        $rows = [];
        foreach ($months as $month) {
            foreach ($byClient as $clientId => $client) {
                $amounts = $client['months'][$month];
                $rows[] = new MonthlyProjection(
                    month: $month,
                    clientId: $clientId,
                    clientPublicId: $client['agreement']->clientPublicId,
                    clientName: $client['agreement']->clientName,
                    contractedRetainer: $amounts['retainer'],
                    contractedRecurringItems: $amounts['recurring_items'],
                    projectedOverage: $amounts['projected_overage'],
                );
            }
        }

        return new RevenueProjection($months, $rows, $agreements);
    }

    /** The overage method for one agreement. */
    private function overageFor(ProjectedAgreement $agreement, ProjectionInputs $inputs): ProjectedOverage
    {
        $history = $inputs->overageHistory[$agreement->id] ?? [];
        $historyMonths = 0;
        $billed = 0;
        $windowStart = $inputs->windowStart();

        for ($back = 1; $back <= RevenueAssumptions::OVERAGE_WINDOW_MONTHS; $back++) {
            $monthStart = $windowStart->subMonthsNoOverflow($back);
            $billed += $history[$monthStart->format('Y-m')] ?? 0;
            if ($agreement->coversAnyOf($monthStart, $monthStart->endOfMonth()->startOfDay())) {
                $historyMonths++;
            }
        }

        return ProjectedOverage::fromHistory($historyMonths, $billed);
    }

    /** @return list<string> the current month and the twelve after it, as `Y-m` */
    private static function windowMonths(ProjectionInputs $inputs): array
    {
        $months = [];
        for ($offset = 0; $offset <= RevenueAssumptions::PROJECTION_MONTHS; $offset++) {
            $months[] = $inputs->windowStart()->addMonthsNoOverflow($offset)->format('Y-m');
        }

        return $months;
    }

    /** @param list<string> $months */
    private function projectAgreement(ProjectedAgreement $agreement, ProjectionInputs $inputs, array $months): AgreementProjection
    {
        $amounts = self::emptyMonths($months);

        foreach ($this->retainerCharges($agreement, $inputs) as [$date, $amount]) {
            $month = $date->format('Y-m');
            if (isset($amounts[$month]) && ! $inputs->isBilled(ProjectionInputs::retainerKey($agreement->id, $date->toDateString()))) {
                // @infection-ignore-all Equivalent: a retainer is sold at most once a month - one charge per calendar month for a monthly agreement, one per cycle start otherwise - so adding and assigning agree.
                $amounts[$month]['retainer'] += $amount;
            }
        }

        $nextChargeOn = [];
        foreach ($agreement->recurringItems as $item) {
            $nextChargeOn[$item->id] = null;
        }
        foreach ($this->itemCharges($agreement, $inputs) as [$itemId, $date, $amount]) {
            $month = $date->format('Y-m');
            if (! isset($amounts[$month]) || $inputs->isBilled(ProjectionInputs::itemKey($itemId, $date->toDateString()))) {
                continue;
            }
            $amounts[$month]['recurring_items'] += $amount;
            $nextChargeOn[$itemId] ??= $date->toDateString();
        }

        $overage = $this->overageFor($agreement, $inputs);
        foreach ($amounts as $month => $monthAmounts) {
            $monthStart = CarbonImmutable::createFromFormat('!Y-m', $month);
            if ($monthStart instanceof CarbonImmutable && $agreement->coversAnyOf($monthStart, $monthStart->endOfMonth()->startOfDay())) {
                $amounts[$month] = [...$monthAmounts, 'projected_overage' => $monthAmounts['projected_overage'] + $overage->monthlyAverage];
            }
        }

        return new AgreementProjection($agreement, $overage, $amounts, $nextChargeOn, $agreement->monthlyRecurringOn($inputs->asOf));
    }

    /**
     * Each retainer charge whose date falls in the window: [date, minor units].
     *
     * @return iterable<array{CarbonImmutable, int}>
     */
    private function retainerCharges(ProjectedAgreement $agreement, ProjectionInputs $inputs): iterable
    {
        $windowStart = $inputs->windowStart();
        $windowEnd = $inputs->windowEnd();

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            // The monthly generator sells each calendar month on its first day,
            // at the period fee times the month's covered fraction.
            for ($month = $windowStart; $month->lte($windowEnd); $month = $month->addMonthNoOverflow()) {
                $multiplier = $this->retainers->monthRetainerMultiplier(
                    $agreement,
                    Carbon::instance($month),
                    Carbon::instance($month->endOfMonth()->startOfDay()),
                );
                // Priced as the monthly generator prices it: the fee rounded to cents, then to minor units.
                yield [$month, self::minor(round($agreement->periodRetainerFee() * $multiplier, 2))];
            }

            return;
        }

        // Look past the window so the last cycle it holds is not clipped by
        // the ceiling: only a real end date shortens a cycle's entitlement.
        $through = Carbon::instance($windowEnd)->addMonthsNoOverflow($agreement->effectiveBillingCadence()->monthsInCycle());
        foreach ($this->cycles->cyclesForAgreement($agreement, $through) as $cycle) {
            $start = CarbonImmutable::instance($cycle->start)->startOfDay();
            if ($start->gt($windowEnd)) {
                // @infection-ignore-all Equivalent: the cycles ascend, so every later one is also past the window and continuing yields nothing more.
                break;
            }
            if ($start->lt($windowStart)) {
                continue;
            }
            yield [$start, self::minor($this->retainers->cycleRetainerFee($agreement, $cycle, ['retainer_multiplier' => $this->legacyCycleMultiplier($agreement, $cycle)]))];
        }
    }

    /**
     * The multiplier the cadence generator's ledger summary hands the fee
     * when the agreement overrides neither period figure: the months' granted
     * hours over a month's retainer, or the month count when it grants none.
     * The override paths ignore it ({@see RetainerCalculator::cycleRetainerFee()}).
     */
    private function legacyCycleMultiplier(ProjectedAgreement $agreement, BillingCycle $cycle): float
    {
        if ($agreement->periodRetainerHoursOverride() !== null) {
            return $this->retainers->cyclePeriodRetainerMultiplier($agreement, $cycle);
        }
        $monthlyHours = $agreement->retainerMonthlyHours();
        if ($monthlyHours <= 0) {
            return $cycle->monthCount;
        }

        $hours = 0.0;
        foreach ($cycle->monthStarts as $monthStart) {
            $hours += $this->retainers->retainerHoursForMonth($agreement, $monthStart->copy(), $monthStart->copy()->endOfMonth()->startOfDay());
        }

        return round($hours / $monthlyHours, 4);
    }

    /**
     * Each recurring-item charge in the window, within the agreement's own
     * term: [item id, date, minor units].
     *
     * @return iterable<array{int, CarbonImmutable, int}>
     */
    private function itemCharges(ProjectedAgreement $agreement, ProjectionInputs $inputs): iterable
    {
        // The first invoice sells the cycle containing the start: the calendar
        // month for a monthly agreement, the start itself otherwise.
        $firstCycleStart = $agreement->effectiveBillingCadence() === BillingCadence::Monthly
            ? $agreement->startsOn->startOfMonth()->startOfDay()
            : $agreement->startsOn->startOfDay();
        $rangeStart = $inputs->windowStart()->max($firstCycleStart);
        $end = $agreement->effectiveEnd();
        // An agreement that ends before the range opens leaves it empty, and the biller places nothing in an empty range.
        $rangeEnd = $end === null ? $inputs->windowEnd() : $inputs->windowEnd()->min($end);

        $model = $agreement->toBillerModel();
        // The biller refuses an item whose cadence no case names, rather than
        // billing nothing silently; a projection leaves it out the same way an
        // invoice would never carry it.
        $model->setRelation('recurringItems', $model->recurringItems->filter(
            static fn (ClientAgreementRecurringItem $item): bool => $item->charge_cadence !== null,
        )->values());

        foreach ($this->items->linesForCycle($model, Carbon::instance($rangeStart), Carbon::instance($rangeEnd)) as $line) {
            yield [$line['item']->id, CarbonImmutable::instance($line['line_date'])->startOfDay(), $this->items->buildLine($line)->total_amount];
        }
    }

    /**
     * @param  array<string, array{retainer: int, recurring_items: int, projected_overage: int}>  $into
     * @param  array<string, array{retainer: int, recurring_items: int, projected_overage: int}>  $add
     * @return array<string, array{retainer: int, recurring_items: int, projected_overage: int}>
     */
    private static function addMonths(array $into, array $add): array
    {
        foreach ($add as $month => $amounts) {
            $current = $into[$month];
            $into[$month] = [
                'retainer' => $current['retainer'] + $amounts['retainer'],
                'recurring_items' => $current['recurring_items'] + $amounts['recurring_items'],
                'projected_overage' => $current['projected_overage'] + $amounts['projected_overage'],
            ];
        }

        return $into;
    }

    private static function minor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /**
     * @param  list<string>  $months
     * @return array<string, array{retainer: int, recurring_items: int, projected_overage: int}>
     */
    private static function emptyMonths(array $months): array
    {
        return array_fill_keys($months, ['retainer' => 0, 'recurring_items' => 0, 'projected_overage' => 0]);
    }
}
