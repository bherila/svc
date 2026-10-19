<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use DomainException;

/**
 * One Stripe balance transaction in a payout, read with its source expanded:
 * the identifiers that can name an svc payment (the source itself, and the
 * payment intent and charge it belongs to), and, for a charge, its fee and
 * the time it was charged.
 *
 * Only a charge settles a payment in this payout. A refund or dispute names a
 * payment that settled earlier, so it says which workspace the payout belongs
 * to but changes nothing on the payment.
 */
final readonly class StripeBalanceEntry
{
    /** Balance transaction types that are a payment being settled. */
    private const array CHARGES = ['charge', 'payment'];

    /** @param list<string> $paymentIdentifiers */
    private function __construct(
        public bool $isCharge,
        public array $paymentIdentifiers,
        public int $fee,
        public string $currency,
        public ?CarbonImmutable $chargedAt,
    ) {}

    /** @param array<string, mixed> $transaction */
    public static function from(array $transaction): self
    {
        $type = $transaction['type'] ?? null;
        $fee = $transaction['fee'] ?? null;
        $currency = $transaction['currency'] ?? null;
        if (! is_string($type) || ! is_int($fee) || ! is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            throw new DomainException('A Stripe balance transaction in the payout is unreadable.');
        }
        $source = $transaction['source'] ?? null;
        $identifiers = [];
        $created = null;
        if (is_string($source)) {
            $identifiers[] = $source;
        } elseif (is_array($source)) {
            foreach (['id', 'payment_intent', 'charge'] as $key) {
                $value = $source[$key] ?? null;
                $value = is_array($value) ? ($value['id'] ?? null) : $value;
                if (is_string($value) && $value !== '') {
                    $identifiers[] = $value;
                }
            }
            $created = is_int($source['created'] ?? null) ? $source['created'] : null;
        }
        $isCharge = in_array($type, self::CHARGES, true);
        if ($isCharge) {
            $created ??= is_int($transaction['created'] ?? null) ? $transaction['created'] : null;
        }

        return new self(
            $isCharge,
            array_values(array_unique($identifiers)),
            $fee,
            strtoupper($currency),
            $isCharge && $created !== null ? CarbonImmutable::createFromTimestampUTC($created) : null,
        );
    }
}
