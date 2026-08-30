<?php

namespace App\Services\Billing;

use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\AllocationPlan;
use App\Services\Billing\Balances\TimeEntryFragment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for splitting time entries into fragments based on allocation rules.
 *
 * This service implements the deterministic splitting logic for allocating time entries
 * across different capacity pools (prior month retainer, current month retainer, catch-up, billable).
 */
class TimeEntrySplitter
{
    /**
     * Split time entries into fragments based on available capacity.
     *
     * The allocation follows this priority:
     * 1. Prior month retainer capacity (oldest hours first)
     * 2. Current month retainer capacity
     * 3. Catch-up billing (up to threshold to maintain minimum availability)
     * 4. Billable catch-up (beyond threshold)
     *
     * @param  Collection<int, ClientTimeEntry>  $timeEntries  Collection of ClientTimeEntry models
     * @param  float  $priorMonthRetainerCapacity  Hours available from prior month retainer
     * @param  float  $currentMonthRetainerCapacity  Hours available from current month retainer
     * @param  float  $catchUpThresholdHours  Minimum availability buffer hours
     */
    public function allocateTimeEntries(
        Collection $timeEntries,
        float $priorMonthRetainerCapacity,
        float $currentMonthRetainerCapacity,
        float $catchUpThresholdHours
    ): AllocationPlan {
        $priorMonthFragments = [];
        $currentMonthFragments = [];
        $catchUpFragments = [];
        $billableCatchupFragments = [];

        $remainingPriorMonthMinutes = (int) round($priorMonthRetainerCapacity * 60);
        $remainingCurrentMonthMinutes = (int) round($currentMonthRetainerCapacity * 60);
        $catchUpThresholdMinutes = (int) round($catchUpThresholdHours * 60);

        // Calculate how much catch-up billing is needed to restore threshold
        $totalRetainerMinutes = $remainingPriorMonthMinutes + $remainingCurrentMonthMinutes;
        $catchUpNeededMinutes = max(0, $catchUpThresholdMinutes - $totalRetainerMinutes);

        // Process entries in chronological order (deterministic)
        $sortedEntries = $timeEntries->sortBy([
            ['date_worked', 'asc'],
            ['id', 'asc'], // Stable sort for same-date entries
        ]);

        foreach ($sortedEntries as $entry) {
            $remainingMinutes = $entry->minutes_worked;

            // Allocate to prior month retainer first
            if ($remainingPriorMonthMinutes > 0 && $remainingMinutes > 0) {
                $allocated = min($remainingMinutes, $remainingPriorMonthMinutes);
                $priorMonthFragments[] = $this->createFragment(
                    $entry,
                    $allocated,
                    'prior_month_retainer'
                );
                $remainingMinutes -= $allocated;
                $remainingPriorMonthMinutes -= $allocated;
            }

            // Allocate to current month retainer
            if ($remainingCurrentMonthMinutes > 0 && $remainingMinutes > 0) {
                $allocated = min($remainingMinutes, $remainingCurrentMonthMinutes);
                $currentMonthFragments[] = $this->createFragment(
                    $entry,
                    $allocated,
                    'current_month_retainer'
                );
                $remainingMinutes -= $allocated;
                $remainingCurrentMonthMinutes -= $allocated;
            }

            // Allocate to catch-up billing (to restore threshold)
            if ($catchUpNeededMinutes > 0 && $remainingMinutes > 0) {
                $allocated = min($remainingMinutes, $catchUpNeededMinutes);
                $catchUpFragments[] = $this->createFragment(
                    $entry,
                    $allocated,
                    'catch_up'
                );
                $remainingMinutes -= $allocated;
                $catchUpNeededMinutes -= $allocated;
            }

            // Any remaining minutes are billable catch-up (beyond threshold)
            if ($remainingMinutes > 0) {
                $billableCatchupFragments[] = $this->createFragment(
                    $entry,
                    $remainingMinutes,
                    'billable_catchup'
                );
            }
        }

        return new AllocationPlan(
            priorMonthRetainerFragments: $priorMonthFragments,
            currentMonthRetainerFragments: $currentMonthFragments,
            catchUpFragments: $catchUpFragments,
            billableCatchupFragments: $billableCatchupFragments,
            totalPriorMonthRetainerHours: $this->sumFragmentHours($priorMonthFragments),
            totalCurrentMonthRetainerHours: $this->sumFragmentHours($currentMonthFragments),
            totalCatchUpHours: $this->sumFragmentHours($catchUpFragments),
            totalBillableCatchupHours: $this->sumFragmentHours($billableCatchupFragments)
        );
    }

    /**
     * Split a single time entry into two fragments at the specified minutes.
     * Creates a new ClientTimeEntry record for the overflow.
     *
     * @param  ClientTimeEntry  $entry  The entry to split
     * @param  int  $splitAtMinutes  The number of minutes for the primary fragment
     * @return array{primary: ClientTimeEntry, overflow: ClientTimeEntry}
     *
     * @throws \InvalidArgumentException If split point is invalid
     */
    public function splitEntry(ClientTimeEntry $entry, int $splitAtMinutes): array
    {
        if ($splitAtMinutes <= 0 || $splitAtMinutes >= $entry->minutes_worked) {
            throw new \InvalidArgumentException(
                "Split point must be between 1 and {$entry->minutes_worked}. Got: {$splitAtMinutes}"
            );
        }

        return DB::transaction(function () use ($entry, $splitAtMinutes) {
            $overflowMinutes = $entry->minutes_worked - $splitAtMinutes;

            // Create overflow entry.
            // Column names differ from the predecessor here because this writes a
            // real row; the allocation arithmetic above is untouched.
            $overflow = ClientTimeEntry::create([
                'workspace_id' => $entry->workspace_id,
                'client_company_id' => $entry->client_company_id,
                'client_project_id' => $entry->client_project_id,
                'client_task_id' => $entry->client_task_id,
                'user_id' => $entry->user_id,
                // Always the root, never an intermediate fragment, so a repeatedly
                // split entry stays one flat group for recombination.
                'split_from_time_entry_id' => $entry->split_from_time_entry_id ?? $entry->id,
                'worked_on' => $entry->worked_on,
                'minutes' => $overflowMinutes,
                'description' => $entry->description,
                'job_type' => $entry->job_type,
                // Cast the flags: an unset flag on an in-memory entry is null, and
                // these columns are NOT NULL. Absent means false, not unknown.
                'is_billable' => (bool) $entry->is_billable,
                'is_deferred' => (bool) $entry->is_deferred,
                'is_visible_to_client' => (bool) $entry->is_visible_to_client,
                'status' => $entry->status,
                'billing_rate_amount' => $entry->billing_rate_amount,
                'currency' => $entry->currency,
                // Everything that describes the work or its money travels with
                // the fragment. Dropping any of it would leave two rows that no
                // longer represent the same piece of work - subcontractor cost
                // vanishing from the overflow, or an approval losing its author.
                'billing_rate_source' => $entry->billing_rate_source,
                'client_visible_description' => $entry->client_visible_description,
                'subcontractor_billing_mode' => $entry->subcontractor_billing_mode,
                'subcontractor_cost_amount' => $entry->subcontractor_cost_amount,
                'subcontractor_cost_currency' => $entry->subcontractor_cost_currency,
                'subcontractor_cost_metadata' => $entry->subcontractor_cost_metadata,
                'approved_by_user_id' => $entry->approved_by_user_id,
                'approved_at' => $entry->approved_at,
                // The overflow starts unbilled. Here that is the absence of a pivot
                // row rather than a null column, so there is nothing to set.
            ]);

            $entry->update([
                'minutes' => $splitAtMinutes,
            ]);

            return [
                'primary' => $entry->fresh(),
                'overflow' => $overflow,
            ];
        });
    }

    /**
     * Create a TimeEntryFragment from a ClientTimeEntry model.
     *
     * @param  ClientTimeEntry  $entry  The source time entry
     * @param  int  $minutes  The number of minutes for this fragment
     * @param  string  $allocationType  The allocation type for this fragment
     */
    protected function createFragment(
        ClientTimeEntry $entry,
        int $minutes,
        string $allocationType
    ): TimeEntryFragment {
        return new TimeEntryFragment(
            originalTimeEntryId: $entry->id,
            minutes: $minutes,
            dateWorked: $entry->date_worked->format('Y-m-d'),
            description: $entry->name,
            userId: $entry->user_id,
            clientInvoiceLineId: $entry->client_invoice_line_id,
            allocationType: $allocationType
        );
    }

    /**
     * Sum the total hours from an array of fragments.
     *
     * @param  TimeEntryFragment[]  $fragments
     */
    protected function sumFragmentHours(array $fragments): float
    {
        return array_reduce(
            $fragments,
            fn ($sum, $fragment) => $sum + $fragment->getHours(),
            0.0
        );
    }
}
