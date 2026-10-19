<?php

namespace App\Support\Finance;

use DomainException;

/**
 * What a payment processor reports about one payout, in svc's vocabulary:
 * the signed amount in minor units (negative when the payout took money from
 * the bank account), its currency, the day it arrives, its type and status,
 * and how current the report is (epoch milliseconds), so an older report
 * never overwrites a newer one.
 *
 * Built only by the provider adapters, from the processor's own payout object.
 */
final readonly class PayoutFacts
{
    public function __construct(
        public string $provider,
        public string $identifier,
        public int $amount,
        public string $currency,
        /** Y-m-d, or null when the processor has not said. */
        public ?string $arrivalDate,
        public PayoutType $type,
        public PayoutStatus $status,
        public int $observedAtMs,
    ) {
        if (! in_array($provider, ['stripe', 'square'], true)) {
            throw new DomainException('A payout comes from Stripe or Square.');
        }
        if ($identifier === '' || strlen($identifier) > 255) {
            throw new DomainException('A payout identifier is between 1 and 255 characters.');
        }
        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new DomainException('A payout currency is three upper-case letters.');
        }
        if ($arrivalDate !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $arrivalDate) !== 1) {
            throw new DomainException('A payout arrival date is Y-m-d.');
        }
        if ($observedAtMs < 0) {
            throw new DomainException('A payout report time is not before the epoch.');
        }
    }

    /**
     * Whether these facts replace stored facts observed at `$storedAtMs` with
     * `$storedStatus`: a newer report does, an older one never does, and of two
     * reports stamped alike the one further along wins.
     */
    public function supersedes(int $storedAtMs, ?PayoutStatus $storedStatus): bool
    {
        if ($this->observedAtMs !== $storedAtMs) {
            return $this->observedAtMs > $storedAtMs;
        }

        // An unreadable stored status is replaced by any readable one.
        return $storedStatus === null || $this->status->progress() >= $storedStatus->progress();
    }
}
