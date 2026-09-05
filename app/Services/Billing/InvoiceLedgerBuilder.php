<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientTimeEntry;
use App\Services\Billing\Balances\BillingCycle;
use App\Services\Billing\Balances\ClosingBalance;
use App\Services\Billing\Balances\MonthSummary;
use App\Services\Billing\Balances\OpeningBalance;
use App\Support\Billing\BillingCadence;
use Carbon\Carbon;

class InvoiceLedgerBuilder
{
    private readonly ReplayHistoryBasis $replayHistoryBasis;

    public function __construct(
        private readonly RolloverCalculator $rolloverCalculator = new RolloverCalculator,
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly RetainerCalculator $retainerCalculator = new RetainerCalculator,
        private readonly TimeEntryProjectChainGuard $projectChainGuard = new TimeEntryProjectChainGuard,
        private readonly BilledOverageLedger $billedOverageLedger = new BilledOverageLedger,
        ?ReplayHistoryBasis $replayHistoryBasis = null,
    ) {
        $this->replayHistoryBasis = $replayHistoryBasis ?? new ReplayHistoryBasis;
    }

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
        $ledgerAgreement = $this->replayHistoryBasis->agreementForLedger($agreement);
        $activeDate = Carbon::parse($ledgerAgreement->active_date)->startOfDay();
        $terminationDate = $agreement->termination_date
            ? Carbon::parse($agreement->termination_date)->startOfDay()
            : null;
        $ledgerEnd = $through->copy()->startOfDay();

        if ($terminationDate && $terminationDate->lt($ledgerEnd)) {
            $ledgerEnd = $terminationDate->copy();
        }

        $isMonthly = $agreement->effectiveBillingCadence() === BillingCadence::Monthly;
        // @infection-ignore-all Monthly selection is covered by database-backed cadence and interim feature tests; the mutation lane deliberately runs unit tests only.
        $billedOveragesByMonth = $isMonthly
            ? $this->billedOverageLedger->hoursByMonthThrough($agreement, $ledgerEnd)
            : [];
        $calculationStart = $activeDate->copy()->startOfMonth();
        $firstBilledMonth = array_key_first($billedOveragesByMonth);
        if ($firstBilledMonth !== null) {
            $calculationStart = min($calculationStart, Carbon::parse($firstBilledMonth)->startOfDay());
        }

        if ($calculationStart->gt($ledgerEnd)) {
            return [];
        }

        $companyEntries = ClientTimeEntry::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->whereBetween('worked_on', [$activeDate, $ledgerEnd]);

        // Validate before applying the agreement's project scope. A malformed
        // entry can point at another company's project and thereby fall outside
        // one scoped agreement while being counted by another. Filtering first
        // would turn a data-integrity failure into a silent undercharge.
        $this->projectChainGuard->assertProjectChainsAgree($company, $companyEntries);

        $billableEntries = (clone $companyEntries)
            ->where('is_billable', true)
            ->deferredOnlyOnceAllocated()
            ->retainerBillable()
            ->forAgreementScope($agreement)
            ->get();

        if ($agreement->retainer_hours !== null) {
            /** @var array<string, float> $hoursByDate */
            $hoursByDate = [];
            foreach ($billableEntries as $entry) {
                $dateKey = Carbon::parse($entry->date_worked)->format('Y-m-d');
                $hoursByDate[$dateKey] = ($hoursByDate[$dateKey] ?? 0.0) + ((float) $entry->minutes_worked / 60);
            }

            // A monthly advance invoice can reconcile the month immediately
            // before activation. Period-retainer cycles begin at activation,
            // so carry that paid capacity into the first cycle rather than
            // dropping a bucket the cycle walker can never emit.
            // @infection-ignore-all Persisted charged-invoice selection and the active-cycle remap are covered together by the database-backed period-retainer ledger test; isolated mutation workers cannot safely share that fixture.
            $openingServiceMonthKey = $activeDate->copy()->subMonthNoOverflow()->format('Y-m');
            if (isset($billedOveragesByMonth[$openingServiceMonthKey])) {
                $activeMonthKey = $activeDate->format('Y-m');
                $billedOveragesByMonth[$activeMonthKey] = round(
                    ($billedOveragesByMonth[$activeMonthKey] ?? 0.0) + $billedOveragesByMonth[$openingServiceMonthKey],
                    4,
                );
                unset($billedOveragesByMonth[$openingServiceMonthKey]);
            }

            // BillingCycleResolver correctly uses the stored agreement start
            // everywhere else. Replay is the one exception: its ledger must
            // contain a historical opening cycle without changing which
            // cycles ordinary generation may sell or mutating the model row.
            return $this->buildPeriodRetainerLedgerThrough(
                $ledgerAgreement,
                $ledgerEnd,
                $hoursByDate,
                $billExcessImmediately,
                $billedOveragesByMonth,
            );
        }

        $entriesByMonth = $billableEntries
            ->groupBy(fn (ClientTimeEntry $entry): string => Carbon::parse($entry->date_worked)->format('Y-m'));
        $months = [];

        $cursor = $calculationStart->copy();
        while ($cursor->lte($ledgerEnd)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();
            $monthKey = $monthStart->format('Y-m');
            $monthEntries = $entriesByMonth->get($monthKey, collect());
            $isPreAgreement = $monthStart->lt($activeDate->copy()->startOfMonth());
            $months[] = [
                'year_month' => $monthKey,
                'retainer_hours' => $isPreAgreement
                    ? 0.0
                    : $this->retainerCalculator->retainerHoursForMonth($ledgerAgreement, $monthStart, $monthEnd),
                'hours_worked' => round($monthEntries->sum('minutes_worked') / 60, 4),
                // @infection-ignore-all The month-to-query join is feature-tested against persisted invoices; the mutation lane deliberately excludes database tests.
                'billed_overage_hours' => $billedOveragesByMonth[$monthKey] ?? 0.0,
                'reset_rollover' => false,
            ];

            $cursor->addMonth()->startOfMonth();
        }

        if ($months !== []) {
            $months = $this->withOpeningRollover($agreement, $months);
        }

        return $this->rolloverCalculator->calculateMultipleMonths(
            $months,
            (int) $agreement->rollover_months,
            $billExcessImmediately,
        );
    }

    /**
     * Grant the agreement's opening rollover as capacity that reaches its
     * first recorded month.
     *
     * The mechanism is a month of retainer with no work in it, placed
     * immediately before the agreement's recorded start, which
     * `RolloverCalculator` then carries forward. Adding the hours to the start
     * month directly would be simpler and is deliberately not what happens:
     * the carrier month makes the grant expire on the agreement's own
     * `rollover_months` policy, so an agreement that carries nothing forward
     * carries this forward neither. Granted in the start month, its remainder
     * would live one month longer than any other unused hour.
     *
     * The anchor is the agreement's **recorded** start, not the ledger's. When
     * a replay history basis has moved the ledger's opening back a period, the
     * carry-in still belongs where the predecessor recorded it, so the capacity
     * lands against the recorded start rather than a period earlier - which
     * would grant it before the history the basis exists to reproduce. Where
     * the basis already occupies the carrier month, the hours are added to that
     * month rather than duplicating its key.
     *
     * No agreement in the migrated source carries a non-zero initial rollover
     * (nine agreements, all zero, source and destination agreeing), so the
     * anchor choice is settled by what the column means rather than by any
     * history it can be checked against. The tests are the only exercise this
     * has.
     *
     * @param  non-empty-array<int, array{year_month: string, retainer_hours: float, hours_worked: float, billed_overage_hours?: float, reset_rollover: bool}>  $months
     * @return non-empty-array<int, array{year_month: string, retainer_hours: float, hours_worked: float, billed_overage_hours?: float, reset_rollover: bool}>
     */
    private function withOpeningRollover(ClientAgreement $agreement, array $months): array
    {
        $initialRolloverHours = $agreement->initial_rollover_hours;

        if ($initialRolloverHours <= 0) {
            return $months;
        }

        // `active_date` is the engine's name for `starts_on`, which is
        // `NOT NULL` (#147), so the walk's own start is no longer a fallback.
        $recordedStart = Carbon::parse($agreement->active_date);

        $carrierKey = $recordedStart->startOfMonth()->subMonth()->format('Y-m');

        foreach ($months as $index => $month) {
            if ($month['year_month'] === $carrierKey) {
                $months[$index]['retainer_hours'] = round(
                    $month['retainer_hours'] + $initialRolloverHours,
                    4,
                );

                return $months;
            }
        }

        // A ledger that ends before the carrier month has not reached the
        // recorded start at all, so there is no opening to grant. Prepending
        // regardless would put a later month at the front of a list the
        // rollover calculator reads in order.
        //
        // Non-emptiness is the caller's to establish, and it does so by type
        // rather than by a branch in here that no input could reach.
        if ($carrierKey > $months[array_key_last($months)]['year_month']) {
            return $months;
        }

        array_unshift($months, [
            'year_month' => $carrierKey,
            'retainer_hours' => round($initialRolloverHours, 4),
            'hours_worked' => 0.0,
            'reset_rollover' => false,
        ]);

        return $months;
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
     * @param  array<string, float>  $billedOveragesByMonth  Signed charged hours keyed by service month.
     * @return array<int, MonthSummary>
     *
     * @infection-ignore-all The period-retainer branch is exercised against persisted agreement, time, and charged-invoice rows in InvoiceLedgerBuilderTest; isolated mutation workers cannot safely share that database fixture.
     */
    public function buildPeriodRetainerLedgerThrough(
        ClientAgreement $agreement,
        Carbon $ledgerEnd,
        array $hoursByDate,
        bool $billExcessImmediately,
        array $billedOveragesByMonth = [],
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
                $monthKey = $cursor->format('Y-m');
                // Period retainers never roll capacity into a later cycle, so
                // a monthly charge adjusts this cycle's closing pool only.
                // Non-monthly agreements supply no billed-overage buckets and
                // retain their original cumulative-cycle calculation.
                $effectivePool = round($cyclePool + ($billedOveragesByMonth[$monthKey] ?? 0.0), 4);
                $netDeficit = round(max(0.0, $cumulativeWorked - $effectivePool), 4);

                $monthFromRetainer = round(min($monthHoursWorked, $openingPool), 4);

                if ($billExcessImmediately) {
                    $monthExcess = round(max(0.0, $netDeficit - $cumulativeExcess), 4);
                    $cumulativeExcess = $netDeficit;
                    $negativeBalance = 0.0;
                } else {
                    $monthExcess = 0.0;
                    $negativeBalance = $netDeficit;
                }
                $closingPool = round(max(0.0, $effectivePool - $cumulativeWorked), 4);

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
                    yearMonth: $monthKey,
                    retainerHours: $monthRetainer,
                    billExcessImmediately: $billExcessImmediately,
                    cycleStart: $cycleStartKey,
                    // The same charge that widened `$effectivePool` above, kept
                    // where a reader of the ledger can see it: the pool says
                    // what may be worked, this says what was bought.
                    billedOverageHours: $billedOveragesByMonth[$monthKey] ?? 0.0,
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
                'unused_hours' => $last ? $last->closing->unusedHours : 0.0,
                'negative_hours' => $last ? $last->closing->negativeBalance : 0.0,
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
