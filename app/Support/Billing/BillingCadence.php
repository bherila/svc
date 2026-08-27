<?php

namespace App\Support\Billing;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Billing cadence for a client agreement.
 *
 * Determines how frequently invoices are issued and what period each invoice
 * covers. The monthly ledger (RolloverCalculator) remains the source of truth;
 * cadence is a grouping and issuance policy layered on top.
 */
enum BillingCadence: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    /**
     * Number of calendar months in one billing cycle.
     */
    public function monthsInCycle(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
        };
    }

    /**
     * Map of every cadence backing value to its month count.
     *
     * Single source of truth for any consumer (including SQL `CASE`
     * expressions) that needs the cadence multiplier, so it can never drift
     * from {@see monthsInCycle()}.
     *
     * @return array<string, int>
     */
    public static function monthsInCycleMap(): array
    {
        return array_reduce(
            self::cases(),
            function (array $carry, self $cadence): array {
                $carry[$cadence->value] = $cadence->monthsInCycle();

                return $carry;
            },
            []
        );
    }

    /**
     * Returns the first day of the billing cycle that contains the given date,
     * aligned to calendar quarters/years.
     *
     * - Monthly: first day of the month.
     * - Quarterly: Jan 1 / Apr 1 / Jul 1 / Oct 1 of the reference year.
     * - SemiAnnual: Jan 1 / Jul 1 of the reference year.
     * - Annual: Jan 1 of the reference year.
     */
    public function cycleStart(CarbonInterface $reference): Carbon
    {
        $date = Carbon::instance($reference)->startOfDay();

        return match ($this) {
            self::Monthly => $date->copy()->startOfMonth(),
            self::Quarterly => $this->quarterStart($date),
            self::SemiAnnual => $date->month <= 6
                ? Carbon::create($date->year, 1, 1)->startOfDay()
                : Carbon::create($date->year, 7, 1)->startOfDay(),
            self::Annual => $date->copy()->startOfYear(),
        };
    }

    /**
     * Returns the last day of the billing cycle that contains the given date.
     */
    public function cycleEnd(CarbonInterface $reference): Carbon
    {
        $start = $this->cycleStart($reference);

        return match ($this) {
            self::Monthly => $start->copy()->endOfMonth()->startOfDay(),
            self::Quarterly => $start->copy()->addMonths(3)->subDay(),
            self::SemiAnnual => $start->copy()->addMonths(6)->subDay(),
            self::Annual => $start->copy()->endOfYear()->startOfDay(),
        };
    }

    /**
     * Yields the start date of every billing cycle that begins between $from
     * (inclusive) and $to (inclusive).
     *
     * @return iterable<Carbon>
     */
    public function cycleStartsBetween(CarbonInterface $from, CarbonInterface $to): iterable
    {
        $fromDate = Carbon::instance($from)->startOfDay();
        $cursor = $this->cycleStart($fromDate);

        if ($cursor->lt($fromDate)) {
            $cursor->addMonths($this->monthsInCycle());
        }

        while ($cursor->lte($to)) {
            yield $cursor->copy();
            $cursor->addMonths($this->monthsInCycle());
        }
    }

    /**
     * Returns the first day of the calendar quarter containing $date.
     */
    private function quarterStart(Carbon $date): Carbon
    {
        $quarterStartMonth = (int) ceil($date->month / 3) * 3 - 2;

        return Carbon::create($date->year, $quarterStartMonth, 1)->startOfDay();
    }
}
