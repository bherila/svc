<?php

namespace App\Support\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareRequestFailed;
use App\Support\Finance\PayoutFacts;
use App\Support\Finance\PayoutStatus;
use App\Support\Finance\PayoutType;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A payout read from Square's Payouts API as {@see PayoutFacts}; never one
 * from a webhook payload, which is only a hint to read it.
 *
 * Square's `amount_money` is signed - positive for a deposit, negative for a
 * withdrawal - and its `arrival_date` is already a calendar day.
 *
 * Square's `type` is how much of the balance moved, not how: "A `BATCH` payout
 * is a transfer of a seller's entire Square balance. A `SIMPLE` payout is a
 * transfer of any amount less than the entire balance" (Payouts API
 * overview). So a `BATCH` payout is `automatic`, and a `SIMPLE` one is
 * `instant` when it went to a debit card or carries a transfer fee
 * (`payout_fee`, which Square returns for an Instant Deposit to a card) and
 * `manual` otherwise. A payout into a Square balance (`SQUARE_STORED_BALANCE`,
 * Square Checking or Savings, or the deprecated `SQUARE_BALANCE`) never
 * reaches a bank account, so it is not a payout here and is answered null.
 *
 * `SENT` is a payout on its way,
 * `PAID` one that arrived and `FAILED` one that came back. How current the
 * read is comes from Square's own `updated_at`. Anything else is refused as
 * unreadable rather than guessed at.
 */
final class SquarePayoutFacts
{
    private const array STATUSES = [
        'SENT' => PayoutStatus::InTransit,
        'PAID' => PayoutStatus::Paid,
        'FAILED' => PayoutStatus::Failed,
    ];

    /** Destinations inside Square: money moved there reaches no bank account. */
    private const array SQUARE_BALANCES = ['SQUARE_STORED_BALANCE', 'SQUARE_BALANCE'];

    /**
     * @param  array<string, mixed>  $payout
     * @return PayoutFacts|null null for a transfer into a Square balance
     */
    public static function from(array $payout, string $expectedId): ?PayoutFacts
    {
        if (($payout['id'] ?? null) !== $expectedId || preg_match(SquareClient::ID_PATTERN, $expectedId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $status = self::STATUSES[(string) ($payout['status'] ?? '')] ?? throw SquareRequestFailed::unreadable();
        $destination = $payout['destination'] ?? null;
        $destinationType = is_array($destination) && is_string($destination['type'] ?? null) ? $destination['type'] : null;
        if (in_array($destinationType, self::SQUARE_BALANCES, true)) {
            return null;
        }
        $fees = $payout['payout_fee'] ?? null;
        $type = match ($payout['type'] ?? null) {
            'BATCH' => PayoutType::Automatic,
            'SIMPLE' => $destinationType === 'CARD' || (is_array($fees) && $fees !== []) ? PayoutType::Instant : PayoutType::Manual,
            default => throw SquareRequestFailed::unreadable(),
        };
        [$amount, $currency] = self::money($payout['amount_money'] ?? null);
        $arrival = $payout['arrival_date'] ?? null;
        if ($arrival !== null && (! is_string($arrival) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $arrival) !== 1
            || CarbonImmutable::createFromFormat('!Y-m-d', $arrival)?->toDateString() !== $arrival)) {
            throw SquareRequestFailed::unreadable();
        }

        return new PayoutFacts('square', $expectedId, $amount, $currency, $arrival, $type, $status,
            self::timeMs($payout['updated_at'] ?? $payout['created_at'] ?? null));
    }

    /**
     * Signed minor units and the currency of a Square money object. Square's
     * reference examples spell the currency `currency_code` in places; either
     * is read.
     *
     * @return array{0: int, 1: string}
     */
    public static function money(mixed $money): array
    {
        $amount = is_array($money) ? ($money['amount'] ?? null) : null;
        $currency = is_array($money) ? ($money['currency'] ?? $money['currency_code'] ?? null) : null;
        if (! is_int($amount) || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw SquareRequestFailed::unreadable();
        }

        return [$amount, $currency];
    }

    private static function timeMs(mixed $time): int
    {
        if (! is_string($time) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $time) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        try {
            return (int) CarbonImmutable::parse($time)->getTimestampMs();
        } catch (Throwable) {
            throw SquareRequestFailed::unreadable();
        }
    }
}
