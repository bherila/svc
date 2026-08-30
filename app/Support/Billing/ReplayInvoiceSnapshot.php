<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/** Immutable input to replay correction proofs. */
final readonly class ReplayInvoiceSnapshot
{
    /**
     * @param  list<ReplayInvoiceLineSnapshot>  $lines
     */
    public function __construct(
        public string $currency,
        public int $subtotalAmount,
        public int $taxAmount,
        public int $totalAmount,
        public ?CarbonImmutable $cycleStart,
        public ?CarbonImmutable $cycleEnd,
        public ?CarbonImmutable $servicePeriodStart,
        public ?CarbonImmutable $servicePeriodEnd,
        public array $lines,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            currency: ReplaySnapshotValue::text($snapshot['currency'] ?? null),
            subtotalAmount: ReplaySnapshotValue::integer($snapshot['subtotal_amount'] ?? null),
            taxAmount: ReplaySnapshotValue::integer($snapshot['tax_amount'] ?? null),
            totalAmount: ReplaySnapshotValue::integer($snapshot['total_amount'] ?? null),
            cycleStart: self::date($snapshot['cycle_start'] ?? null),
            cycleEnd: self::date($snapshot['cycle_end'] ?? null),
            servicePeriodStart: self::date($snapshot['service_period_start'] ?? null),
            servicePeriodEnd: self::date($snapshot['service_period_end'] ?? null),
            lines: array_map(
                static fn (array $line): ReplayInvoiceLineSnapshot => ReplayInvoiceLineSnapshot::fromArray($line),
                ReplaySnapshotValue::arrays($snapshot['lines'] ?? null),
            ),
        );
    }

    /**
     * Does a monthly replay sell the cycle immediately after the work period?
     *
     * Imported history may label its old sold cycle differently, but the
     * generated side must still obey the current engine contract. Keeping that
     * proof on the snapshot makes it database-free and mutation-testable.
     */
    public function sellsMonthlySuccessorOfServicePeriod(): bool
    {
        if ($this->cycleStart === null
            || $this->cycleEnd === null
            || $this->servicePeriodStart === null
            || $this->servicePeriodEnd === null
            || ! $this->servicePeriodStart->isSameDay($this->servicePeriodStart->startOfMonth())
            || ! $this->servicePeriodEnd->isSameDay($this->servicePeriodStart->endOfMonth())) {
            return false;
        }

        $expectedCycleStart = $this->servicePeriodEnd->addDay()->startOfDay();

        return $this->cycleStart->isSameDay($expectedCycleStart)
            && $this->cycleEnd->isSameDay($expectedCycleStart->endOfMonth());
    }

    /** @return list<ReplayInvoiceLineSnapshot> */
    public function linesOfType(string $type): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (ReplayInvoiceLineSnapshot $line): bool => $line->type === $type,
        ));
    }

    /**
     * Total source-backed capacity drawn by this generated invoice.
     *
     * The replay-only opening lot is the oldest rollover lot, so every valid
     * generated draw consumes it before newer capacity. Returning null for an
     * ambiguous line makes the chain proof fail closed without another query.
     */
    public function sourceBackedCapacityDrawMinutes(int $agreementId): ?int
    {
        $servicePeriodEnd = $this->servicePeriodEnd?->toDateString();
        if ($servicePeriodEnd === null) {
            return null;
        }

        $minutes = 0;
        foreach ($this->linesOfType('prior_month_retainer') as $line) {
            $lineMinutes = $line->hoursMinutes();
            if ($lineMinutes === null
                || $lineMinutes < 0
                || ! $line->isCanonicalCapacityDraw($agreementId, $servicePeriodEnd)) {
                return null;
            }
            $minutes += $lineMinutes;
        }

        return $minutes;
    }

    /**
     * Historical equivalent of sourceBackedCapacityDrawMinutes(). Legacy rows
     * stored a display quantity of one, so reserve their proved hours without
     * requiring the current generator's zero-quantity presentation contract.
     */
    public function sourceBackedHistoricalCapacityDrawMinutes(int $agreementId): ?int
    {
        $servicePeriodEnd = $this->servicePeriodEnd?->toDateString();
        if ($servicePeriodEnd === null) {
            return null;
        }

        $minutes = 0;
        foreach ($this->linesOfType('prior_month_retainer') as $line) {
            $lineMinutes = $line->hoursMinutes();
            if ($lineMinutes === null
                || $lineMinutes < 0
                || $line->sourceMinutes !== $lineMinutes
                || $line->agreementRateSourceMinutes !== $lineMinutes
                || $line->agreementId !== (string) $agreementId
                || $line->unitAmount !== 0
                || $line->taxAmount !== 0
                || $line->totalAmount !== 0
                || $line->lineDate !== $servicePeriodEnd
                || ! $line->hasNoAuxiliaryOwnership()) {
                return null;
            }
            $minutes += $lineMinutes;
        }

        return $minutes;
    }

    /** Capacity sold by this historical row under its immutable retainer contract. */
    public function contractRetainerCapacityMinutes(
        int $agreementId,
        string $currency,
        int $retainerAmount,
    ): ?int {
        if ($this->currency !== $currency) {
            return null;
        }

        $retainers = $this->linesOfType('retainer');
        if (count($retainers) !== 1) {
            return null;
        }

        $line = $retainers[0];
        $minutes = $line->hoursMinutes();

        return $minutes !== null
            && $minutes > 0
            && $line->agreementId === (string) $agreementId
            && $line->unitAmount === $retainerAmount
            && $line->taxAmount === 0
            && $line->totalAmount === $retainerAmount
            && is_numeric($line->quantity)
            && (float) $line->quantity === 1.0
            && $line->sourceMinutes === 0
            && $line->agreementRateSourceMinutes === 0
            && $line->hasNoAuxiliaryOwnership()
                ? $minutes
                : null;
    }

    /** @return array<string, int> */
    public function contractLineMultisetOfType(string $type): array
    {
        $multiset = [];
        foreach ($this->linesOfType($type) as $line) {
            $signature = $line->contractSignature();
            $multiset[$signature] = ($multiset[$signature] ?? 0) + 1;
        }
        ksort($multiset);

        return $multiset;
    }

    /**
     * @param  list<string>  $excludedTypes
     * @return array<string, int>
     */
    public function lineMultisetExcluding(array $excludedTypes): array
    {
        $multiset = [];
        foreach ($this->lines as $line) {
            if (in_array($line->type, $excludedTypes, true)) {
                continue;
            }
            $signature = $line->completeSignature();
            $multiset[$signature] = ($multiset[$signature] ?? 0) + 1;
        }
        ksort($multiset);

        return $multiset;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || $value === '?') {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
