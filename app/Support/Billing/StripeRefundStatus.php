<?php

namespace App\Support\Billing;

/**
 * The status of a Stripe refund: Stripe's vocabulary, not a payment's
 * ({@see InvoicePaymentStatus}), although some words are shared.
 */
enum StripeRefundStatus: string
{
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';

    /**
     * Whether a refund in this status still counts as refunded. A failed or
     * canceled refund returned its money to the merchant; every other status
     * is a refund under way or done, as Stripe's own refunded total counts it.
     * A status Stripe adds later counts as refunded until it is understood, so
     * it can never make received money look larger than it is.
     */
    public static function stillStands(string $status): bool
    {
        return match (self::tryFrom($status)) {
            self::Failed, self::Canceled => false,
            self::Pending, self::RequiresAction, self::Succeeded, null => true,
        };
    }
}
