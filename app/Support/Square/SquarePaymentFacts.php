<?php

namespace App\Support\Square;

use App\Services\Square\SquareClient;
use App\Services\Square\SquareRequestFailed;
use Carbon\CarbonImmutable;
use Throwable;

/** Only facts returned by GetPayment, never facts from a webhook payload. */
final readonly class SquarePaymentFacts
{
    private function __construct(
        public string $id,
        public string $orderId,
        public string $locationId,
        public string $currency,
        public int $amount,
        public int $refundedAmount,
        public CarbonImmutable $receivedAt,
    ) {}

    /** @param array<string, mixed> $payment */
    public static function from(array $payment, string $expectedId): ?self
    {
        if (($payment['id'] ?? null) !== $expectedId || preg_match(SquareClient::ID_PATTERN, $expectedId) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $status = $payment['status'] ?? null;
        if (in_array($status, ['APPROVED', 'PENDING', 'CANCELED', 'FAILED'], true)) {
            return null;
        }
        if ($status !== 'COMPLETED') {
            throw SquareRequestFailed::unreadable();
        }
        $order = $payment['order_id'] ?? null;
        $location = $payment['location_id'] ?? null;
        $money = $payment['amount_money'] ?? null;
        $currency = is_array($money) ? ($money['currency'] ?? null) : null;
        if (! is_string($order) || preg_match(SquareClient::ID_PATTERN, $order) !== 1
            || ! is_string($location) || preg_match(SquareClient::ID_PATTERN, $location) !== 1
            || ! is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw SquareRequestFailed::unreadable();
        }
        $amount = self::money($money, $currency);
        $refund = self::money($payment['refunded_money'] ?? ['amount' => 0, 'currency' => $currency], $currency);
        // A tip refund cannot be allocated to invoice principal from an aggregate.
        // Refuse that ambiguity rather than subtracting staff tips from receivables.
        $tip = self::money($payment['tip_money'] ?? ['amount' => 0, 'currency' => $currency], $currency);
        if ($amount < 1 || $refund > $amount || $tip !== 0) {
            throw SquareRequestFailed::unreadable();
        }
        $created = $payment['created_at'] ?? null;
        $matches = [];
        if (! is_string($created) || preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})/', $created, $matches) !== 1 || $matches[0] !== $created) {
            throw SquareRequestFailed::unreadable();
        }
        try {
            $received = CarbonImmutable::parse($created);
        } catch (Throwable) {
            throw SquareRequestFailed::unreadable();
        }
        if ($received->format('Y-m-d\TH:i:s') !== substr($created, 0, 19)) {
            throw SquareRequestFailed::unreadable();
        }

        return new self($expectedId, $order, $location, $currency, $amount, $refund, $received);
    }

    private static function money(mixed $money, string $currency): int
    {
        if (! is_array($money) || ! is_int($money['amount'] ?? null) || $money['amount'] < 0 || ($money['currency'] ?? null) !== $currency) {
            throw SquareRequestFailed::unreadable();
        }

        return $money['amount'];
    }
}
