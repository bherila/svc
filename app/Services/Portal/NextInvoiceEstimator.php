<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Models\ClientAgreementRecurringItem;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\BillingCycleResolver;
use App\Services\Billing\RecurringItemBiller;
use App\Services\Billing\RetainerCalculator;
use App\Support\Billing\BillingCadence;
use App\Support\Portal\NextInvoiceEstimate;
use App\Support\Revenue\ProjectedAgreement;
use App\Support\Revenue\ProjectionInputs;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * What the next invoice on an agreement is expected to carry, for the client
 * portal: the recurring charges for the coming cadence period that no charged
 * invoice has sold yet, plus the overage accrued so far.
 *
 * Database-free. The agreement arrives as {@see ProjectedAgreement}, what has
 * already been sold as the keys {@see ProjectionInputs} uses, and the overage
 * as hours from the capacity ledger, so every rule is tested without a
 * database. Charges are placed and priced by the billing engine's own services
 * ({@see BillingCycleResolver}, {@see RetainerCalculator},
 * {@see RecurringItemBiller}) exactly as the revenue projector places them, and
 * `NextInvoiceEstimatorTest` pins the retainer to the projector's figure.
 *
 * It looks at most {@see self::LOOKAHEAD} cycles ahead for one that still has
 * something to sell, because a retainer billed in advance leaves the current
 * cycle empty. No further: this is the next invoice, not a forecast.
 */
final class NextInvoiceEstimator
{
    public const int LOOKAHEAD = 3;

    public function __construct(
        private readonly BillingCycleResolver $cycles = new BillingCycleResolver,
        private readonly RetainerCalculator $retainers = new RetainerCalculator,
        private readonly RecurringItemBiller $items = new RecurringItemBiller,
    ) {}

    /**
     * @param  array<string, true>  $billed  keys from {@see ProjectionInputs::retainerKey()} and {@see ProjectionInputs::itemKey()}
     * @param  float  $overageHours  hours worked past the retainer and its rollover this period
     * @param  int|null  $hourlyRate  the agreement's rate in minor units; null leaves the overage unpriced
     */
    public function estimate(ProjectedAgreement $agreement, CarbonImmutable $today, array $billed, float $overageHours, ?int $hourlyRate): ?NextInvoiceEstimate
    {
        if (! $agreement->billsOnARecurringCadence()) {
            return null;
        }

        foreach ($this->upcomingCycles($agreement, $today->startOfDay()) as [$start, $end]) {
            $parts = [...$this->retainerPart($agreement, $start, $end, $billed), ...$this->itemParts($agreement, $start, $end, $billed)];
            if ($parts === []) {
                continue;
            }
            if ($overageHours > 0) {
                $parts[] = [
                    'kind' => 'overage',
                    'description' => 'Hours past the retainer so far',
                    'amount' => $hourlyRate === null ? null : (int) round($overageHours * $hourlyRate),
                    'date' => null,
                    'hours' => round($overageHours, 2),
                ];
            }

            return new NextInvoiceEstimate($start->toDateString(), $end->toDateString(), $agreement->currency, $parts);
        }

        return null;
    }

    /**
     * The cycles from the one holding today, as [start, end], never past the
     * agreement's end and never more than {@see self::LOOKAHEAD} of them.
     *
     * @return list<array{CarbonImmutable, CarbonImmutable}>
     */
    private function upcomingCycles(ProjectedAgreement $agreement, CarbonImmutable $today): array
    {
        $end = $agreement->effectiveEnd();
        $from = $today->max($agreement->startsOn->startOfDay());
        $found = [];

        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            for ($ahead = 0; $ahead < self::LOOKAHEAD; $ahead++) {
                $month = $from->startOfMonth()->addMonthsNoOverflow($ahead);
                if ($end === null || $month->lte($end)) {
                    $found[] = [$month, $month->endOfMonth()->startOfDay()];
                }
            }

            return $found;
        }

        $through = Carbon::instance($from)->addMonthsNoOverflow($agreement->effectiveBillingCadence()->monthsInCycle() * self::LOOKAHEAD);
        foreach ($this->cycles->cyclesForAgreement($agreement, $through) as $cycle) {
            $cycleEnd = CarbonImmutable::instance($cycle->end)->startOfDay();
            if ($cycleEnd->lt($from)) {
                continue;
            }
            $found[] = [CarbonImmutable::instance($cycle->start)->startOfDay(), $cycleEnd];
            if (count($found) === self::LOOKAHEAD) {
                break;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, true>  $billed
     * @return list<array{kind: string, description: string, amount: int|null, date: string|null, hours: float|null}>
     */
    private function retainerPart(ProjectedAgreement $agreement, CarbonImmutable $start, CarbonImmutable $end, array $billed): array
    {
        if (isset($billed[ProjectionInputs::retainerKey($agreement->id, $start->toDateString())])) {
            return [];
        }

        $amount = $agreement->effectiveBillingCadence() === BillingCadence::Monthly
            ? $this->monthlyRetainer($agreement, $start, $end)
            : $this->cycleRetainer($agreement, $start, $end);

        return $amount > 0
            ? [['kind' => 'retainer', 'description' => 'Retainer', 'amount' => $amount, 'date' => $start->toDateString(), 'hours' => null]]
            : [];
    }

    /** As the monthly generator sells a month: the fee times the month's covered fraction, rounded to cents. */
    private function monthlyRetainer(ProjectedAgreement $agreement, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $multiplier = $this->retainers->monthRetainerMultiplier($agreement, Carbon::instance($start), Carbon::instance($end));

        return (int) round(round($agreement->periodRetainerFee() * $multiplier, 2) * 100);
    }

    private function cycleRetainer(ProjectedAgreement $agreement, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $monthStarts = $this->monthStarts($start, $end);
        // @infection-ignore-all Equivalent: `isProrated` is read by no pricing service here ({@see RetainerCalculator} and {@see self::cycleMultiplier()} price from the dates and month starts), so true and false price the same.
        $cycle = new BillingCycle(Carbon::instance($start), Carbon::instance($end), false, count($monthStarts), $monthStarts);

        return (int) round($this->retainers->cycleRetainerFee($agreement, $cycle, ['retainer_multiplier' => $this->cycleMultiplier($agreement, $cycle)]) * 100);
    }

    /**
     * The multiplier the cadence generator hands the fee when the agreement
     * overrides neither period figure: the months' granted hours over a
     * month's retainer, or the month count when it grants none. The override
     * path ignores it. The same rule the revenue projector applies.
     */
    private function cycleMultiplier(ProjectedAgreement $agreement, BillingCycle $cycle): float
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

    /** @return list<Carbon> the first day of each calendar month the span touches */
    private function monthStarts(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = [];
        for ($month = $start->startOfMonth(); $month->lte($end); $month = $month->addMonthNoOverflow()) {
            $months[] = Carbon::instance($month);
        }

        return $months;
    }

    /**
     * Each recurring-item charge in the span that no charged invoice has sold,
     * placed by the invoice generator's own incidence rules and clipped to the
     * agreement's end.
     *
     * @param  array<string, true>  $billed
     * @return list<array{kind: string, description: string, amount: int|null, date: string|null, hours: float|null}>
     */
    private function itemParts(ProjectedAgreement $agreement, CarbonImmutable $start, CarbonImmutable $end, array $billed): array
    {
        $agreementEnd = $agreement->effectiveEnd();
        $rangeEnd = $agreementEnd === null ? $end : $end->min($agreementEnd);

        $model = $agreement->toBillerModel();
        // An item whose cadence no case names is left out, as an invoice would
        // never carry it.
        $model->setRelation('recurringItems', $model->recurringItems->filter(
            static fn (ClientAgreementRecurringItem $item): bool => $item->charge_cadence !== null,
        )->values());

        $parts = [];
        foreach ($this->items->linesForCycle($model, Carbon::instance($start), Carbon::instance($rangeEnd)) as $line) {
            $date = $line['line_date']->toDateString();
            if (isset($billed[ProjectionInputs::itemKey($line['item']->id, $date)])) {
                continue;
            }
            $parts[] = [
                'kind' => 'recurring_item',
                'description' => $line['description'],
                'amount' => $this->items->buildLine($line)->total_amount,
                'date' => $date,
                'hours' => null,
            ];
        }

        return $parts;
    }
}
