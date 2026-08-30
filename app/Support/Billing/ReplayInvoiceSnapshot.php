<?php

namespace App\Support\Billing;

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
        public array $lines,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public static function fromArray(array $snapshot): self
    {
        return new self(
            currency: (string) ($snapshot['currency'] ?? ''),
            subtotalAmount: (int) ($snapshot['subtotal_amount'] ?? 0),
            taxAmount: (int) ($snapshot['tax_amount'] ?? 0),
            totalAmount: (int) ($snapshot['total_amount'] ?? 0),
            lines: array_values(array_map(
                static fn (array $line): ReplayInvoiceLineSnapshot => ReplayInvoiceLineSnapshot::fromArray($line),
                (array) ($snapshot['lines'] ?? []),
            )),
        );
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
}
