<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Services\Billing\Balances\BillingCycle;
use App\Support\Billing\FirstCycleProration;
use Carbon\Carbon;

class RetainerCalculator
{
    public function __construct(private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver) {}

    /**
     * Resolve retainer hours for this concrete cadence cycle.
     *
     * @param  array<string, float>  $cycleLedger
     */
    public function cycleRetainerHours(ClientAgreement $agreement, BillingCycle $cycle, array $cycleLedger): float
    {
        if ($agreement->retainer_hours !== null) {
            return $this->cyclePeriodRetainerHours($agreement, $cycle);
        }

        return $cycleLedger['retainer_hours'];
    }

    public function cyclePeriodRetainerHours(ClientAgreement $agreement, BillingCycle $cycle): float
    {
        return round((float) $agreement->retainer_hours * $this->cyclePeriodRetainerMultiplier($agreement, $cycle), 4);
    }

    /**
     * Resolve retainer fee for this concrete cadence cycle.
     *
     * @param  array<string, float>  $cycleLedger
     */
    public function cycleRetainerFee(ClientAgreement $agreement, BillingCycle $cycle, array $cycleLedger): float
    {
        if ($agreement->retainer_fee !== null) {
            return round((float) $agreement->retainer_fee * $this->cyclePeriodRetainerMultiplier($agreement, $cycle), 2);
        }

        return round((float) $agreement->monthly_retainer_fee * $cycleLedger['retainer_multiplier'], 2);
    }

    /**
     * Multiplier to apply to retainer_hours / retainer_fee for the given cycle.
     *
     * The window is the cycle's effective entitlement — start = max(cycle.start,
     * natural cycle start) and end = min(natural cycle end, termination_date) —
     * over the natural cycle length. We deliberately ignore the cycle's end as
     * yielded by `cyclesForAgreement(...)` because that may have been clipped
     * by `$through` (e.g., when an interim ledger is built mid-cycle), which is
     * not a real shortening of the client's retainer entitlement.
     */
    public function cyclePeriodRetainerMultiplier(ClientAgreement $agreement, BillingCycle $cycle): float
    {
        $naturalCycle = $this->billingCycleResolver->cycleContaining($agreement, $cycle->start);

        $activeDate = Carbon::instance($agreement->starts_on)->startOfDay();
        $fullPeriodFirstCycle = $agreement->effectiveFirstCycleProration() === FirstCycleProration::FullPeriod
            && $cycle->start->isSameDay($activeDate)
            && $cycle->start->gt($naturalCycle->start);

        $effectiveStart = $fullPeriodFirstCycle || $naturalCycle->start->gt($cycle->start)
            ? $naturalCycle->start->copy()
            : $cycle->start->copy();
        $effectiveEnd = $naturalCycle->end->copy();

        $terminationDate = $agreement->ends_on
            ? Carbon::parse($agreement->ends_on)->startOfDay()
            : null;
        if ($terminationDate !== null && $terminationDate->lt($effectiveEnd)) {
            $effectiveEnd = $terminationDate->copy();
        }

        if ($effectiveStart->gt($effectiveEnd)) {
            return 0.0;
        }

        $naturalDays = $naturalCycle->start->diffInDays($naturalCycle->end) + 1;
        if ($naturalDays <= 0) {
            return 1.0;
        }

        $effectiveDays = $effectiveStart->diffInDays($effectiveEnd) + 1;
        if ($effectiveDays >= $naturalDays) {
            return 1.0;
        }

        return $effectiveDays / $naturalDays;
    }

    /**
     * How many retainer hours a month actually grants.
     *
     * The fee for a partly-covered month is prorated, and so is the ledger's
     * capacity - but the monthly generation path read `monthly_retainer_hours`
     * straight off the agreement in three places, so an agreement starting on
     * the 15th was charged half a retainer and granted a whole month's pool.
     * Both questions have one answer now.
     */
    public function retainerHoursForMonth(ClientAgreement $agreement, Carbon $monthStart, Carbon $monthEnd): float
    {
        return round(
            $agreement->retainerHoursPerMonth() * $this->monthRetainerMultiplier($agreement, $monthStart, $monthEnd),
            4,
        );
    }

    public function monthRetainerMultiplier(ClientAgreement $agreement, Carbon $monthStart, Carbon $monthEnd): float
    {
        $activeDate = Carbon::parse($agreement->starts_on)->startOfDay();
        $terminationDate = $agreement->ends_on
            ? Carbon::parse($agreement->ends_on)->startOfDay()
            : null;

        if ($activeDate->lte($monthStart) && (! $terminationDate || $terminationDate->gte($monthEnd))) {
            return 1.0;
        }

        $coveredStart = $activeDate->gt($monthStart) ? $activeDate->copy() : $monthStart->copy();
        $coveredEnd = $monthEnd->copy();
        if ($terminationDate && $terminationDate->lt($coveredEnd)) {
            $coveredEnd = $terminationDate->copy();
        }

        if ($coveredStart->gt($coveredEnd)) {
            return 0.0;
        }

        if ($coveredStart->isSameDay($monthStart) && $coveredEnd->isSameDay($monthEnd)) {
            return 1.0;
        }

        // FirstCycleProration says what happens to the *first* cycle. Applying
        // it to any partial month also grants a full month's capacity in a
        // termination month, which understates the overage the client owes.
        $agreementStart = $agreement->starts_on === null
            ? null
            : Carbon::parse((string) $agreement->starts_on)->startOfDay();

        $isOpeningMonth = $agreementStart !== null
            && $agreementStart->betweenIncluded($monthStart, $monthEnd);

        if ($isOpeningMonth && $agreement->effectiveFirstCycleProration() === FirstCycleProration::FullPeriod) {
            return 1.0;
        }

        return round(($coveredStart->diffInDays($coveredEnd) + 1) / $monthStart->daysInMonth, 4);
    }
}
