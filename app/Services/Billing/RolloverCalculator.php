<?php

namespace App\Services\Billing;

use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;

/**
 * Calculates rollover hour balances for client retainer agreements.
 *
 * This class encapsulates all rollover logic for retainer-based billing:
 * - Tracking hours included in monthly retainers
 * - Calculating rollover hours that carry forward
 * - Determining when hours expire based on rollover_months setting
 * - Calculating negative balances when hours exceed available pool
 * - Determining excess hours to be billed at hourly rate
 *
 * Rules:
 * 1. Each month grants retainer_hours to the available pool
 * 2. Unused hours roll over for up to rollover_months (1 = this month only, no rollover)
 * 3. When hours worked exceed current month's retainer, rollover hours are used first (FIFO)
 * 4. If all available hours are exhausted, excess is billed at hourly rate
 * 5. If previous month had a negative balance, new month's hours offset it first
 */
class RolloverCalculator
{
    /**
     * Calculate the opening balance for a month.
     *
     * @param  float  $retainerHours  Hours included in the current month's retainer
     * @param  array<int, float>  $previousMonthsUnused  Array of unused hours from previous months,
     *                                                   indexed by months ago (1 = last month, 2 = two months ago, etc.)
     * @param  int  $rolloverMonths  Number of months hours can roll over (1 = no rollover)
     * @param  float  $previousNegativeBalance  Negative balance carried from previous month
     */
    public function calculateOpeningBalance(
        float $retainerHours,
        array $previousMonthsUnused,
        int $rolloverMonths,
        float $previousNegativeBalance = 0.0
    ): OpeningBalance {
        $rolloverHours = 0.0;
        $expiredHours = 0.0;

        // Calculate rollover and expired hours from previous months
        foreach ($previousMonthsUnused as $monthsAgo => $unusedHours) {
            // If rollover_months is 1, hours from 1 month ago roll over
            // If rollover_months is 2, hours from 1-2 months ago roll over
            // If rollover_months is 0, nothing rolls over
            if ($monthsAgo <= $rolloverMonths) {
                $rolloverHours += $unusedHours;
            } else {
                $expiredHours += $unusedHours;
            }
        }

        // Apply negative balance offset (subtract from this month's retainer hours first)
        $negativeOffset = 0.0;
        $invoicedNegativeBalance = 0.0;
        $effectiveRetainerHours = $retainerHours;

        if ($previousNegativeBalance > 0) {
            $negativeOffset = min($previousNegativeBalance, $retainerHours);
            // In the "give and take" model, we carry forward the remaining negative balance
            // instead of billing it immediately.
            $invoicedNegativeBalance = 0.0;
            $effectiveRetainerHours = $retainerHours - $negativeOffset;
        }

        $totalAvailable = $effectiveRetainerHours + $rolloverHours;

        // The remaining negative balance after applying retainer
        $remainingNegativeBalance = max(0, $previousNegativeBalance - $retainerHours);

        return new OpeningBalance(
            retainerHours: round($retainerHours, 4),
            rolloverHours: round($rolloverHours, 4),
            expiredHours: round($expiredHours, 4),
            totalAvailable: round($totalAvailable, 4),
            negativeOffset: round($negativeOffset, 4),
            invoicedNegativeBalance: round($invoicedNegativeBalance, 4),
            effectiveRetainerHours: round($effectiveRetainerHours, 4),
            remainingNegativeBalance: round($remainingNegativeBalance, 4),
        );
    }

    /**
     * Calculate the closing balance for a month after hours are worked.
     *
     * @param  float  $totalAvailable  Total hours available at start of month
     * @param  float  $hoursWorked  Hours worked during the month
     * @param  float  $retainerHours  This month's retainer hours (for categorizing usage)
     * @param  float  $rolloverHours  Available rollover hours from previous months
     * @param  float  $remainingNegativeBalance  Negative balance that was too large to be offset by retainer
     */
    public function calculateClosingBalance(
        float $totalAvailable,
        float $hoursWorked,
        float $retainerHours,
        float $rolloverHours,
        bool $billExcessImmediately = false,
        float $remainingNegativeBalance = 0.0
    ): ClosingBalance {
        $hoursUsedFromRetainer = 0.0;
        $hoursUsedFromRollover = 0.0;
        $unusedHours = 0.0;
        $excessHours = 0.0;
        $negativeBalance = $remainingNegativeBalance;

        if ($hoursWorked <= $retainerHours) {
            // Case C: All work covered by retainer, remainder rolls over
            $hoursUsedFromRetainer = $hoursWorked;
            $unusedHours = $retainerHours - $hoursWorked;
        } elseif ($hoursWorked <= $totalAvailable) {
            // Case A: Used all retainer hours plus some rollover
            $hoursUsedFromRetainer = $retainerHours;
            $hoursUsedFromRollover = $hoursWorked - $retainerHours;
            $unusedHours = 0.0; // Used all of this month's hours
        } else {
            // Case B: Exceeded all available hours
            $hoursUsedFromRetainer = $retainerHours;
            $hoursUsedFromRollover = $rolloverHours;

            if ($billExcessImmediately) {
                $excessHours = $hoursWorked - $totalAvailable;
            } else {
                $excessHours = 0.0;
                $negativeBalance += ($hoursWorked - $totalAvailable);
            }
        }

        return new ClosingBalance(
            hoursUsedFromRetainer: round($hoursUsedFromRetainer, 4),
            hoursUsedFromRollover: round($hoursUsedFromRollover, 4),
            unusedHours: round($unusedHours, 4),
            excessHours: round($excessHours, 4),
            negativeBalance: round($negativeBalance, 4),
            remainingRollover: round(max(0, $rolloverHours - $hoursUsedFromRollover), 4),
        );
    }

    /**
     * Calculate complete month summary combining opening and closing balances.
     *
     * @param  float  $retainerHours  Hours included in monthly retainer
     * @param  float  $hoursWorked  Hours worked during the month
     * @param  array<int, float>  $previousMonthsUnused  Unused hours from previous months by month index
     * @param  int  $rolloverMonths  Number of months hours can roll over
     * @param  float  $previousNegativeBalance  Negative balance from previous month
     */
    public function calculateMonthSummary(
        float $retainerHours,
        float $hoursWorked,
        array $previousMonthsUnused,
        int $rolloverMonths,
        float $previousNegativeBalance = 0.0,
        bool $billExcessImmediately = false,
        string $yearMonth = ''
    ): MonthSummary {
        $opening = $this->calculateOpeningBalance(
            $retainerHours,
            $previousMonthsUnused,
            $rolloverMonths,
            $previousNegativeBalance
        );

        $closing = $this->calculateClosingBalance(
            $opening->totalAvailable,
            $hoursWorked,
            $opening->effectiveRetainerHours,
            $opening->rolloverHours,
            $billExcessImmediately,
            $opening->remainingNegativeBalance
        );

        return new MonthSummary(
            opening: $opening,
            closing: $closing,
            hoursWorked: round($hoursWorked, 4),
            yearMonth: $yearMonth,
            retainerHours: $retainerHours,
            billExcessImmediately: $billExcessImmediately,
        );
    }

    /**
     * Calculate hour balances for multiple months in sequence.
     *
     * `billed_overage_hours` settles debt in that month. `reset_rollover`
     * clears accumulated unused hours at the first post-termination month but
     * deliberately preserves unbilled negative hours.
     *
     * @param  array<int, array{year_month?: string, retainer_hours?: float, hours_worked?: float, billed_overage_hours?: float, reset_rollover?: bool}>  $months
     * @param  int  $rolloverMonths  Number of months hours can roll over
     * @param  bool  $billExcessImmediately  Whether to bill excess hours immediately or carry them forward as negative balance.
     *                                       MonthSummary::closing->excessHours is populated only when this is true.
     * @return array<MonthSummary> Array of month summaries
     */
    public function calculateMultipleMonths(array $months, int $rolloverMonths, bool $billExcessImmediately = false): array
    {
        $results = [];
        $unusedByMonth = []; // Track unused hours by month for rollover calculation

        foreach ($months as $index => $month) {
            $retainerHours = $month['retainer_hours'] ?? 0.0;
            $hoursWorked = $month['hours_worked'] ?? 0.0;
            $yearMonth = $month['year_month'] ?? '';

            // If this month marks the post-termination boundary, clear rollover history.
            // Unused hours from before termination are forfeited and do not carry forward.
            // Note: the negative balance (overage) is intentionally preserved so that
            // any unbilled overage from the termination period is still collected.
            if ($month['reset_rollover'] ?? false) {
                $unusedByMonth = [];
            }

            // Age each stored balance by elapsed calendar months, not by its
            // position in this map. A month that ended with nothing unused is
            // never stored, so counting entries would treat a January balance as
            // one month old in March whenever February happened to be fully
            // consumed - and hours would stay spendable past their expiry.
            // Kept oldest-first so the deduction below stays FIFO.
            $previousMonthsUnused = [];
            $monthKeys = array_keys($unusedByMonth);

            foreach ($monthKeys as $key) {
                $monthsAgo = $this->monthsBetween((string) $key, $yearMonth);
                $previousMonthsUnused[$monthsAgo] = ($previousMonthsUnused[$monthsAgo] ?? 0.0) + $unusedByMonth[$key];
            }

            // Get negative balance from previous month if any
            $previousNegativeBalance = 0.0;
            if ($index > 0) {
                /** @var MonthSummary $prevSummary */
                $prevSummary = $results[$index - 1];
                if ($prevSummary->closing->negativeBalance > 0) {
                    $previousNegativeBalance = $prevSummary->closing->negativeBalance;
                }
            }

            $summary = $this->calculateMonthSummary(
                $retainerHours,
                $hoursWorked,
                $previousMonthsUnused,
                $rolloverMonths,
                $previousNegativeBalance,
                $billExcessImmediately,
                $yearMonth
            );

            // Settle a charge where it happened. A surplus is the deliberate
            // minimum-availability buffer and belongs to this month's capacity
            // lot, so the normal rollover window expires it instead of letting
            // the same old charge create fresh capacity forever.
            $billedOverage = max(0.0, $month['billed_overage_hours'] ?? 0.0);
            if ($billedOverage > 0.0) {
                $settledDebt = min($summary->closing->negativeBalance, $billedOverage);
                $summary = new MonthSummary(
                    opening: $summary->opening,
                    closing: new ClosingBalance(
                        hoursUsedFromRetainer: $summary->closing->hoursUsedFromRetainer,
                        hoursUsedFromRollover: $summary->closing->hoursUsedFromRollover,
                        unusedHours: round(
                            $summary->closing->unusedHours + ($billedOverage - $settledDebt),
                            4,
                        ),
                        excessHours: $summary->closing->excessHours,
                        negativeBalance: round($summary->closing->negativeBalance - $settledDebt, 4),
                        remainingRollover: $summary->closing->remainingRollover,
                    ),
                    hoursWorked: $summary->hoursWorked,
                    yearMonth: $summary->yearMonth,
                    retainerHours: $summary->retainerHours,
                    billExcessImmediately: $summary->billExcessImmediately,
                    cycleStart: $summary->cycleStart,
                );
            }

            // Deduct used rollover hours from the history stack (FIFO)
            $usedRollover = $summary->closing->hoursUsedFromRollover;
            if ($usedRollover > 0) {
                // Re-implementation of deduction logic using monthKeys
                foreach ($monthKeys as $key) {
                    if ($usedRollover <= 0) {
                        break;
                    }

                    $monthsAgo = $this->monthsBetween((string) $key, $yearMonth);
                    if ($monthsAgo <= $rolloverMonths) {
                        // This entry contributed to rollover
                        $amount = $unusedByMonth[$key];
                        $deduct = min($amount, $usedRollover);

                        $unusedByMonth[$key] -= $deduct;
                        $usedRollover -= $deduct;

                        if ($unusedByMonth[$key] <= 0.0001) {
                            unset($unusedByMonth[$key]);
                        }
                    }
                }
            }

            $results[] = $summary;

            // Track this month's unused hours for future rollover calculations
            // Only track if there are unused hours
            if ($summary->closing->unusedHours > 0) {
                $unusedByMonth[$yearMonth] = $summary->closing->unusedHours;
            }

            // Drop balances that can no longer be spent, in the month they
            // expire. A balance first falls outside the window at exactly
            // `rolloverMonths + 1`, which is the month that reports it as
            // expired; keeping it past that reported the same hours as expiring
            // again every month afterwards.
            foreach (array_keys($unusedByMonth) as $key) {
                if ($this->monthsBetween((string) $key, $yearMonth) >= $rolloverMonths + 1) {
                    unset($unusedByMonth[$key]);
                }
            }
        }

        return $results;
    }

    /**
     * Whole calendar months from one `Y-m` key to another.
     *
     * Falls back to zero for anything unparseable rather than throwing: a
     * malformed key should not take down a billing run, and treating it as the
     * current month keeps it spendable, which is the conservative direction.
     */
    private function monthsBetween(string $from, string $to): int
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $from, $a) !== 1 || preg_match('/^(\d{4})-(\d{2})$/', $to, $b) !== 1) {
            return 0;
        }

        return ((int) $b[1] * 12 + (int) $b[2]) - ((int) $a[1] * 12 + (int) $a[2]);
    }

    /**
     * Get a human-readable description of the hour balance status.
     *
     * @param  MonthSummary  $monthSummary  The summary from calculateMonthSummary
     * @return string Description of the status
     */
    public function getStatusDescription(MonthSummary $monthSummary): string
    {
        $opening = $monthSummary->opening;
        $closing = $monthSummary->closing;

        $parts = [];

        if ($opening->invoicedNegativeBalance > 0) {
            $parts[] = sprintf(
                'Previous negative balance exceeded retainer by %.2f hours (billed at hourly rate)',
                $opening->invoicedNegativeBalance
            );
        }

        if ($closing->negativeBalance > 0) {
            $parts[] = sprintf(
                'Negative balance of %.2f hours carried forward to next month',
                $closing->negativeBalance
            );
        }

        if ($closing->excessHours > 0) {
            $parts[] = sprintf(
                'Exceeded by %.2f hours (billed at hourly rate)',
                $closing->excessHours
            );
        }

        if ($closing->unusedHours > 0) {
            $parts[] = sprintf(
                '%.2f unused hours will roll over',
                $closing->unusedHours
            );
        }

        if ($closing->hoursUsedFromRollover > 0) {
            $parts[] = sprintf(
                'Used %.2f rollover hours',
                $closing->hoursUsedFromRollover
            );
        }

        if (empty($parts)) {
            return 'All retainer hours used exactly';
        }

        return implode('; ', $parts);
    }
}
