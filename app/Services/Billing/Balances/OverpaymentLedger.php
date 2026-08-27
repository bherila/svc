<?php

namespace App\Services\Billing\Balances;

/**
 * Itemised view of overpayment credits available for a client company.
 *
 * Each entry is a single source invoice that was overpaid. `totalRemaining`
 * is the sum of `remaining` across all entries — it's the amount that will
 * be applied as a credit line on the next draft invoice.
 *
 * @phpstan-type LedgerEntry array{
 *     invoice_id: int,
 *     invoice_number: string|null,
 *     overpaid: float,
 *     consumed: float,
 *     remaining: float
 * }
 */
readonly class OverpaymentLedger
{
    /**
     * @param  list<LedgerEntry>  $entries
     */
    public function __construct(
        public array $entries,
        public float $totalRemaining,
    ) {}

    public static function empty(): self
    {
        return new self(entries: [], totalRemaining: 0.0);
    }
}
