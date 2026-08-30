<?php

namespace App\Services\Billing;

use App\Services\Billing\Balances\BillingCycle;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Billing\ReplayCadenceAgreement;
use App\Support\Billing\ReplayInvoiceLineSnapshot;
use App\Support\Billing\ReplayInvoiceSnapshot;
use App\Support\Billing\ReplayOpeningCapacityContext;
use App\Support\Billing\ReplayOpeningCapacityProof;
use App\Support\Billing\ReplayRecurringItemIncidence;
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
                    ReplaySnapshotValue::integer($line['source_minutes'] ?? null),
                    ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null),
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
                    ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null),
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
                    || (string) ($historical['quantity'] ?? '') !== $canonicalQuantity
                    || ReplaySnapshotValue::integer($historical['source_minutes'] ?? null) !== $minutes
                    || ReplaySnapshotValue::integer($generated['source_minutes'] ?? null) !== $minutes
                    || ReplaySnapshotValue::integer($historical['source_agreement_rate_minutes'] ?? null) !== $minutes
                    || ReplaySnapshotValue::integer($generated['source_agreement_rate_minutes'] ?? null) !== $minutes) {
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
     * @param  list<ReplayRecurringItemIncidence>  $incidences
     */
    public function openingRecurringItemIncidence(
        string $key,
        array $before,
        array $after,
        array $incidences,
    ): bool {
        [$companyId, $agreementId, $kind] = array_pad(explode('|', $key, 4), 4, '');
        if (! ctype_digit($companyId)
            || ! ctype_digit($agreementId)
            || $kind !== InvoiceKind::CadencePeriod->value
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
            ReplaySnapshotValue::integer($line['source_minutes'] ?? null),
            ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null),
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
            || ($line['line_date'] ?? '') === ''
            || ($line['hours'] ?? null) !== null
            || ! array_key_exists('source_minutes', $line)
            || ReplaySnapshotValue::integer($line['source_minutes'] ?? null) !== 0
            || ! array_key_exists('source_agreement_rate_minutes', $line)
            || ReplaySnapshotValue::integer($line['source_agreement_rate_minutes'] ?? null) !== 0) {
            return false;
        }

        $matchingIncidences = array_values(array_filter(
            $incidences,
            static fn (ReplayRecurringItemIncidence $incidence): bool => $incidence->companyId === (int) $companyId
                && $incidence->agreementId === (int) $agreementId
                && (string) $incidence->itemId === (string) $line['recurring_item_id']
                && $incidence->lineDate === (string) $line['line_date'],
        ));
        if (count($matchingIncidences) !== 1) {
            return false;
        }

        $incidence = $matchingIncidences[0];
        if ($incidence->taxable
            || ! $incidence->opensItem
            || $incidence->currency !== ReplaySnapshotValue::text($after['currency'] ?? null)
            || (int) ($line['unit_amount'] ?? 0) !== $incidence->unitAmount
            || (int) ($line['tax_amount'] ?? 0) !== $incidence->taxAmount
            || (int) ($line['total_amount'] ?? 0) !== $incidence->totalAmount
            || (string) ($line['description_hash'] ?? '') !== $incidence->descriptionHash
            || self::decimalString($line['quantity'] ?? null) !== self::decimalString($incidence->quantity)
            || (string) ($line['agreement_id'] ?? '') !== (string) $incidence->agreementId
            || (string) ($line['project_id'] ?? '') !== ''
            || (string) ($line['claimed_by'] ?? '') !== '') {
            return false;
        }

        $delta = $incidence->totalAmount;

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
            || ! self::allCanonicalBalanceLines(
                $generatedPrior,
                $agreementId,
                $after->servicePeriodEnd?->toDateString(),
            )
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
            || $afterPriorMinutes === null
            || ! self::allLinesBackedBySourceMinutes($historicalPrior)
            || ! self::allLinesBackedBySourceMinutes($generatedPrior)) {
            return null;
        }

        $movedMinutes = $beforeHourlyMinutes - $afterHourlyMinutes;
        $beforeHourlySourceMinutes = $beforeHourly->sourceMinutes;
        $afterHourlySourceMinutes = $afterHourly instanceof ReplayInvoiceLineSnapshot
            ? $afterHourly->sourceMinutes
            : 0;
        $beforeHourlyAgreementRateMinutes = $beforeHourly->agreementRateSourceMinutes;
        $afterHourlyAgreementRateMinutes = $afterHourly instanceof ReplayInvoiceLineSnapshot
            ? $afterHourly->agreementRateSourceMinutes
            : 0;
        if ($movedMinutes <= 0
            || ($maximumMinutes !== null && $movedMinutes > $maximumMinutes)
            || $afterPriorMinutes - $beforePriorMinutes !== $movedMinutes
            || $beforeHourlySourceMinutes === null
            || $afterHourlySourceMinutes === null
            || $beforeHourlyAgreementRateMinutes !== $beforeHourlySourceMinutes
            || $afterHourlyAgreementRateMinutes !== $afterHourlySourceMinutes
            || $beforeHourlySourceMinutes < 0
            || $afterHourlySourceMinutes < 0
            || $beforeHourlySourceMinutes > $beforeHourlyMinutes
            || $afterHourlySourceMinutes > $afterHourlyMinutes
            // An overage can include a carried ledger deficit that has no
            // direct time-entry pivot. That unchanged deficit is not what this
            // correction claims to explain: the exact decrease in source-backed
            // work must be the exact number of minutes moved into capacity.
            || $beforeHourlySourceMinutes - $afterHourlySourceMinutes !== $movedMinutes) {
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
     * @param  array<int, list<ReplayCadenceAgreement>>  $agreementsByCompany
     * @param  array<string, array<string, mixed>>  $expected
     * @param  array<string, array<string, mixed>>  $actual
     * @param  array<int, Carbon>  $anchors
     * @return array<string, true>
     */
    public function contractCadenceHistoryGapKeys(
        array $agreementsByCompany,
        array $expected,
        array $actual,
        array $anchors,
    ): array {
        $history = [];
        foreach ($expected as $key => $snapshot) {
            if (! in_array(ReplaySnapshotValue::text($snapshot['status'] ?? null), InvoiceStatus::live(), true)) {
                continue;
            }

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
                || ! isset($agreementsByCompany[(int) $companyId])) {
                continue;
            }

            // AgreementSelector includes every non-draft recurring agreement
            // that starts by one month after the pinned replay date. Prove that
            // complete eligible set here from the immutable repository result.
            // If a second agreement could have generated a cadence chain,
            // explaining only the rows the engine emitted would hide its omission.
            $selectionCeiling = Carbon::instance($anchor)->copy()->addMonthNoOverflow()->startOfDay();
            $eligibleAgreements = array_values(array_filter(
                $agreementsByCompany[(int) $companyId],
                static fn (ReplayCadenceAgreement $candidate): bool => $candidate->startsOn->lte($selectionCeiling),
            ));
            if (count($eligibleAgreements) !== 1) {
                continue;
            }

            $agreement = $eligibleAgreements[0];
            if ((string) $agreement->agreementId !== (string) $agreementIds[0]
                || $agreement->periodRetainerHours() <= 0
                || $agreement->periodRetainerFee() <= 0) {
                continue;
            }

            if ($agreement->startsOn->gt($anchor)) {
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
                    || ReplaySnapshotValue::text($row['snapshot']['status'] ?? null) !== InvoiceStatus::Draft->value
                    || ReplaySnapshotValue::text($row['snapshot']['currency'] ?? null) !== $agreement->currency
                    || $lineTotal !== ReplaySnapshotValue::integer($row['snapshot']['total_amount'] ?? null)
                    || $lineTax !== ReplaySnapshotValue::integer($row['snapshot']['tax_amount'] ?? null)
                    || $lineTotal - $lineTax !== ReplaySnapshotValue::integer($row['snapshot']['subtotal_amount'] ?? null)
                    || count(array_filter(
                        $lines,
                        static fn (array $line): bool => ReplaySnapshotValue::text($line['agreement_id'] ?? null) !== (string) $agreement->agreementId,
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
            // Use the same natural opening cycle as invoice generation. A
            // monthly agreement may start mid-month, but cycleContaining()
            // deliberately resolves that opening sale to the calendar month;
            // anchoring this proof to the literal start date would reject the
            // engine's valid prorated opening invoice.
            $nextStart = $this->billingCycleResolver
                ->cycleContaining($agreement, $agreement->startsOn)
                ->start
                ->copy()
                ->startOfDay();
            foreach ($cycles as $cycle) {
                $expectedEnd = $nextStart->copy()->addMonths($months)->subDay()->startOfDay();
                $expectedPeriodStart = $nextStart->copy()->subMonths($months)->startOfDay();
                $expectedPeriodEnd = $nextStart->copy()->subDay()->startOfDay();
                if (! $cycle['start']->isSameDay($nextStart)
                    || ! $cycle['end']->isSameDay($expectedEnd)
                    || ! $cycle['period_start']->isSameDay($expectedPeriodStart)
                    || ! $cycle['period_end']->isSameDay($expectedPeriodEnd)
                    || ! $this->hasOnlyConfiguredCadenceLines(
                        $agreement,
                        $cycle['start'],
                        $cycle['end'],
                        $cycle['period_start'],
                        $cycle['period_end'],
                        $cycle['lines'],
                        $this->maximumCapacityMinutesForPeriod(
                            $agreement,
                            $cycle['period_start'],
                            $cycle['period_end'],
                        ),
                    )) {
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
            // successor of the cycle containing the effective generation day.
            // A terminated agreement uses its end date when it predates the
            // replay anchor; active agreements use the anchor itself.
            // Command anchors are end-of-day timestamps. Billing cycles are
            // inclusive date ranges ending at start-of-day, so passing 23:59:59
            // makes the resolver see the next cycle. Normalize the proof to the
            // same calendar day before asking which cycle contains it.
            $anchorDay = Carbon::instance($anchor)->copy()->startOfDay();
            $terminationDay = $agreement->endsOn;
            $generationDay = $terminationDay instanceof CarbonInterface && $terminationDay->lt($anchorDay)
                ? $terminationDay
                : $anchorDay;
            $generationCycle = $this->billingCycleResolver->cycleContaining($agreement, $generationDay);
            $expectedLastStart = $generationCycle->end->copy()->addDay()->startOfDay();
            $expectedLastEnd = $expectedLastStart->copy()
                ->addMonths($agreement->cadence->monthsInCycle())
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
    private function hasOnlyConfiguredCadenceLines(
        ReplayCadenceAgreement $agreement,
        Carbon $cycleStart,
        Carbon $cycleEnd,
        Carbon $periodStart,
        Carbon $periodEnd,
        array $lines,
        ?int $maximumCapacityMinutes = null,
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
        if ($agreement->effectiveBillingCadence() === BillingCadence::Monthly) {
            // The monthly generator prorates both monthly values and period
            // overrides with the calendar-month contract. cyclePeriod... is
            // correct for multi-month cadence cycles but treats a calendar
            // opening cycle as full when the agreement starts mid-month.
            $retainerMultiplier = $this->retainerCalculator->monthRetainerMultiplier(
                $agreement,
                $cycleStart->copy()->startOfMonth()->startOfDay(),
                $cycleEnd->copy()->endOfMonth()->startOfDay(),
            );
            $retainerHours = round($agreement->periodRetainerHours() * $retainerMultiplier, 4);
            $expectedFee = (int) round(
                round($agreement->periodRetainerFee() * $retainerMultiplier, 2) * 100,
            );
        } else {
            $retainerHours = $agreement->periodHoursOverride !== null
                ? $this->retainerCalculator->cyclePeriodRetainerHours($agreement, $cycle)
                : array_sum(array_map(
                    fn (Carbon $monthStart): float => $this->retainerCalculator->retainerHoursForMonth(
                        $agreement,
                        $monthStart,
                        $monthStart->copy()->endOfMonth()->startOfDay(),
                    ),
                    $monthStarts,
                ));
            $retainerMultiplier = $agreement->periodHoursOverride !== null
                ? $this->retainerCalculator->cyclePeriodRetainerMultiplier($agreement, $cycle)
                : ($agreement->monthlyHours > 0
                    ? $retainerHours / $agreement->monthlyHours
                    : count($monthStarts));
            $expectedFee = (int) round($this->retainerCalculator->cycleRetainerFee(
                $agreement,
                $cycle,
                ['retainer_multiplier' => $retainerMultiplier],
            ) * 100);
        }
        $expectedHours = round($retainerHours, 4);

        $snapshots = array_map(ReplayInvoiceLineSnapshot::fromArray(...), $lines);
        $retainerLines = array_values(array_filter(
            $snapshots,
            static fn (ReplayInvoiceLineSnapshot $line): bool => $line->type === InvoiceLineType::Retainer->value,
        ));
        if ($expectedFee <= 0 && $expectedHours <= 0) {
            if ($retainerLines !== []) {
                return false;
            }
        } elseif (count($retainerLines) !== 1) {
            return false;
        } else {
            $retainer = $retainerLines[0];
            if ($retainer->unitAmount !== $expectedFee
                || $retainer->totalAmount !== $expectedFee
                || $retainer->taxAmount !== 0
                || self::decimalString($retainer->quantity) !== '1'
                || round($retainer->hours ?? 0.0, 4) !== round($expectedHours, 4)
                || $retainer->lineDate !== $cycleStart->toDateString()
                || $retainer->agreementId !== (string) $agreement->agreementId
                || ! $retainer->canonicalCadenceDescription
                || $retainer->recurringItemId !== ''
                || $retainer->projectId !== ''
                || $retainer->claimedBy !== ''
                || $retainer->sourceMinutes !== 0
                || $retainer->agreementRateSourceMinutes !== 0) {
                return false;
            }
        }

        $sourceFreeOverageMinutes = 0;
        $capacityDrawMinutes = 0;
        foreach ($snapshots as $line) {
            if ($line->type === InvoiceLineType::Retainer->value) {
                continue;
            }

            // This attribution is intentionally narrower than the invoice
            // generator. It can independently prove ordinary retainer draws
            // and agreement-rate overage, the only extra composition observed
            // in the omitted cadence. Recurring items, milestones, credits,
            // subcontractor rates, and any future line type need their own
            // source-backed proof; until then replay fails closed.
            if ($line->type === InvoiceLineType::AdditionalHours->value) {
                $minutes = $line->roundedHoursMinutes();
                $sourceMinutes = $line->sourceMinutes;
                $expectedLineDate = $agreement->cadence === BillingCadence::Monthly
                    ? $periodStart->toDateString()
                    : $periodEnd->toDateString();
                $maximumSourceFreeMinutes = $agreement->cadence === BillingCadence::Monthly
                    ? $agreement->catchUpThresholdMinutes
                    : 0;
                if ($minutes === null
                    || $minutes <= 0
                    || ! $line->quantityMatchesHours()
                    || $sourceMinutes === null
                    || $sourceMinutes < 0
                    || $sourceMinutes > $minutes
                    || $line->agreementRateSourceMinutes !== $sourceMinutes
                    || $minutes - $sourceMinutes > $maximumSourceFreeMinutes
                    || $line->agreementId !== (string) $agreement->agreementId
                    || ! $line->hasNoAuxiliaryOwnership()
                    || ! $line->canonicalCadenceOverageDescription
                    || $line->unitAmount !== $agreement->hourlyRateAmount
                    || $line->taxAmount !== 0
                    || $line->totalAmount !== MoneyService::hourlyAmount($minutes, $line->unitAmount)
                    || $line->lineDate !== $expectedLineDate) {
                    return false;
                }

                $sourceFreeOverageMinutes += $minutes - $sourceMinutes;

                continue;
            }

            if ($line->type === InvoiceLineType::PriorMonthRetainer->value) {
                if (! $line->isCanonicalCapacityDraw($agreement->agreementId, $periodEnd->toDateString())) {
                    return false;
                }

                $capacityDrawMinutes += $line->hoursMinutes() ?? 0;

                continue;
            }

            return false;
        }

        $maximumSourceFreeMinutes = $agreement->cadence === BillingCadence::Monthly
            ? $agreement->catchUpThresholdMinutes
            : 0;

        return $sourceFreeOverageMinutes <= $maximumSourceFreeMinutes
            && ($maximumCapacityMinutes === null || $capacityDrawMinutes <= $maximumCapacityMinutes);
    }

    /**
     * Conservative, configuration-derived ceiling for capacity usable by one
     * service period. This stays database-free: the repository has already
     * copied every persistence fact into the immutable agreement DTO.
     */
    private function maximumCapacityMinutesForPeriod(
        ReplayCadenceAgreement $agreement,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): int {
        if ($periodEnd->lt($agreement->startsOn)
            || ($agreement->endsOn instanceof CarbonInterface && $periodStart->gt($agreement->endsOn))) {
            return 0;
        }

        if ($agreement->usesPeriodRetainerTerms) {
            $monthStarts = [];
            $cursor = $periodStart->copy()->startOfMonth();
            while ($cursor->lte($periodEnd)) {
                $monthStarts[] = $cursor->copy();
                $cursor->addMonth()->startOfMonth();
            }

            $minutes = (int) round($this->retainerCalculator->cyclePeriodRetainerHours(
                $agreement,
                new BillingCycle(
                    start: $periodStart,
                    end: $periodEnd,
                    isProrated: false,
                    monthCount: count($monthStarts),
                    monthStarts: $monthStarts,
                ),
            ) * 60);

            // A monthly minimum-availability charge buys this bounded buffer
            // for the following period even when no source row is attached.
            return $minutes + ($agreement->cadence === BillingCadence::Monthly
                ? $agreement->catchUpThresholdMinutes
                : 0);
        }

        $minutes = 0;
        $firstCapacityMonth = $periodStart->copy()->startOfMonth()->subMonths($agreement->rolloverMonths);
        $cursor = $firstCapacityMonth;
        while ($cursor->lte($periodEnd)) {
            $monthStart = $cursor->copy()->startOfMonth();
            $minutes += (int) round($this->retainerCalculator->retainerHoursForMonth(
                $agreement,
                $monthStart,
                $monthStart->copy()->endOfMonth()->startOfDay(),
            ) * 60);
            $cursor->addMonth()->startOfMonth();
        }

        if ($agreement->initialRolloverMinutes > 0
            && $periodStart->copy()->startOfMonth()->diffInMonths($agreement->startsOn->startOfMonth()) <= $agreement->rolloverMonths) {
            $minutes += $agreement->initialRolloverMinutes;
        }

        if ($agreement->cadence === BillingCadence::Monthly) {
            $minutes += $agreement->catchUpThresholdMinutes;
        }

        return max(0, $minutes);
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

    /** @param list<ReplayInvoiceLineSnapshot> $lines */
    private static function allLinesBackedBySourceMinutes(array $lines): bool
    {
        foreach ($lines as $line) {
            $minutes = $line->hoursMinutes();
            if ($minutes === null
                || $line->sourceMinutes !== $minutes
                || $line->agreementRateSourceMinutes !== $minutes) {
                return false;
            }
        }

        return true;
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

    /** @param list<ReplayInvoiceLineSnapshot> $lines */
    private static function allCanonicalBalanceLines(
        array $lines,
        int $agreementId,
        ?string $servicePeriodEnd,
    ): bool {
        if ($servicePeriodEnd === null) {
            return false;
        }

        foreach ($lines as $line) {
            if (! $line->isCanonicalCapacityDraw($agreementId, $servicePeriodEnd)) {
                return false;
            }
        }

        return true;
    }
}
