<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use Carbon\Carbon;

class InvoiceLedgerBuilder
{
    public function __construct(
        private readonly RolloverCalculator $rolloverCalculator = new RolloverCalculator,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly RetainerCalculator $retainerCalculator = new RetainerCalculator,
    ) {}

    /**
     * Build the monthly ledger for one agreement through a given date.
     *
     * @return array<int, MonthSummary>
     */
    public function buildAgreementLedgerThrough(
        ClientCompany $company,
        ClientAgreement $agreement,
        Carbon $through,
        bool $billExcessImmediately = false,
    ): array {
        $activeDate = Carbon::parse($agreement->active_date)->startOfDay();
        $terminationDate = $agreement->termination_date
            ? Carbon::parse($agreement->termination_date)->startOfDay()
            : null;
        $ledgerEnd = $through->copy()->startOfDay();

        if ($terminationDate && $terminationDate->lt($ledgerEnd)) {
            $ledgerEnd = $terminationDate->copy();
        }

        if ($activeDate->gt($ledgerEnd)) {
            return [];
        }

        $billableEntries = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_billable', true)
            // Deferred work draws on the retainer only when the allocator finds
            // the whole entry fits. Counting it here consumes pool it may never
            // take, which manufactures a negative balance and bills catch-up
            // hours for capacity nothing actually used.
            ->where('is_deferred', false)
            ->retainerBillable()
            ->forAgreementScope($agreement)
            ->whereBetween('worked_on', [$activeDate, $ledgerEnd])
            ->get();

        if ($agreement->retainer_hours !== null) {
            /** @var array<string, float> $hoursByDate */
            $hoursByDate = [];
            foreach ($billableEntries as $entry) {
                $dateKey = Carbon::parse($entry->date_worked)->format('Y-m-d');
                $hoursByDate[$dateKey] = ($hoursByDate[$dateKey] ?? 0.0) + ((float) $entry->minutes_worked / 60);
            }

            return $this->buildPeriodRetainerLedgerThrough(
                $agreement,
                $ledgerEnd,
                $hoursByDate,
                $billExcessImmediately,
            );
        }

        $entriesByMonth = $billableEntries
            ->groupBy(fn (ClientTimeEntry $entry): string => Carbon::parse($entry->date_worked)->format('Y-m'));

        $months = [];
        $initialRolloverHours = (float) ($agreement->initial_rollover_hours ?? 0);
        if ($initialRolloverHours > 0) {
            $months[] = [
                'year_month' => $activeDate->copy()->startOfMonth()->subMonth()->format('Y-m'),
                'retainer_hours' => round($initialRolloverHours, 4),
                'hours_worked' => 0.0,
                'reset_rollover' => false,
            ];
        }

        $cursor = $activeDate->copy()->startOfMonth();
        while ($cursor->lte($ledgerEnd)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $monthKey = $monthStart->format('Y-m');
            $monthEntries = $entriesByMonth->get($monthKey, collect());
            $retainerMultiplier = $this->retainerCalculator->monthRetainerMultiplier($agreement, $monthStart, $monthEnd);

            $months[] = [
                'year_month' => $monthKey,
                'retainer_hours' => round((float) $agreement->monthly_retainer_hours * $retainerMultiplier, 4),
                'hours_worked' => round($monthEntries->sum('minutes_worked') / 60, 4),
                'reset_rollover' => false,
            ];

            $cursor->addMonth()->startOfMonth();
        }

        return $this->rolloverCalculator->calculateMultipleMonths(
            $months,
            (int) $agreement->rollover_months,
            $billExcessImmediately,
        );
    }

    /**
     * Build a cycle-pooled ledger for agreements that use native period
     * retainer terms (retainer_hours / retainer_fee).
     *
     * Each cycle's retainer is a single pool that is consumed across its
     * months. Excess hours and interim overages are computed against the
     * cycle pool rather than per-month monthly_retainer_hours, so interim
     * billing stays consistent with the final cadence reckoning.
     *
     * @param  array<string, float>  $hoursByDate  Billable hours summed per work date (Y-m-d). Date keys outside any cycle window are simply unused.
     * @return array<int, MonthSummary>
     */
    public function buildPeriodRetainerLedgerThrough(
        ClientAgreement $agreement,
        Carbon $ledgerEnd,
        array $hoursByDate,
        bool $billExcessImmediately,
    ): array {
        $ledger = [];

        foreach ($this->billingCycleResolver->cyclesForAgreement($agreement, $ledgerEnd) as $cycle) {
            $cyclePool = $this->retainerCalculator->cyclePeriodRetainerHours($agreement, $cycle);
            $cumulativeWorked = 0.0;
            $cumulativeExcess = 0.0;
            $cycleStartKey = $cycle->start->format('Y-m-d');

            $cursor = $cycle->start->copy()->startOfMonth();
            $lastMonth = $cycle->end->copy()->startOfMonth();
            if ($lastMonth->gt($ledgerEnd)) {
                $lastMonth = $ledgerEnd->copy()->startOfMonth();
            }

            $isFirstMonthOfCycle = true;

            while ($cursor->lte($lastMonth)) {
                $monthStart = $cursor->copy()->startOfMonth();
                $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();

                // Clip to the cycle's portion of this calendar month so adjacent
                // cycles sharing a boundary month don't claim each other's hours.
                $rangeStart = $monthStart->gt($cycle->start) ? $monthStart : $cycle->start->copy()->startOfDay();
                $rangeEnd = $monthEnd->lt($cycle->end) ? $monthEnd : $cycle->end->copy()->startOfDay();
                if ($rangeEnd->gt($ledgerEnd)) {
                    $rangeEnd = $ledgerEnd->copy();
                }

                $monthHoursWorked = 0.0;
                if ($rangeStart->lte($rangeEnd)) {
                    $dateCursor = $rangeStart->copy();
                    while ($dateCursor->lte($rangeEnd)) {
                        $monthHoursWorked += $hoursByDate[$dateCursor->format('Y-m-d')] ?? 0.0;
                        $dateCursor->addDay();
                    }
                }
                $monthHoursWorked = round($monthHoursWorked, 4);

                $openingPool = round(max(0.0, $cyclePool - $cumulativeWorked), 4);
                $cumulativeWorked = round($cumulativeWorked + $monthHoursWorked, 4);

                $monthFromRetainer = round(min($monthHoursWorked, $openingPool), 4);

                if ($billExcessImmediately) {
                    $newCumulativeExcess = round(max(0.0, $cumulativeWorked - $cyclePool), 4);
                    $monthExcess = round($newCumulativeExcess - $cumulativeExcess, 4);
                    $cumulativeExcess = $newCumulativeExcess;
                    $negativeBalance = 0.0;
                } else {
                    $monthExcess = 0.0;
                    $negativeBalance = round(max(0.0, $cumulativeWorked - $cyclePool), 4);
                }

                $closingPool = round(max(0.0, $cyclePool - $cumulativeWorked), 4);

                $monthRetainer = $isFirstMonthOfCycle ? $cyclePool : 0.0;
                $isFirstMonthOfCycle = false;

                $ledger[] = new MonthSummary(
                    opening: new OpeningBalance(
                        retainerHours: $monthRetainer,
                        rolloverHours: 0.0,
                        expiredHours: 0.0,
                        totalAvailable: $openingPool,
                        negativeOffset: 0.0,
                        invoicedNegativeBalance: 0.0,
                        effectiveRetainerHours: $monthRetainer,
                        remainingNegativeBalance: 0.0,
                    ),
                    closing: new ClosingBalance(
                        hoursUsedFromRetainer: $monthFromRetainer,
                        hoursUsedFromRollover: 0.0,
                        unusedHours: $closingPool,
                        excessHours: $monthExcess,
                        negativeBalance: $negativeBalance,
                        remainingRollover: 0.0,
                    ),
                    hoursWorked: $monthHoursWorked,
                    yearMonth: $cursor->format('Y-m'),
                    retainerHours: $monthRetainer,
                    billExcessImmediately: $billExcessImmediately,
                    cycleStart: $cycleStartKey,
                );

                $cursor->addMonth()->startOfMonth();
            }
        }

        return $ledger;
    }

    /**
     * @param  array<int, MonthSummary>  $ledger
     * @return array{
     *     retainer_hours: float,
     *     retainer_multiplier: float,
     *     covered_hours: float,
     *     hours_worked: float,
     *     rollover_hours_used: float,
     *     unused_hours: float,
     *     negative_hours: float,
     *     starting_unused_hours: float,
     *     starting_negative_hours: float
     * }
     */
    public function summarizeLedgerForCycle(ClientAgreement $agreement, array $ledger, BillingCycle $cycle): array
    {
        $cycleMonthStart = $this->cycleMonthStartForLegacyMonthlyLedger($agreement, $cycle);
        $cycleMonthEnd = $this->cycleMonthEndForLegacyMonthlyLedger($agreement, $cycle);
        $cycleStartKey = $cycle->start->format('Y-m-d');
        $cycleSummaries = collect($ledger)
            ->filter(function (MonthSummary $summary) use ($cycleMonthStart, $cycleMonthEnd, $cycleStartKey): bool {
                // For period-retainer rows, match by the owning cycle (boundary
                // months can appear in adjacent cycles' rows).
                if ($summary->cycleStart !== null) {
                    return $summary->cycleStart === $cycleStartKey;
                }

                $monthStart = Carbon::parse($summary->yearMonth.'-01')->startOfDay();

                return $monthStart->betweenIncluded($cycleMonthStart, $cycleMonthEnd);
            })
            ->values();

        /** @var MonthSummary|null $first */
        $first = $cycleSummaries->first();
        /** @var MonthSummary|null $last */
        $last = $cycleSummaries->last();

        if ($agreement->retainer_hours !== null) {
            $retainerHours = $this->retainerCalculator->cyclePeriodRetainerHours($agreement, $cycle);
            $hoursWorked = round((float) $cycleSummaries->sum('hoursWorked'), 4);
            $coveredHours = round(min($hoursWorked, $retainerHours), 4);

            return [
                'retainer_hours' => $retainerHours,
                'retainer_multiplier' => $this->retainerCalculator->cyclePeriodRetainerMultiplier($agreement, $cycle),
                'covered_hours' => $coveredHours,
                'hours_worked' => $hoursWorked,
                'rollover_hours_used' => 0.0,
                'unused_hours' => round(max(0.0, $retainerHours - $hoursWorked), 4),
                'negative_hours' => round(max(0.0, $hoursWorked - $retainerHours), 4),
                'starting_unused_hours' => 0.0,
                'starting_negative_hours' => 0.0,
            ];
        }

        $retainerHours = round((float) $cycleSummaries->sum('retainerHours'), 4);
        $monthlyRetainerHours = (float) $agreement->monthly_retainer_hours;

        return [
            'retainer_hours' => $retainerHours,
            'retainer_multiplier' => $monthlyRetainerHours > 0
                ? round($retainerHours / $monthlyRetainerHours, 4)
                : (float) $cycleSummaries->count(),
            'covered_hours' => round((float) $cycleSummaries->sum(
                fn (MonthSummary $summary): float => $summary->closing->hoursUsedFromRetainer
                    + $summary->closing->hoursUsedFromRollover
                    + $summary->opening->negativeOffset
            ), 4),
            'hours_worked' => round((float) $cycleSummaries->sum('hoursWorked'), 4),
            'rollover_hours_used' => round((float) $cycleSummaries->sum(
                fn (MonthSummary $summary): float => $summary->closing->hoursUsedFromRollover
            ), 4),
            'unused_hours' => $last
                ? round($last->closing->unusedHours + $last->closing->remainingRollover, 4)
                : 0.0,
            'negative_hours' => $last ? round($last->closing->negativeBalance, 4) : 0.0,
            'starting_unused_hours' => $first ? round($first->opening->rolloverHours, 4) : 0.0,
            'starting_negative_hours' => $first
                ? round($first->opening->negativeOffset + $first->opening->remainingNegativeBalance, 4)
                : 0.0,
        ];
    }

    /**
     * Return the first calendar-month row a legacy monthly ledger should count
     * for this cycle. Period-retainer ledgers carry cycle ownership directly;
     * legacy rows do not, so a shared mid-month boundary belongs to the cycle
     * ending in that calendar month unless the successor is a termination-
     * clipped final cycle inside that same calendar month.
     */
    public function cycleMonthStartForLegacyMonthlyLedger(ClientAgreement $agreement, BillingCycle $cycle): Carbon
    {
        $cycleMonthStart = $cycle->start->copy()->startOfMonth();
        $activeDate = Carbon::parse($agreement->active_date)->startOfDay();
        $terminationDate = $agreement->termination_date
            ? Carbon::parse($agreement->termination_date)->startOfDay()
            : null;
        $isTerminationClippedInsideStartMonth = $terminationDate !== null
            && $cycle->end->isSameDay($terminationDate)
            && $cycle->start->isSameMonth($cycle->end);

        if ($cycle->start->isSameDay($activeDate)
            || $cycle->start->isSameDay($cycleMonthStart)
            || $isTerminationClippedInsideStartMonth) {
            return $cycleMonthStart;
        }

        return $cycleMonthStart->addMonth()->startOfMonth();
    }

    /**
     * Return the final calendar-month row a legacy monthly ledger should count
     * for this cycle. When an agreement terminates inside the boundary month of
     * the next anchored cycle, that month moves to the truncated final cycle so
     * it is still counted exactly once.
     */
    public function cycleMonthEndForLegacyMonthlyLedger(ClientAgreement $agreement, BillingCycle $cycle, ?Carbon $through = null): Carbon
    {
        $cycleMonthEnd = ($through ?? $cycle->end)->copy()->startOfMonth();
        $terminationDate = $agreement->termination_date
            ? Carbon::parse($agreement->termination_date)->startOfDay()
            : null;

        if ($through !== null && $through->lt($cycle->end)) {
            return $cycleMonthEnd;
        }

        if ($terminationDate !== null
            && $terminationDate->gt($cycle->end)
            && $terminationDate->isSameMonth($cycle->end)) {
            return $cycleMonthEnd->subMonth()->startOfMonth();
        }

        return $cycleMonthEnd;
    }

    public function ledgerRowBelongsToCycleThrough(
        MonthSummary $summary,
        string $cycleStartKey,
        Carbon $cycleMonthStart,
        Carbon $periodMonthEnd,
    ): bool {
        if ($summary->cycleStart !== null) {
            return $summary->cycleStart === $cycleStartKey
                && Carbon::parse($summary->yearMonth.'-01')->startOfDay()->lte($periodMonthEnd);
        }

        $monthStart = Carbon::parse($summary->yearMonth.'-01')->startOfDay();

        return $monthStart->betweenIncluded($cycleMonthStart, $periodMonthEnd);
    }

    /**
     * @param  array<int, MonthSummary>  $ledger
     */
    public function findLedgerMonth(array $ledger, string $yearMonth, ?string $cycleStart = null): ?MonthSummary
    {
        $exact = null;
        $fallback = null;
        foreach ($ledger as $summary) {
            if ($summary->yearMonth !== $yearMonth) {
                continue;
            }
            if ($cycleStart !== null && $summary->cycleStart === $cycleStart) {
                $exact = $summary;
                break;
            }
            $fallback ??= $summary;
        }

        return $exact ?? $fallback;
    }
}
