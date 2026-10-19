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
        /** Square's processing fee so far, positive for a fee charged; null until Square reports one. */
        public ?int $processingFee = null,
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
        // Creation is authorization/initiation; updates also include later refunds.
        // Use the documented capture timeline, or refuse an unknown completion date.
        $details = $payment['card_details'] ?? null;
        $timeline = is_array($details) ? ($details['card_payment_timeline'] ?? null) : null;
        $completed = ($payment['source_type'] ?? null) === 'CARD' && is_array($timeline) ? ($timeline['captured_at'] ?? null) : null;
        $matches = [];
        if (! is_string($completed) || preg_match('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})/', $completed, $matches) !== 1 || $matches[0] !== $completed) {
            throw SquareRequestFailed::unreadable();
        }
        try {
            $received = CarbonImmutable::parse($completed);
        } catch (Throwable) {
            throw SquareRequestFailed::unreadable();
        }
        if ($received->format('Y-m-d\TH:i:s') !== substr($completed, 0, 19)) {
            throw SquareRequestFailed::unreadable();
        }

        return new self($expectedId, $order, $location, $currency, $amount, $refund, $received, self::processingFee($payment['processing_fee'] ?? null, $currency));
    }

    /**
     * The total of Square's processing fees on the payment (an adjustment may
     * be negative), or null when Square has not reported them yet or reports
     * them in a shape this does not read. The fee only informs the finance
     * listing; it never decides whether the payment itself is readable, so it
     * is not a reason to refuse one.
     */
    private static function processingFee(mixed $fees, string $currency): ?int
    {
        if (! is_array($fees) || $fees === [] || ! array_is_list($fees)) {
            return null;
        }
        $total = 0;
        foreach ($fees as $fee) {
            $money = is_array($fee) ? ($fee['amount_money'] ?? null) : null;
            if (! is_array($money) || ! is_int($money['amount'] ?? null) || ($money['currency'] ?? null) !== $currency) {
                return null;
            }
            $total += $money['amount'];
        }

        return $total;
    }

    private static function money(mixed $money, string $currency): int
    {
        if (! is_array($money) || ! is_int($money['amount'] ?? null) || $money['amount'] < 0 || ($money['currency'] ?? null) !== $currency) {
            throw SquareRequestFailed::unreadable();
        }

        return $money['amount'];
    }
}
