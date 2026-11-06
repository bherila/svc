<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\PaymentRefundKind;
use App\Support\Finance\ProcessorMovementKind;
use Carbon\CarbonImmutable;

/**
 * The entries of the cash-income feed, built from facts alone.
 *
 * A received payment is one `payment` entry for the whole of what was
 * received. Money that leaves it afterwards - a refund recorded by hand or
 * reported by the processor, a dispute, a processor fee - is a separate
 * signed entry bound to the same payment, and a refund recorded in error is
 * answered by a `refund_reversal` that names it; no entry is rewritten.
 * Amounts are signed integer minor units: money in is positive, money out
 * negative. A fee is the processor's signed expense, positive when charged,
 * so `net_minor` is the amount less the fee.
 */
final class CashEntries
{
    /**
     * The payment statuses whose money was received: a succeeded payment, a
     * refunded one (the refund is its own entry) and a disputed one (so is the
     * dispute). A pending, failed or canceled payment brought in nothing.
     *
     * Exhaustive with no `default` arm, so a new status must be decided here.
     *
     * @return list<string>
     */
    public static function receivedStatuses(): array
    {
        $received = [];
        foreach (InvoicePaymentStatus::cases() as $status) {
            $counts = match ($status) {
                InvoicePaymentStatus::Succeeded, InvoicePaymentStatus::Refunded, InvoicePaymentStatus::Disputed => true,
                InvoicePaymentStatus::Pending, InvoicePaymentStatus::Failed, InvoicePaymentStatus::Canceled => false,
            };
            if ($counts) {
                $received[] = $status->value;
            }
        }

        return $received;
    }

    /**
     * @param  string  $version  the payment's opaque revision, which moves when it is corrected or refunded
     * @param  int|null  $feeMinor  the processor's fee for the payment, when it has reported one
     */
    public static function payment(
        FeedPosition $position,
        PaymentRef $ref,
        string $id,
        string $version,
        int $amount,
        int $refundedAmount,
        ?int $feeMinor,
        string $receivedOn,
        ?string $method,
        ?string $provider,
        string $status,
    ): FeedEntry {
        return self::entry($position, $ref, CashEntryType::Payment, $id, $receivedOn, $amount, $feeMinor, null, $provider, $version, $method, StoredValueLabel::of($status), $refundedAmount, false);
    }

    /**
     * A refund recorded by hand against a payment, or the reversal of one.
     *
     * @param  string  $recordedOn  the day it was recorded, in the workspace's calendar
     * @param  string|null  $reversesId  the refund a reversal names
     */
    public static function recordedRefund(FeedPosition $position, PaymentRef $ref, string $id, PaymentRefundKind $kind, int $amount, string $recordedOn, ?string $reversesId): FeedEntry
    {
        return match ($kind) {
            PaymentRefundKind::Refund => self::entry($position, $ref, CashEntryType::Refund, $id, $recordedOn, -$amount, null, null, null, null, null, null, null, false),
            PaymentRefundKind::Reversal => self::entry($position, $ref, CashEntryType::RefundReversal, $id, $recordedOn, $amount, null, $reversesId, null, null, null, null, null, false),
        };
    }

    /** A signed balance entry the payment processor reported for a payment. */
    public static function movement(FeedPosition $position, PaymentRef $ref, string $id, ProcessorMovementKind $kind, int $principalMinor, int $feeMinor, string $occurredOn, string $provider, bool $needsReview): FeedEntry
    {
        return self::entry($position, $ref, CashEntryType::ofMovement($kind), $id, $occurredOn, $principalMinor, $feeMinor, null, $provider, null, null, null, null, $needsReview);
    }

    private static function entry(
        FeedPosition $position,
        PaymentRef $ref,
        CashEntryType $type,
        string $id,
        string $effectiveOn,
        int $amount,
        ?int $feeMinor,
        ?string $reversesId,
        ?string $provider,
        ?string $version,
        ?string $method,
        ?string $statusLabel,
        ?int $refundedAmount,
        bool $needsReview,
    ): FeedEntry {
        return new FeedEntry($position, [
            'id' => $id,
            'version' => $version,
            'entry_type' => $type->value,
            'entry_type_label' => $type->label(),
            'recorded_at' => CarbonImmutable::parse($position->at, 'UTC')->toIso8601String(),
            'effective_on' => $effectiveOn,
            'amount_minor' => $amount,
            'fee_minor' => $feeMinor,
            'net_minor' => $amount - ($feeMinor ?? 0),
            'currency' => $ref->currency,
            'payment_id' => $ref->paymentId,
            'reverses_entry_id' => $reversesId,
            'invoice_id' => $ref->invoiceId,
            'invoice_number' => $ref->invoiceNumber,
            'client_id' => $ref->clientId,
            'provider' => $provider,
            'method' => $method,
            'status_label' => $statusLabel,
            'refunded_amount_minor' => $refundedAmount,
            'needs_review' => $needsReview,
        ]);
    }
}
