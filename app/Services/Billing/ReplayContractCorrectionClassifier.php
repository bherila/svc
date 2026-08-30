<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Services\Billing\Balances\BillingCycle;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\ReplayInvoiceLineSnapshot;
use App\Support\Billing\ReplayInvoiceSnapshot;
use App\Support\Billing\ReplayOpeningCapacityContext;
use App\Support\Billing\ReplayOpeningCapacityProof;
use App\Support\Billing\ReplaySnapshotValue;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Proves the narrow billing-contract differences an invoice replay may accept.
 *
 * These are counterfactual checks, not feature flags or opportunity
 * predicates. A proof must account for the complete monetary or structural
 * difference; anything it cannot prove remains a replay failure.
 */
final class ReplayContractCorrectionClassifier
{
    public function __construct(
        private readonly BillingCycleResolver $billingCycleResolver = new BillingCycleResolver,
        private readonly RetainerCalculator $retainerCalculator = new RetainerCalculator,
    ) {}

    /**
     * Did the source price decimal hours differently from exact whole minutes?
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function exactMinuteArithmetic(array $before, array $after): bool
    {
        if (($before['currency'] ?? null) !== ($after['currency'] ?? null)
            || ReplaySnapshotValue::integer($before['tax_amount'] ?? null)
                !== ReplaySnapshotValue::integer($after['tax_amount'] ?? null)) {
            return false;
        }

        /** @var list<array<string, mixed>> $beforeLines */
        $beforeLines = (array) ($before['lines'] ?? []);
        /** @var list<array<string, mixed>> $afterLines */
        $afterLines = (array) ($after['lines'] ?? []);

        $otherMoney = static function (array $lines): array {
            $signatures = [];
            foreach ($lines as $line) {
                if (($line['type'] ?? null) === 'additional_hours') {
                    continue;
                }
                $moneyBearing = (int) ($line['unit_amount'] ?? 0) !== 0
                    || (int) ($line['tax_amount'] ?? 0) !== 0
                    || (int) ($line['total_amount'] ?? 0) !== 0;
                $signature = implode('|', [
                    (string) ($line['type'] ?? ''),
                    (int) ($line['unit_amount'] ?? 0),
                    // Zero-value balance lines use quantity to display hours,
                    // not to price money. Whole-minute normalization may move
                    // that display field and the replay's acceptance contract
                    // explicitly reports rather than gates hour differences.
                    $moneyBearing ? (string) ($line['quantity'] ?? '') : '',
                    (int) ($line['tax_amount'] ?? 0),
                    (int) ($line['total_amount'] ?? 0),
                    (string) ($line['project_id'] ?? ''),
                    (string) ($line['agreement_id'] ?? ''),
                    (string) ($line['line_date'] ?? ''),
                    (string) ($line['recurring_item_id'] ?? ''),
                    (string) ($line['claimed_by'] ?? ''),
                    (string) ($line['identity_hash'] ?? ''),
                ]);
                $signatures[$signature] = ($signatures[$signature] ?? 0) + 1;
            }
            ksort($signatures);

            return $signatures;
        };

        if ($otherMoney($beforeLines) !== $otherMoney($afterLines)) {
            return false;
        }

        $hourly = static function (array $lines): array {
            $grouped = [];
            foreach ($lines as $line) {
                if (($line['type'] ?? null) !== 'additional_hours') {
                    continue;
                }
                $identity = implode('|', [
                    (int) ($line['unit_amount'] ?? 0),
                    (int) ($line['tax_amount'] ?? 0),
                    (string) ($line['project_id'] ?? ''),
                    (string) ($line['agreement_id'] ?? ''),
                    (string) ($line['line_date'] ?? ''),
                    (string) ($line['recurring_item_id'] ?? ''),
                    (string) ($line['claimed_by'] ?? ''),
                    (string) ($line['identity_hash'] ?? ''),
                    $line['hours'] === null ? 'null' : (string) round((float) $line['hours'], 4),
                ]);
                $grouped[$identity][] = $line;
            }
            ksort($grouped);
            foreach ($grouped as &$group) {
                usort($group, static fn (array $left, array $right): int => [
                    (int) ($left['total_amount'] ?? 0),
                    (string) ($left['quantity'] ?? ''),
                ] <=> [
                    (int) ($right['total_amount'] ?? 0),
                    (string) ($right['quantity'] ?? ''),
                ]);
            }
            unset($group);

            return $grouped;
        };

        $beforeHourly = $hourly($beforeLines);
        $afterHourly = $hourly($afterLines);
        if ($beforeHourly === [] || array_keys($beforeHourly) !== array_keys($afterHourly)) {
            return false;
        }

        $historicalRounded = false;
        $lineDelta = 0;
        foreach ($beforeHourly as $identity => $historicalLines) {
            $generatedLines = $afterHourly[$identity];
            if (count($historicalLines) !== count($generatedLines)) {
                return false;
            }

            foreach ($historicalLines as $index => $historical) {
                $generated = $generatedLines[$index];
                $hours = $generated['hours'] ?? null;
                $rate = (int) ($generated['unit_amount'] ?? 0);
                if ($hours === null || $rate <= 0
                    || (int) ($historical['unit_amount'] ?? 0) !== $rate
                    || (int) ($historical['tax_amount'] ?? 0) !== 0
                    || (int) ($generated['tax_amount'] ?? 0) !== 0
                    || round((float) ($historical['hours'] ?? 0), 4) !== round((float) $hours, 4)) {
                    return false;
                }

                $minutes = (int) round((float) $hours * 60);
                if ($minutes <= 0) {
                    return false;
                }

                $exact = MoneyService::hourlyAmount($minutes, $rate);
                $historicalTotal = (int) ($historical['total_amount'] ?? 0);
                $canonicalQuantity = self::decimalString(HoursQuantity::decimal((float) $hours));
                if ((int) ($generated['total_amount'] ?? 0) !== $exact
                    || (string) ($generated['quantity'] ?? '') !== $canonicalQuantity
                    || (string) ($historical['quantity'] ?? '') !== $canonicalQuantity) {
                    return false;
                }

                if ($historicalTotal !== $exact && abs($historicalTotal - $exact) !== 1) {
                    return false;
                }

                $historicalRounded = $historicalRounded || $historicalTotal !== $exact;
                $lineDelta += $exact - $historicalTotal;
            }
        }

        if (! $historicalRounded) {
            return false;
        }

        return ReplaySnapshotValue::integer($after['subtotal_amount'] ?? null)
                - ReplaySnapshotValue::integer($before['subtotal_amount'] ?? null) === $lineDelta
            && ReplaySnapshotValue::integer($after['total_amount'] ?? null)
                - ReplaySnapshotValue::integer($before['total_amount'] ?? null) === $lineDelta;
    }

    /**
     * Did history omit the recurring item's contractually due first incidence?
     *
     * Exactly one line may be added, every other monetary line must remain
     * equivalent, and the added line must be the biller's exact output for an
     * active item whose configured start date is that incidence date.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function openingRecurringItemIncidence(Workspace $workspace, string $key, array $before, array $after): bool
    {
        [$companyId, $agreementId, $kind, $identity] = array_pad(explode('|', $key, 4), 4, '');
        if ($agreementId === '' || $agreementId === 'none' || $kind !== InvoiceKind::CadencePeriod->value
            || ($before['currency'] ?? null) !== ($after['currency'] ?? null)
            || ReplaySnapshotValue::integer($before['tax_amount'] ?? null)
                !== ReplaySnapshotValue::integer($after['tax_amount'] ?? null)) {
            return false;
        }

        $signatureOf = static fn (array $line): string => implode('|', [
            (string) ($line['type'] ?? ''),
            (int) ($line['unit_amount'] ?? 0),
            (string) ($line['quantity'] ?? ''),
            (int) ($line['tax_amount'] ?? 0),
            (int) ($line['total_amount'] ?? 0),
            (string) ($line['project_id'] ?? ''),
            (string) ($line['agreement_id'] ?? ''),
            (string) ($line['line_date'] ?? ''),
            (string) ($line['recurring_item_id'] ?? ''),
            (string) ($line['claimed_by'] ?? ''),
            (string) ($line['description_hash'] ?? ''),
        ]);

        $tally = static function (array $lines) use ($signatureOf): array {
            $counts = [];
            $representatives = [];
            foreach ($lines as $line) {
                $signature = $signatureOf($line);
                $counts[$signature] = ($counts[$signature] ?? 0) + 1;
                $representatives[$signature] = $line;
            }
            ksort($counts);

            return [$counts, $representatives];
        };

        /** @var list<array<string, mixed>> $beforeLines */
        $beforeLines = (array) ($before['lines'] ?? []);
        /** @var list<array<string, mixed>> $afterLines */
        $afterLines = (array) ($after['lines'] ?? []);
        [$beforeCounts] = $tally($beforeLines);
        [$afterCounts, $afterRepresentatives] = $tally($afterLines);

        $added = [];
        foreach (array_unique([...array_keys($beforeCounts), ...array_keys($afterCounts)]) as $signature) {
            $delta = ($afterCounts[$signature] ?? 0) - ($beforeCounts[$signature] ?? 0);
            if ($delta < 0 || $delta > 1) {
                return false;
            }
            if ($delta === 1) {
                $added[] = $afterRepresentatives[$signature];
            }
        }
        if (count($added) !== 1) {
            return false;
        }

        $line = $added[0];
        if (($line['type'] ?? null) !== 'recurring_item'
            || ($line['recurring_item_id'] ?? '') === ''
            || ($line['line_date'] ?? '') === '') {
            return false;
        }

        $cycleStartText = ReplaySnapshotValue::text($after['cycle_start'] ?? null);
        $cycleEndText = ReplaySnapshotValue::text($after['cycle_end'] ?? null);
        if ($cycleStartText === '' || $cycleEndText === '' || $cycleStartText === '?' || $cycleEndText === '?') {
            return false;
        }

        $company = ClientCompany::query()
            ->whereKey((int) $companyId)
            ->where('workspace_id', $workspace->id)
            ->first();
        if (! $company instanceof ClientCompany) {
            return false;
        }
        $agreement = ClientAgreement::query()
            ->whereKey((int) $agreementId)
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->first();
        if (! $agreement instanceof ClientAgreement) {
            return false;
        }

        $item = $agreement->recurringItems()
            ->whereKey((int) $line['recurring_item_id'])
            ->where('workspace_id', $workspace->id)
            ->where('is_active', true)
            ->first();
        if ($item === null || (bool) $item->is_taxable
            || (string) $item->currency !== ReplaySnapshotValue::text($after['currency'] ?? null)) {
            return false;
        }

        $lineDate = Carbon::parse((string) $line['line_date'])->startOfDay();
        $cycleStart = Carbon::parse($cycleStartText)->startOfDay();
        $cycleEnd = Carbon::parse($cycleEndText)->startOfDay();
        $itemStart = Carbon::instance($item->start_date ?? $agreement->starts_on ?? $cycleStart)->startOfDay();
        if (! $lineDate->isSameDay($itemStart)
            || $lineDate->lt($cycleStart)
            || $lineDate->gt($cycleEnd)) {
            return false;
        }

        $agreement->setRelation(
            'recurringItems',
            $agreement->recurringItems()->where('workspace_id', $workspace->id)->get(),
        );
        $biller = new RecurringItemBiller;
        $incidence = collect($biller->linesForCycle($agreement, $cycleStart, $cycleEnd))
            ->first(fn (array $candidate): bool => (int) $candidate['item']->id === (int) $item->id
                && $candidate['line_date']->isSameDay($lineDate));
        if (! is_array($incidence)) {
            return false;
        }

        $expectedLine = $biller->buildLine($incidence);
        if ((int) ($line['unit_amount'] ?? 0) !== (int) $expectedLine->unit_amount
            || (int) ($line['tax_amount'] ?? 0) !== (int) $expectedLine->tax_amount
            || (int) ($line['total_amount'] ?? 0) !== (int) $expectedLine->total_amount
            || (string) ($line['quantity'] ?? '') !== self::decimalString($expectedLine->quantity)
            || (string) ($line['agreement_id'] ?? '') !== (string) $agreement->id
            || (string) ($line['project_id'] ?? '') !== ''
            || (string) ($line['claimed_by'] ?? '') !== '') {
            return false;
        }

        $delta = (int) $expectedLine->total_amount;

        return ReplaySnapshotValue::integer($after['subtotal_amount'] ?? null)
                - ReplaySnapshotValue::integer($before['subtotal_amount'] ?? null) === $delta
            && ReplaySnapshotValue::integer($after['total_amount'] ?? null)
                - ReplaySnapshotValue::integer($before['total_amount'] ?? null) === $delta;
    }

    /**
     * Did predecessor history charge for the replay-only opening retainer but
     * omit its capacity from later allocation?
     *
     * This proof is deliberately database-free. The command supplies a context
     * built from the already captured opening invoice, and these immutable
     * snapshots must show a minute-for-minute move from priced overage to a
     * zero-value prior-retainer draw. Every other line stays byte-equivalent.
     */
    public function historyOmittedOpeningCapacity(
        ReplayOpeningCapacityContext $context,
        ReplayInvoiceSnapshot $before,
        ReplayInvoiceSnapshot $after,
    ): ?ReplayOpeningCapacityProof {
        return $this->proveCapacityReallocation(
            agreementId: $context->agreementId,
            currency: $context->currency,
            retainerAmount: $context->retainerAmount,
            maximumMinutes: $context->capacityMinutes,
            before: $before,
            after: $after,
        );
    }

    /**
     * Did capacity move from priced overage to zero-value balance lines without
     * changing its contract, rate, tax, or any unrelated charge?
     *
     * Unlike the opening-history proof, this makes no claim about which ledger
     * correction caused the move. It only lets the established rollover,
     * deferred-work, and project-scope facts evaluate a proved reallocation.
     */
    public function capacityReallocatedAtSameRate(
        ReplayInvoiceSnapshot $before,
        ReplayInvoiceSnapshot $after,
    ): bool {
        $retainers = $before->linesOfType('retainer');
        if (count($retainers) !== 1 || ! ctype_digit($retainers[0]->agreementId)) {
            return false;
        }

        return $this->proveCapacityReallocation(
            agreementId: (int) $retainers[0]->agreementId,
            currency: $before->currency,
            retainerAmount: $retainers[0]->totalAmount,
            maximumMinutes: null,
            before: $before,
            after: $after,
        ) instanceof ReplayOpeningCapacityProof;
    }

    private function proveCapacityReallocation(
        int $agreementId,
        string $currency,
        int $retainerAmount,
        ?int $maximumMinutes,
        ReplayInvoiceSnapshot $before,
        ReplayInvoiceSnapshot $after,
    ): ?ReplayOpeningCapacityProof {
        if (($maximumMinutes !== null && $maximumMinutes <= 0)
            || $before->currency !== $currency
            || $after->currency !== $currency
            || $before->taxAmount !== $after->taxAmount
            || $before->lineMultisetExcluding(['additional_hours', 'prior_month_retainer', 'retainer'])
                !== $after->lineMultisetExcluding(['additional_hours', 'prior_month_retainer', 'retainer'])) {
            return null;
        }

        $historicalHourly = $before->linesOfType('additional_hours');
        $generatedHourly = $after->linesOfType('additional_hours');
        $historicalPrior = $before->linesOfType('prior_month_retainer');
        $generatedPrior = $after->linesOfType('prior_month_retainer');
        $historicalRetainer = $before->linesOfType('retainer');
        $generatedRetainer = $after->linesOfType('retainer');
        if (count($historicalHourly) !== 1
            || count($generatedHourly) > 1
            || count($historicalRetainer) !== 1
            || count($generatedRetainer) !== 1) {
            return null;
        }

        $beforeHourly = $historicalHourly[0];
        $afterHourly = $generatedHourly[0] ?? null;
        if ($beforeHourly->agreementId !== (string) $agreementId
            || $beforeHourly->unitAmount <= 0
            || $beforeHourly->taxAmount !== 0
            || ($afterHourly instanceof ReplayInvoiceLineSnapshot
                && ($beforeHourly->allocationIdentity() !== $afterHourly->allocationIdentity()
                    || $afterHourly->taxAmount !== 0))
            || ! self::allocationMultisetIsSubset($historicalPrior, $generatedPrior)
            || ! self::allZeroValueBalanceLines($historicalPrior, $agreementId)
            || ! self::allZeroValueBalanceLines($generatedPrior, $agreementId)
            || $historicalRetainer[0]->contractSignature() !== $generatedRetainer[0]->contractSignature()
            || $historicalRetainer[0]->agreementId !== (string) $agreementId
            || $historicalRetainer[0]->totalAmount !== $retainerAmount) {
            return null;
        }

        $beforeHourlyMinutes = self::pricedMinutes($beforeHourly);
        $afterHourlyMinutes = $afterHourly instanceof ReplayInvoiceLineSnapshot
            ? self::pricedMinutes($afterHourly)
            : 0;
        $beforePriorMinutes = self::totalHoursMinutes($historicalPrior);
        $afterPriorMinutes = self::totalHoursMinutes($generatedPrior);
        if ($beforeHourlyMinutes === null
            || $afterHourlyMinutes === null
            || $beforePriorMinutes === null
            || $afterPriorMinutes === null) {
            return null;
        }

        $movedMinutes = $beforeHourlyMinutes - $afterHourlyMinutes;
        if ($movedMinutes <= 0
            || ($maximumMinutes !== null && $movedMinutes > $maximumMinutes)
            || $afterPriorMinutes - $beforePriorMinutes !== $movedMinutes) {
            return null;
        }

        $historicalHourlyTotal = MoneyService::hourlyAmount($beforeHourlyMinutes, $beforeHourly->unitAmount);
        $generatedHourlyTotal = $afterHourly instanceof ReplayInvoiceLineSnapshot
            ? MoneyService::hourlyAmount($afterHourlyMinutes, $afterHourly->unitAmount)
            : 0;
        $generatedLineTotal = $afterHourly instanceof ReplayInvoiceLineSnapshot
            ? $afterHourly->totalAmount
            : 0;
        $historicalRoundingDelta = $beforeHourly->totalAmount - $historicalHourlyTotal;
        if (abs($historicalRoundingDelta) > 1
            || $generatedLineTotal !== $generatedHourlyTotal) {
            return null;
        }

        $moneyDelta = $generatedLineTotal - $beforeHourly->totalAmount;

        if ($moneyDelta >= 0
            || $after->subtotalAmount - $before->subtotalAmount !== $moneyDelta
            || $after->totalAmount - $before->totalAmount !== $moneyDelta) {
            return null;
        }

        return new ReplayOpeningCapacityProof(
            movedMinutes: $movedMinutes,
            moneyDelta: $moneyDelta,
            alsoCorrectsHistoricalMinuteRounding: $historicalRoundingDelta !== 0,
        );
    }

    /**
     * Identify a complete generated cadence chain predecessor history omitted.
     *
     * A lone extra invoice is never waived. The company must have predecessor
     * history, but only ad-hoc invoices; every generated machine invoice must
     * belong to one recurring retainer agreement; and the generated cycles
     * must be the contiguous sequence beginning at the recorded start.
     *
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @param  array<int, Carbon>  $anchors
     * @return array<string, true>
     */
    public function contractCadenceHistoryGapKeys(
        Workspace $workspace,
        array $expected,
        array $actual,
        array $anchors,
    ): array {
        $history = [];
        foreach ($expected as $key => $snapshot) {
            [$companyId] = array_pad(explode('|', $key, 2), 2, '');
            $kind = ReplaySnapshotValue::text($snapshot['invoice_kind'] ?? null);
            $history[$companyId][$kind === InvoiceKind::AdHoc->value ? 'ad_hoc' : 'machine'] =
                ($history[$companyId][$kind === InvoiceKind::AdHoc->value ? 'ad_hoc' : 'machine'] ?? 0) + 1;
        }

        $unexpectedByCompany = [];
        foreach ($actual as $key => $snapshot) {
            if (isset($expected[$key]) || ($snapshot['invoice_kind'] ?? null) === InvoiceKind::AdHoc->value) {
                continue;
            }
            [$companyId, $agreementId, $kind, $identity] = array_pad(explode('|', $key, 4), 4, '');
            $unexpectedByCompany[$companyId][] = compact('key', 'agreementId', 'kind', 'identity', 'snapshot');
        }

        if ($unexpectedByCompany === []) {
            return [];
        }

        $companyIds = array_values(array_unique(array_map('intval', array_keys($unexpectedByCompany))));
        $workspaceCompanyIds = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $companyIds)
            ->pluck('id')
            ->map(ReplaySnapshotValue::integer(...))
            ->all();
        $agreementsByCompany = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $workspaceCompanyIds)
            ->whereIn('status', ['active', 'paused', 'terminated', 'expired'])
            ->whereNotNull('starts_on')
            ->get()
            ->groupBy('client_company_id');

        $explained = [];
        foreach ($unexpectedByCompany as $companyId => $rows) {
            if (($history[$companyId]['ad_hoc'] ?? 0) === 0 || ($history[$companyId]['machine'] ?? 0) !== 0
                || count($rows) < 2) {
                continue;
            }

            $agreementIds = array_values(array_unique(array_column($rows, 'agreementId')));
            if (count($agreementIds) !== 1 || $agreementIds[0] === '' || $agreementIds[0] === 'none') {
                continue;
            }

            $anchor = $anchors[(int) $companyId] ?? null;
            if (! $anchor instanceof CarbonInterface
                || ! in_array((int) $companyId, $workspaceCompanyIds, true)) {
                continue;
            }

            // AgreementSelector includes every non-draft recurring agreement
            // that starts by one month after the pinned replay date. Prove that
            // complete eligible set here from one eager query: if a second
            // agreement could have generated a cadence chain, explaining only
            // the rows the engine happened to emit would hide its omission.
            $selectionCeiling = Carbon::instance($anchor)->copy()->addMonth()->startOfDay();
            $eligibleAgreements = $agreementsByCompany->get((int) $companyId, collect())
                ->filter(static fn (ClientAgreement $candidate): bool => $candidate->billsOnARecurringCadence()
                    && $candidate->starts_on !== null
                    && Carbon::instance($candidate->starts_on)->startOfDay()->lte($selectionCeiling))
                ->values();
            if ($eligibleAgreements->count() !== 1) {
                continue;
            }

            $agreement = $eligibleAgreements->first();
            if (! $agreement instanceof ClientAgreement || $agreement->starts_on === null
                || (string) $agreement->id !== (string) $agreementIds[0]
                || ! $agreement->billsOnARecurringCadence()
                || $agreement->periodRetainerHours() <= 0
                || $agreement->periodRetainerFee() <= 0) {
                continue;
            }

            if (Carbon::instance($agreement->starts_on)->startOfDay()->gt($anchor)) {
                continue;
            }

            $cycles = [];
            $valid = true;
            foreach ($rows as $row) {
                /** @var list<array<string, mixed>> $lines */
                $lines = (array) ($row['snapshot']['lines'] ?? []);
                $lineTotal = array_sum(array_map(
                    static fn (array $line): int => ReplaySnapshotValue::integer($line['total_amount'] ?? null),
                    $lines,
                ));
                $lineTax = array_sum(array_map(
                    static fn (array $line): int => ReplaySnapshotValue::integer($line['tax_amount'] ?? null),
                    $lines,
                ));
                if ($row['kind'] !== InvoiceKind::CadencePeriod->value
                    || ReplaySnapshotValue::integer($row['snapshot']['total_amount'] ?? null) <= 0
                    || $lines === []
                    || ReplaySnapshotValue::text($row['snapshot']['currency'] ?? null) !== (string) $agreement->currency
                    || $lineTotal !== ReplaySnapshotValue::integer($row['snapshot']['total_amount'] ?? null)
                    || $lineTax !== ReplaySnapshotValue::integer($row['snapshot']['tax_amount'] ?? null)
                    || $lineTotal - $lineTax !== ReplaySnapshotValue::integer($row['snapshot']['subtotal_amount'] ?? null)
                    || count(array_filter(
                        $lines,
                        static fn (array $line): bool => ReplaySnapshotValue::text($line['agreement_id'] ?? null) !== (string) $agreement->id,
                    )) > 0) {
                    $valid = false;
                    break;
                }
                [$cycle, $period] = array_pad(explode('@', (string) $row['identity'], 2), 2, '');
                [$cycleStart, $cycleEnd] = array_pad(explode('..', $cycle, 2), 2, '');
                [$periodStart, $periodEnd] = array_pad(explode('..', $period, 2), 2, '');
                if (in_array('', [$cycleStart, $cycleEnd, $periodStart, $periodEnd], true)
                    || in_array('?', [$cycleStart, $cycleEnd, $periodStart, $periodEnd], true)) {
                    $valid = false;
                    break;
                }
                $cycles[] = [
                    'key' => $row['key'],
                    'start' => Carbon::parse($cycleStart)->startOfDay(),
                    'end' => Carbon::parse($cycleEnd)->startOfDay(),
                    'period_start' => Carbon::parse($periodStart)->startOfDay(),
                    'period_end' => Carbon::parse($periodEnd)->startOfDay(),
                    'lines' => $lines,
                ];
            }
            if (! $valid) {
                continue;
            }

            usort($cycles, static fn (array $left, array $right): int => $left['start'] <=> $right['start']);
            $months = $agreement->effectiveBillingCadence()->monthsInCycle();
            $nextStart = Carbon::instance($agreement->starts_on)->startOfDay();
            foreach ($cycles as $cycle) {
                $expectedEnd = $nextStart->copy()->addMonths($months)->subDay()->startOfDay();
                $expectedPeriodStart = $nextStart->copy()->subMonths($months)->startOfDay();
                $expectedPeriodEnd = $nextStart->copy()->subDay()->startOfDay();
                if (! $cycle['start']->isSameDay($nextStart)
                    || ! $cycle['end']->isSameDay($expectedEnd)
                    || ! $cycle['period_start']->isSameDay($expectedPeriodStart)
                    || ! $cycle['period_end']->isSameDay($expectedPeriodEnd)
                    || ! $this->hasConfiguredRetainerLine($agreement, $cycle['start'], $cycle['end'], $cycle['lines'])) {
                    $valid = false;
                    break;
                }
                $nextStart = $expectedEnd->copy()->addDay()->startOfDay();
            }
            if (! $valid) {
                continue;
            }

            // A contiguous prefix is not necessarily the complete output the
            // pinned replay should have generated. The generator bills one
            // cycle in advance, so the last accepted cycle must be exactly the
            // successor of the cycle containing the per-company replay anchor.
            // This exact boundary is also what makes a terminated agreement
            // safe to prove: termination changes where generation stops, but it
            // cannot turn a plausible prefix into the complete anchored chain.
            // Command anchors are end-of-day timestamps. Billing cycles are
            // inclusive date ranges ending at start-of-day, so passing 23:59:59
            // makes the resolver see the next cycle. Normalize the proof to the
            // same calendar day before asking which cycle contains it.
            $anchorDay = Carbon::instance($anchor)->copy()->startOfDay();
            $anchorCycle = $this->billingCycleResolver->cycleContaining($agreement, $anchorDay);
            $expectedLastStart = $anchorCycle->end->copy()->addDay()->startOfDay();
            $expectedLastEnd = $expectedLastStart->copy()
                ->addMonths($agreement->effectiveBillingCadence()->monthsInCycle())
                ->subDay()
                ->startOfDay();
            $lastCycle = $cycles[count($cycles) - 1];
            if (! $lastCycle['start']->isSameDay($expectedLastStart)
                || ! $lastCycle['end']->isSameDay($expectedLastEnd)) {
                continue;
            }

            foreach ($cycles as $cycle) {
                $explained[(string) $cycle['key']] = true;
            }
        }

        return $explained;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function hasConfiguredRetainerLine(
        ClientAgreement $agreement,
        Carbon $cycleStart,
        Carbon $cycleEnd,
        array $lines,
    ): bool {
        $monthStarts = [];
        $cursor = $cycleStart->copy()->startOfMonth();
        while ($cursor->lte($cycleEnd)) {
            $monthStarts[] = $cursor->copy();
            $cursor->addMonth()->startOfMonth();
        }
        $cycle = new BillingCycle(
            start: $cycleStart,
            end: $cycleEnd,
            isProrated: false,
            monthCount: count($monthStarts),
            monthStarts: $monthStarts,
        );
        $retainerHours = $agreement->retainer_hours !== null
            ? $this->retainerCalculator->cyclePeriodRetainerHours($agreement, $cycle)
            : array_sum(array_map(
                fn (Carbon $monthStart): float => $this->retainerCalculator->retainerHoursForMonth(
                    $agreement,
                    $monthStart,
                    $monthStart->copy()->endOfMonth()->startOfDay(),
                ),
                $monthStarts,
            ));
        $retainerMultiplier = $agreement->retainer_hours !== null
            ? $this->retainerCalculator->cyclePeriodRetainerMultiplier($agreement, $cycle)
            : ($agreement->monthly_retainer_hours > 0
                ? $retainerHours / $agreement->monthly_retainer_hours
                : count($monthStarts));
        $expectedFee = (int) round($this->retainerCalculator->cycleRetainerFee(
            $agreement,
            $cycle,
            ['retainer_multiplier' => $retainerMultiplier],
        ) * 100);
        $expectedHours = round($retainerHours, 4);

        $retainerLines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => ($line['type'] ?? null) === 'retainer',
        ));
        if ($expectedFee <= 0 && $expectedHours <= 0) {
            return $retainerLines === [];
        }
        if (count($retainerLines) !== 1) {
            return false;
        }

        $line = $retainerLines[0];

        return ReplaySnapshotValue::integer($line['unit_amount'] ?? null) === $expectedFee
            && ReplaySnapshotValue::integer($line['total_amount'] ?? null) === $expectedFee
            && ReplaySnapshotValue::integer($line['tax_amount'] ?? null) === 0
            && self::decimalString($line['quantity'] ?? null) === '1'
            && round(ReplaySnapshotValue::number($line['hours'] ?? null) ?? 0.0, 4) === round($expectedHours, 4)
            && ReplaySnapshotValue::text($line['line_date'] ?? null) === $cycleStart->toDateString();
    }

    private static function decimalString(mixed $value): string
    {
        $text = trim(ReplaySnapshotValue::text($value));
        if ($text === '') {
            return '0';
        }
        if (! str_contains($text, '.')) {
            return $text;
        }

        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    private static function pricedMinutes(ReplayInvoiceLineSnapshot $line): ?int
    {
        $hours = $line->hoursMinutes();
        $quantity = $line->quantityMinutes();

        return $hours !== null && $hours === $quantity ? $hours : null;
    }

    /** @param list<ReplayInvoiceLineSnapshot> $lines */
    private static function totalHoursMinutes(array $lines): ?int
    {
        $total = 0;
        foreach ($lines as $line) {
            $minutes = $line->hoursMinutes();
            if ($minutes === null) {
                return null;
            }
            $total += $minutes;
        }

        return $total;
    }

    /**
     * @param  list<ReplayInvoiceLineSnapshot>  $lines
     * @return array<string, int>
     */
    private static function allocationMultiset(array $lines): array
    {
        $multiset = [];
        foreach ($lines as $line) {
            $identity = $line->allocationIdentity();
            $multiset[$identity] = ($multiset[$identity] ?? 0) + 1;
        }
        ksort($multiset);

        return $multiset;
    }

    /**
     * @param  list<ReplayInvoiceLineSnapshot>  $subset
     * @param  list<ReplayInvoiceLineSnapshot>  $superset
     */
    private static function allocationMultisetIsSubset(array $subset, array $superset): bool
    {
        $available = self::allocationMultiset($superset);
        foreach (self::allocationMultiset($subset) as $identity => $count) {
            if (($available[$identity] ?? 0) < $count) {
                return false;
            }
        }

        return true;
    }

    /** @param list<ReplayInvoiceLineSnapshot> $lines */
    private static function allZeroValueBalanceLines(array $lines, int $agreementId): bool
    {
        foreach ($lines as $line) {
            if ($line->agreementId !== (string) $agreementId
                || $line->unitAmount !== 0
                || $line->taxAmount !== 0
                || $line->totalAmount !== 0) {
                return false;
            }
        }

        return true;
    }
}
