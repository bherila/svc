<?php

namespace App\Support\Billing\Autopay;

/**
 * Where an invoice's one autopay attempt stands. The row exists from the
 * moment the charge is claimed; a payment's own result is read from the
 * ledger, never copied here.
 */
enum AutopayAttemptState: string
{
    /** Claimed before Stripe was called. Without a PaymentIntent it is reported for review, never retried. */
    case Claimed = 'claimed';
    /** Stripe accepted the request; the payment ledger carries the result. */
    case Submitted = 'submitted';
    /** Stripe refused the charge (declined, authentication required). */
    case Failed = 'failed';
    /** A provider owner or admin skipped autopay for this invoice. */
    case Skipped = 'skipped';
    /**
     * An owner or admin checked Stripe for an attempt held for review and found nothing
     * was charged. The invoice is left for manual collection; no new attempt is made.
     */
    case NotCharged = 'not_charged';
}
