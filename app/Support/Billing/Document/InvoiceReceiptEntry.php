<?php

declare(strict_types=1);

namespace App\Support\Billing\Document;

/** One expense billed on the invoice, with the receipts the reader may be told about. */
final readonly class InvoiceReceiptEntry
{
    /** @param list<InvoiceReceiptFile> $files */
    public function __construct(
        public string $ref,
        public string $lineId,
        public string $spentOn,
        public string $description,
        public int $amount,
        public array $files,
    ) {}

    /** @return array{ref: string, line_id: string, spent_on: string, description: string, amount: int, files: list<array{id: string, filename: string, media_type: string, bytes: int, href: string|null, note: string|null}>} */
    public function toArray(): array
    {
        return [
            'ref' => $this->ref,
            'line_id' => $this->lineId,
            'spent_on' => $this->spentOn,
            'description' => $this->description,
            'amount' => $this->amount,
            'files' => array_map(static fn (InvoiceReceiptFile $file): array => $file->toArray(), $this->files),
        ];
    }
}
