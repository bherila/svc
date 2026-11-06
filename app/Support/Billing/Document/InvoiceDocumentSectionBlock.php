<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** A section of the document: its lines in print order and what they add up to. */
final readonly class InvoiceDocumentSectionBlock
{
    /** @param list<InvoiceDocumentLine> $lines */
    public function __construct(public DocumentSection $section, public array $lines) {}

    public function subtotal(): int
    {
        return array_sum(array_map(static fn (InvoiceDocumentLine $line): int => $line->totalAmount, $this->lines));
    }

    public function tax(): int
    {
        return array_sum(array_map(static fn (InvoiceDocumentLine $line): int => $line->taxAmount, $this->lines));
    }

    /** @return array{key: string, title: string, subtotal: int, tax: int, lines: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'key' => $this->section->value,
            'title' => $this->section->title(),
            'subtotal' => $this->subtotal(),
            'tax' => $this->tax(),
            'lines' => array_map(static fn (InvoiceDocumentLine $line): array => $line->toArray(), $this->lines),
        ];
    }
}
