<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Services\Billing\MoneyService;

/**
 * One invoice line's identity and money in minor units: a total that includes
 * the line's own tax, so the net is what the total leaves. Net and total are
 * signed (a credit is negative); tax is not.
 */
final readonly class LineFacts
{
    public function __construct(
        public string $id,
        public string $type,
        public string $description,
        public string $quantity,
        public int $unitAmount,
        public int $taxAmount,
        public int $totalAmount,
        public ?string $projectId,
        /** The line's row id, which orders the entries of one voided invoice; zero where it is not needed. */
        public int $rowId = 0,
    ) {}

    public function netAmount(): int
    {
        return $this->totalAmount - $this->taxAmount;
    }

    public function taxable(): bool
    {
        return $this->taxAmount !== 0;
    }

    /**
     * The tax as a share of the net (`0.0825`), to four places: the effective
     * rate the line was charged, since a line stores its tax amount and not a
     * rate. Null for a line with no tax or no positive net.
     */
    public function taxRate(): ?string
    {
        if ($this->taxAmount === 0 || $this->netAmount() <= 0) {
            return null;
        }

        return number_format(round($this->taxAmount / $this->netAmount(), 4), 4, '.', '');
    }

    /** The same line with another description, quantity, unit amount and tax; its total follows from them. */
    public function restated(string $description, string $quantity, int $unitAmount, int $taxAmount): self
    {
        $totals = MoneyService::invoiceTotals([['quantity' => $quantity, 'unit_amount' => $unitAmount, 'tax_amount' => $taxAmount]]);

        return new self($this->id, $this->type, $description, $quantity, $unitAmount, $taxAmount, $totals['total_amount'], $this->projectId, $this->rowId);
    }

    public function withDescription(string $description): self
    {
        return new self($this->id, $this->type, $description, $this->quantity, $this->unitAmount, $this->taxAmount, $this->totalAmount, $this->projectId, $this->rowId);
    }

    /** Whether another line carries different money (quantity, unit amount or tax). */
    public function moneyDiffersFrom(self $other): bool
    {
        return $this->netAmount() !== $other->netAmount() || $this->taxAmount !== $other->taxAmount;
    }
}
