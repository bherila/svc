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
    ) {}

    /** @param array<string, mixed> $line */
    public static function fromArray(array $line): self
    {
        return new self(
            type: (string) ($line['type'] ?? ''),
            totalAmount: (int) ($line['total_amount'] ?? 0),
            unitAmount: (int) ($line['unit_amount'] ?? 0),
            taxAmount: (int) ($line['tax_amount'] ?? 0),
            quantity: (string) ($line['quantity'] ?? ''),
            lineDate: (string) ($line['line_date'] ?? ''),
            recurringItemId: (string) ($line['recurring_item_id'] ?? ''),
            projectId: (string) ($line['project_id'] ?? ''),
            agreementId: (string) ($line['agreement_id'] ?? ''),
            claimedBy: (string) ($line['claimed_by'] ?? ''),
            descriptionHash: (string) ($line['description_hash'] ?? ''),
            identityHash: (string) ($line['identity_hash'] ?? ''),
            hours: array_key_exists('hours', $line) && $line['hours'] !== null
                ? (float) $line['hours']
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
