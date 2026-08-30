<?php

namespace App\Support\Billing;

/** One replay line after persistence concerns and private prose are removed. */
final readonly class ReplayInvoiceLineSnapshot
{
    public function __construct(
        public string $type,
        public int $totalAmount,
        public int $unitAmount,
        public int $taxAmount,
        public string $quantity,
        public string $lineDate,
        public string $recurringItemId,
        public string $projectId,
        public string $agreementId,
        public string $claimedBy,
        public string $descriptionHash,
        public string $identityHash,
        public ?float $hours,
        public ?int $sourceMinutes,
    ) {}

    /** @param array<string, mixed> $line */
    public static function fromArray(array $line): self
    {
        return new self(
            type: ReplaySnapshotValue::text($line['type'] ?? null),
            totalAmount: ReplaySnapshotValue::integer($line['total_amount'] ?? null),
            unitAmount: ReplaySnapshotValue::integer($line['unit_amount'] ?? null),
            taxAmount: ReplaySnapshotValue::integer($line['tax_amount'] ?? null),
            quantity: ReplaySnapshotValue::text($line['quantity'] ?? null),
            lineDate: ReplaySnapshotValue::text($line['line_date'] ?? null),
            recurringItemId: ReplaySnapshotValue::text($line['recurring_item_id'] ?? null),
            projectId: ReplaySnapshotValue::text($line['project_id'] ?? null),
            agreementId: ReplaySnapshotValue::text($line['agreement_id'] ?? null),
            claimedBy: ReplaySnapshotValue::text($line['claimed_by'] ?? null),
            descriptionHash: ReplaySnapshotValue::text($line['description_hash'] ?? null),
            identityHash: ReplaySnapshotValue::text($line['identity_hash'] ?? null),
            hours: ReplaySnapshotValue::number($line['hours'] ?? null),
            sourceMinutes: array_key_exists('source_minutes', $line)
                ? ReplaySnapshotValue::integer($line['source_minutes'])
                : null,
        );
    }

    public function hoursMinutes(): ?int
    {
        if ($this->hours === null) {
            return null;
        }

        return self::wholeMinutes($this->hours);
    }

    public function quantityMinutes(): ?int
    {
        if (! is_numeric($this->quantity)) {
            return null;
        }

        return self::wholeMinutes((float) $this->quantity);
    }

    public function allocationIdentity(): string
    {
        return implode('|', [
            $this->type,
            $this->unitAmount,
            $this->taxAmount,
            $this->lineDate,
            $this->recurringItemId,
            $this->projectId,
            $this->agreementId,
            $this->claimedBy,
            $this->identityHash,
        ]);
    }

    public function completeSignature(): string
    {
        return implode('|', [
            $this->allocationIdentity(),
            $this->totalAmount,
            $this->quantity,
            $this->descriptionHash,
            $this->hours === null ? 'null' : (string) $this->hours,
            $this->sourceMinutes === null ? 'null' : (string) $this->sourceMinutes,
        ]);
    }

    /** Every contract field, excluding generated display prose digests. */
    public function contractSignature(): string
    {
        return implode('|', [
            $this->type,
            $this->totalAmount,
            $this->unitAmount,
            $this->taxAmount,
            $this->quantity,
            $this->lineDate,
            $this->recurringItemId,
            $this->projectId,
            $this->agreementId,
            $this->claimedBy,
            $this->hours === null ? 'null' : (string) $this->hours,
            $this->sourceMinutes === null ? 'null' : (string) $this->sourceMinutes,
        ]);
    }

    private static function wholeMinutes(float $hours): ?int
    {
        $rawMinutes = $hours * 60;
        $minutes = (int) round($rawMinutes);

        // Snapshots retain four decimal hours. Repeating minute fractions can
        // therefore land up to 0.002 minute away from their exact integer.
        return $minutes >= 0 && abs($rawMinutes - $minutes) <= 0.01
            ? $minutes
            : null;
    }
}
