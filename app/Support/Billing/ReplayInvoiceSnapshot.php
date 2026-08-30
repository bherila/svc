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
