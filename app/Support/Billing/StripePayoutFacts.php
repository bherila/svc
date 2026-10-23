<?php

namespace App\Support\Billing;

use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use DomainException;

/**
 * A Stripe payout object read as {@see PayoutFacts}: Stripe's vocabulary on
 * the way in, svc's on the way out.
 *
 * Stripe's `amount` is already signed (a payout that debits the bank account
 * is negative) and its `arrival_date` is midnight UTC of the arrival day. The
 * type is `instant` for an instant payout, `automatic` for one Stripe made on
 * its schedule, and `manual` otherwise.
 */
final class StripePayoutFacts
{
    /** @param array<string, mixed> $payout */
    public static function from(array $payout, int $observedAtMs): PayoutFacts
    {
        $id = $payout['id'] ?? null;
        $amount = $payout['amount'] ?? null;
        $currency = $payout['currency'] ?? null;
        if (! is_string($id) || preg_match('/^po_[A-Za-z0-9_]{1,250}$/D', $id) !== 1) {
            throw new DomainException('The Stripe payout does not identify itself.');
        }
        if (! is_int($amount) || ! is_string($currency) || preg_match('/^[A-Za-z]{3}$/D', $currency) !== 1) {
            throw new DomainException('The Stripe payout has no readable amount.');
        }
        $arrival = $payout['arrival_date'] ?? null;
        if ($arrival !== null && ! is_int($arrival)) {
            throw new DomainException('The Stripe payout has an unreadable arrival date.');
        }
        $status = PayoutStatus::tryFrom(is_string($payout['status'] ?? null) ? $payout['status'] : '')
            ?? throw new DomainException('The Stripe payout has an unrecognised status.');
        $type = match (true) {
            ($payout['method'] ?? null) === 'instant' => PayoutType::Instant,
            ($payout['automatic'] ?? null) === true => PayoutType::Automatic,
            default => PayoutType::Manual,
        };

        return new PayoutFacts(
            'stripe',
            $id,
            $amount,
            strtoupper($currency),
            $arrival === null ? null : CarbonImmutable::createFromTimestampUTC($arrival)->toDateString(),
            $type,
            $status,
            $observedAtMs,
        );
    }
}
