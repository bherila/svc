<?php

namespace App\Support\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareRequestFailed;

/** Only facts returned by RetrieveDispute, never facts from a webhook payload. */
final readonly class SquareDisputeFacts
{
    /**
     * Square's dispute states, and whether each leaves the payment's money with
     * the merchant. An inquiry and a chargeback both hold it in question until
     * they close; a lost or accepted dispute keeps it disputed for good. A
     * state not listed here is refused rather than guessed at.
     */
    private const array STATES = [
        'INQUIRY_EVIDENCE_REQUIRED' => false,
        'INQUIRY_PROCESSING' => false,
        'INQUIRY_CLOSED' => true,
        'EVIDENCE_REQUIRED' => false,
        'PROCESSING' => false,
        'WON' => true,
        'LOST' => false,
        'ACCEPTED' => false,
    ];

    private function __construct(
        public string $id,
        public string $paymentId,
        public string $state,
        public string $currency,
        public int $amount,
        public bool $resolvedForMerchant,
    ) {}

    /** @param array<string, mixed> $dispute */
    public static function from(array $dispute, string $expectedId): self
    {
        if (($dispute['id'] ?? null) !== $expectedId || preg_match(SquareClient::ID_PATTERN, $expectedId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $state = $dispute['state'] ?? null;
        if (! is_string($state) || ! array_key_exists($state, self::STATES)) {
            throw SquareRequestFailed::unreadable();
        }
        $disputed = $dispute['disputed_payment'] ?? null;
        $paymentId = is_array($disputed) ? ($disputed['payment_id'] ?? null) : null;
        $money = $dispute['amount_money'] ?? null;
        $currency = is_array($money) ? ($money['currency'] ?? null) : null;
        $amount = is_array($money) ? ($money['amount'] ?? null) : null;
        if (! is_string($paymentId) || preg_match(SquareClient::ID_PATTERN, $paymentId) !== 1
            || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1
            || ! is_int($amount) || $amount < 1) {
            throw SquareRequestFailed::unreadable();
        }

        return new self($expectedId, $paymentId, $state, $currency, $amount, self::STATES[$state]);
    }
}
