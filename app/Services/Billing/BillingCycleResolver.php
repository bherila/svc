<?php

namespace App\Services\Billing;

use App\Contracts\RetainerAgreementTerms;
use App\Services\Billing\Balances\BillingCycle;
use App\Support\Billing\BillingCadence;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resolves billing cycles for a client agreement.
 *
 * The monthly ledger (RolloverCalculator) remains the authoritative source.
 * BillingCycleResolver only determines *how* those months are grouped into
 * invoiceable cycles — it does not recalculate rollover math.
 */
class BillingCycleResolver
{
    /**
     * Yield each billing cycle from the agreement's active date through
     * min(termination_date, $through).
     *
     * Monthly cadence uses calendar months. Non-monthly cadences are anchored
     * to active_date and span their configured number of months.
     *
     * @return iterable<BillingCycle>
     */
    public function cyclesForAgreement(RetainerAgreementTerms $agreement, CarbonInterface $through): iterable
    {
        $cadence = $agreement->effectiveBillingCadence();

        // Every cycle here is measured from the start date. That used to be a
        // refusal - `starts_on` was nullable and this was the only reader that
        // treated a null as fatal, while others treated the same null as in
        // force. It is `NOT NULL` now (#147), so there is nothing left to
        // refuse and the disagreement is gone with it.
        $activeDate = Carbon::instance($agreement->retainerStartsOn())->startOfDay();
        $terminationDate = $agreement->retainerEndsOn()
            ? Carbon::instance($agreement->retainerEndsOn())->startOfDay()
            : null;

        $ceiling = Carbon::instance($through)->startOfDay();
        if ($terminationDate !== null && $terminationDate->lt($ceiling)) {
            $ceiling = $terminationDate->copy();
        }

        if ($activeDate->gt($ceiling)) {
            return;
        }

        if ($cadence === BillingCadence::Monthly) {
            foreach ($this->generateMonthlyCycles($activeDate, $ceiling) as $cycle) {
                yield $cycle;
            }

            return;
        }

        yield from $this->generateActiveDateAnchoredCycles($cadence, $activeDate, $ceiling);
    }

    /**
     * Return the billing cycle that contains the given $date for $agreement.
     */
    public function cycleContaining(RetainerAgreementTerms $agreement, CarbonInterface $date): BillingCycle
    {
        $cadence = $agreement->effectiveBillingCadence();
        if ($cadence !== BillingCadence::Monthly) {
            // This method used to answer a null start date two ways depending
            // on cadence - fatal for a non-monthly agreement, an ordinary
            // calendar cycle for a monthly one. `NOT NULL` (#147) removes the
            // asymmetry rather than picking one of its two answers.
            $activeDate = Carbon::instance($agreement->retainerStartsOn())->startOfDay();
            if (Carbon::instance($date)->startOfDay()->lt($activeDate)) {
                throw new \InvalidArgumentException(
                    'Cannot resolve a cycle for a date before the agreement active_date.'
                );
            }
            $resolved = $activeDate->copy();
            while ($resolved->copy()->addMonths($cadence->monthsInCycle())->subDay()->lt($date)) {
                $resolved->addMonths($cadence->monthsInCycle());
            }

            return $this->makeCycle(
                $resolved->copy(),
                $resolved->copy()->addMonths($cadence->monthsInCycle())->subDay(),
                false
            );
        }
        $start = $cadence->cycleStart($date);
        $end = $cadence->cycleEnd($date);

        return $this->makeCycle($start, $end, false);
    }

    /**
     * Generate monthly cycles (one per calendar month) between $from and $ceiling.
     *
     * The first cycle starts at $from (not at the beginning of that month), so
     * mid-month agreement starts produce a prorated partial first month.
     *
     * @return iterable<BillingCycle>
     */
    private function generateMonthlyCycles(Carbon $from, Carbon $ceiling): iterable
    {
        $cursor = $from->copy()->startOfMonth();
        $isFirstMonth = true;

        while ($cursor->lte($ceiling)) {
            $cycleStart = $isFirstMonth ? $from->copy() : $cursor->copy();
            $cycleEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $clippedEnd = $cycleEnd->gt($ceiling) ? $ceiling->copy() : $cycleEnd->copy();
            $isProrated = $cycleStart->gt($cursor) || $clippedEnd->lt($cycleEnd);

            yield $this->makeCycle($cycleStart, $clippedEnd, $isProrated);

            $isFirstMonth = false;
            $cursor->addMonth()->startOfMonth();
        }
    }

    /**
     * Generate non-monthly cadence cycles anchored to the agreement active date.
     *
     * @return iterable<BillingCycle>
     */
    private function generateActiveDateAnchoredCycles(BillingCadence $cadence, Carbon $activeDate, Carbon $ceiling): iterable
    {
        $cursor = $activeDate->copy();
        $monthsInCycle = $cadence->monthsInCycle();

        while ($cursor->lte($ceiling)) {
            $cycleStart = $cursor->copy();
            $cycleEnd = $cycleStart->copy()->addMonths($monthsInCycle)->subDay();
            $clippedEnd = $cycleEnd->gt($ceiling) ? $ceiling->copy() : $cycleEnd->copy();

            yield $this->makeCycle($cycleStart, $clippedEnd, $clippedEnd->lt($cycleEnd));

            $cursor = $cycleEnd->copy()->addDay();
        }
    }

    /**
     * Build a BillingCycle DTO from a [start, end] date range.
     */
    private function makeCycle(Carbon $start, Carbon $end, bool $isProrated): BillingCycle
    {
        $monthStarts = [];
        $cursor = $start->copy()->startOfMonth();

        while ($cursor->lte($end)) {
            $monthStarts[] = $cursor->copy();
            $cursor->addMonth()->startOfMonth();
        }

        return new BillingCycle(
            start: $start,
            end: $end,
            isProrated: $isProrated,
            monthCount: count($monthStarts),
            monthStarts: $monthStarts,
        );
    }
}
