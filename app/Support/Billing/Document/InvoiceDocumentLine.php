<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** One printed line. Amounts are minor units, quantity is the stored decimal string. */
final readonly class InvoiceDocumentLine
{
    public function __construct(
        public string $id,
        public string $type,
        public string $description,
        public string $quantity,
        public ?float $hours,
        public ?string $lineDate,
        public int $unitAmount,
        public int $taxAmount,
        public int $totalAmount,
    ) {}

    public function typeLabel(): string
    {
        return StoredValueLabel::of($this->type);
    }

    /** @return array{id: string, type: string, type_label: string, description: string, quantity: string, hours: float|null, line_date: string|null, unit_amount: int, tax_amount: int, total_amount: int} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'description' => $this->description,
            'quantity' => $this->quantity,
            'hours' => $this->hours,
            'line_date' => $this->lineDate,
            'unit_amount' => $this->unitAmount,
            'tax_amount' => $this->taxAmount,
            'total_amount' => $this->totalAmount,
        ];
    }
}
