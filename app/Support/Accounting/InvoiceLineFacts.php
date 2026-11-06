<?php

namespace App\Support\Accounting;

/** Immutable accounting snapshot; signed minor units, with tax outside net. */
final readonly class InvoiceLineFacts
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    public function reverse(string $kind, string $entryId): self
    {
        return new self([...$this->data,
            'kind' => $kind, 'reverses_id' => $entryId,
            'net_amount' => -(int) $this->data['net_amount'],
            'tax_amount' => -(int) $this->data['tax_amount'],
            'total_amount' => -(int) $this->data['total_amount'],
        ]);
    }
}
