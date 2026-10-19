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
 * withdrawal - and its `arrival_date` is already a calendar day. A `BATCH`
 * payout is Square's scheduled one (`automatic`); a `SIMPLE` payout is a single
 * transfer such as an instant one (`instant`). `SENT` is a payout on its way,
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

    private const array TYPES = [
        'BATCH' => PayoutType::Automatic,
        'SIMPLE' => PayoutType::Instant,
    ];

    /** @param array<string, mixed> $payout */
    public static function from(array $payout, string $expectedId): PayoutFacts
    {
        if (($payout['id'] ?? null) !== $expectedId || preg_match(SquareClient::ID_PATTERN, $expectedId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $status = self::STATUSES[(string) ($payout['status'] ?? '')] ?? throw SquareRequestFailed::unreadable();
        $type = self::TYPES[(string) ($payout['type'] ?? '')] ?? throw SquareRequestFailed::unreadable();
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
