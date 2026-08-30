<?php

namespace App\Services\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Support\Billing\HoursQuantity;
use App\Support\Billing\InvoiceKind;
use Carbon\Carbon;

/**
 * Proves the narrow billing-contract differences an invoice replay may accept.
 *
 * These are counterfactual checks, not feature flags or opportunity
 * predicates. A proof must account for the complete monetary or structural
 * difference; anything it cannot prove remains a replay failure.
 */
final class ReplayContractCorrectionClassifier
{
    /**
     * Did the source price decimal hours differently from exact whole minutes?
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function exactMinuteArithmetic(array $before, array $after): bool
    {
        if (($before['currency'] ?? null) !== ($after['currency'] ?? null)
            || (int) ($before['tax_amount'] ?? 0) !== (int) ($after['tax_amount'] ?? 0)) {
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
                if ((int) ($generated['total_amount'] ?? 0) !== $exact
                    || (string) ($generated['quantity'] ?? '') !== self::decimalString(HoursQuantity::decimal((float) $hours))) {
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

        return (int) ($after['subtotal_amount'] ?? 0) - (int) ($before['subtotal_amount'] ?? 0) === $lineDelta
            && (int) ($after['total_amount'] ?? 0) - (int) ($before['total_amount'] ?? 0) === $lineDelta;
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
    public function openingRecurringItemIncidence(string $key, array $before, array $after): bool
    {
        [$companyId, $agreementId, $kind, $identity] = array_pad(explode('|', $key, 4), 4, '');
        if ($agreementId === '' || $agreementId === 'none' || $kind !== InvoiceKind::CadencePeriod->value
            || ($before['currency'] ?? null) !== ($after['currency'] ?? null)
            || (int) ($before['tax_amount'] ?? 0) !== (int) ($after['tax_amount'] ?? 0)) {
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

        [$cycle] = array_pad(explode('@', $identity, 2), 2, '');
        [$cycleStartText, $cycleEndText] = array_pad(explode('..', $cycle, 2), 2, '');
        if ($cycleStartText === '' || $cycleEndText === '' || $cycleStartText === '?' || $cycleEndText === '?') {
            return false;
        }

        $company = ClientCompany::query()->find((int) $companyId);
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
            ->where('is_active', true)
            ->first();
        if ($item === null || $item->start_date === null
            || (bool) $item->is_taxable
            || (string) $item->currency !== (string) ($after['currency'] ?? '')) {
            return false;
        }

        $lineDate = Carbon::parse((string) $line['line_date'])->startOfDay();
        $cycleStart = Carbon::parse($cycleStartText)->startOfDay();
        $cycleEnd = Carbon::parse($cycleEndText)->startOfDay();
        if (! $lineDate->isSameDay($item->start_date)
            || $lineDate->lt($cycleStart)
            || $lineDate->gt($cycleEnd)
            || ($item->anchor_day !== null && (int) $item->anchor_day !== $lineDate->day)
            || ($item->anchor_month !== null && (int) $item->anchor_month !== $lineDate->month)) {
            return false;
        }

        $agreement->setRelation('recurringItems', $agreement->recurringItems()->get());
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

        return (int) ($after['subtotal_amount'] ?? 0) - (int) ($before['subtotal_amount'] ?? 0) === $delta
            && (int) ($after['total_amount'] ?? 0) - (int) ($before['total_amount'] ?? 0) === $delta;
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
     * @return array<string, true>
     */
    public function contractCadenceHistoryGapKeys(array $expected, array $actual): array
    {
        $history = [];
        foreach ($expected as $key => $snapshot) {
            [$companyId] = array_pad(explode('|', $key, 2), 2, '');
            $kind = (string) ($snapshot['invoice_kind'] ?? '');
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

            $company = ClientCompany::query()->find((int) $companyId);
            if (! $company instanceof ClientCompany) {
                continue;
            }
            $agreement = ClientAgreement::query()
                ->whereKey((int) $agreementIds[0])
                ->where('workspace_id', $company->workspace_id)
                ->where('client_company_id', $company->id)
                ->first();
            if (! $agreement instanceof ClientAgreement || $agreement->starts_on === null
                || ! $agreement->billsOnARecurringCadence()
                || $agreement->periodRetainerHours() <= 0
                || $agreement->periodRetainerFee() <= 0) {
                continue;
            }

            $cycles = [];
            $valid = true;
            foreach ($rows as $row) {
                /** @var list<array<string, mixed>> $lines */
                $lines = (array) ($row['snapshot']['lines'] ?? []);
                $lineTotal = array_sum(array_map(static fn (array $line): int => (int) ($line['total_amount'] ?? 0), $lines));
                $lineTax = array_sum(array_map(static fn (array $line): int => (int) ($line['tax_amount'] ?? 0), $lines));
                if ($row['kind'] !== InvoiceKind::CadencePeriod->value
                    || (int) ($row['snapshot']['total_amount'] ?? 0) <= 0
                    || $lines === []
                    || (string) ($row['snapshot']['currency'] ?? '') !== (string) $agreement->currency
                    || $lineTotal !== (int) ($row['snapshot']['total_amount'] ?? 0)
                    || $lineTax !== (int) ($row['snapshot']['tax_amount'] ?? 0)
                    || $lineTotal - $lineTax !== (int) ($row['snapshot']['subtotal_amount'] ?? 0)
                    || count(array_filter(
                        $lines,
                        static fn (array $line): bool => (string) ($line['agreement_id'] ?? '') !== (string) $agreement->id,
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
                    || ! $cycle['period_end']->isSameDay($expectedPeriodEnd)) {
                    $valid = false;
                    break;
                }
                $nextStart = $expectedEnd->copy()->addDay()->startOfDay();
            }
            if (! $valid) {
                continue;
            }

            foreach ($cycles as $cycle) {
                $explained[(string) $cycle['key']] = true;
            }
        }

        return $explained;
    }

    private static function decimalString(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '0';
        }
        if (! str_contains($text, '.')) {
            return $text;
        }

        $text = rtrim(rtrim($text, '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }
}
