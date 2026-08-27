<?php

namespace App\Services\Billing\Balances;

/**
 * Represents the complete allocation plan for time entries.
 *
 * This DTO contains all time entry fragments organized by their allocation type,
 * along with summary totals for each category.
 */
readonly class AllocationPlan
{
    /**
     * Create a new allocation plan.
     *
     * @param  TimeEntryFragment[]  $priorMonthRetainerFragments  Fragments covered by prior month retainer
     * @param  TimeEntryFragment[]  $currentMonthRetainerFragments  Fragments covered by current month retainer
     * @param  TimeEntryFragment[]  $catchUpFragments  Fragments for catch-up billing (maintaining threshold)
     * @param  TimeEntryFragment[]  $billableCatchupFragments  Fragments that are billable beyond catch-up threshold
     * @param  float  $totalPriorMonthRetainerHours  Total hours allocated to prior month retainer
     * @param  float  $totalCurrentMonthRetainerHours  Total hours allocated to current month retainer
     * @param  float  $totalCatchUpHours  Total hours allocated to catch-up billing
     * @param  float  $totalBillableCatchupHours  Total hours that are billable beyond threshold
     */
    public function __construct(
        public array $priorMonthRetainerFragments,
        public array $currentMonthRetainerFragments,
        public array $catchUpFragments,
        public array $billableCatchupFragments,
        public float $totalPriorMonthRetainerHours,
        public float $totalCurrentMonthRetainerHours,
        public float $totalCatchUpHours,
        public float $totalBillableCatchupHours
    ) {}

    /**
     * Get the total number of fragments in this allocation plan.
     */
    public function getTotalFragments(): int
    {
        return count($this->priorMonthRetainerFragments) +
               count($this->currentMonthRetainerFragments) +
               count($this->catchUpFragments) +
               count($this->billableCatchupFragments);
    }

    /**
     * Get all fragments as a single flat array.
     *
     * @return TimeEntryFragment[]
     */
    public function getAllFragments(): array
    {
        return array_merge(
            $this->priorMonthRetainerFragments,
            $this->currentMonthRetainerFragments,
            $this->catchUpFragments,
            $this->billableCatchupFragments
        );
    }

    /**
     * Get the total hours across all allocation types.
     */
    public function getTotalHours(): float
    {
        return $this->totalPriorMonthRetainerHours +
               $this->totalCurrentMonthRetainerHours +
               $this->totalCatchUpHours +
               $this->totalBillableCatchupHours;
    }
}
