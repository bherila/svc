<?php

namespace App\Support\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareRequestFailed;

/**
 * One entry of a Square payout, read from ListPayoutEntries. Only a `CHARGE`
 * entry settles a payment in this payout; its fee is Square's
 * `fee_amount_money`, positive for a fee charged (net = gross - fee), or
 * gross less net when Square leaves the fee out. Other entries (refunds,
 * fees, adjustments) belong to the payout's amount but change no payment.
 */
final readonly class SquarePayoutEntry
{
    private function __construct(
        public ?string $paymentId,
        public ?int $fee,
        public ?string $currency,
    ) {}

    /** @param array<string, mixed> $entry */
    public static function from(array $entry, string $payoutId): self
    {
        if (($entry['payout_id'] ?? $payoutId) !== $payoutId || ! is_string($entry['type'] ?? null)) {
            throw SquareRequestFailed::unreadable();
        }
        if ($entry['type'] !== 'CHARGE') {
            return new self(null, null, null);
        }
        $details = $entry['type_charge_details'] ?? null;
        $paymentId = is_array($details) ? ($details['payment_id'] ?? null) : null;
        if (! is_string($paymentId) || preg_match(SquareClient::ID_PATTERN, $paymentId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        [$gross, $currency] = SquarePayoutFacts::money($entry['gross_amount_money'] ?? null);
        if (isset($entry['fee_amount_money'])) {
            [$fee, $feeCurrency] = SquarePayoutFacts::money($entry['fee_amount_money']);
        } else {
            [$net, $feeCurrency] = SquarePayoutFacts::money($entry['net_amount_money'] ?? null);
            $fee = $gross - $net;
        }
        if ($feeCurrency !== $currency) {
            throw SquareRequestFailed::unreadable();
        }

        return new self($paymentId, $fee, $currency);
    }
}
