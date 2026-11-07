<?php

namespace App\Support\Billing\Autopay;

/** What one pass of the autopay command did about one invoice. */
enum AutopayChargeResult: string
{
    /** Preview only: this invoice would be charged. */
    case WouldCharge = 'would_charge';
    /** Stripe accepted the charge; the payment ledger carries its result. */
    case Submitted = 'submitted';
    /** Stripe refused the charge; recorded as the invoice's one failed attempt. */
    case Failed = 'failed';
    /** The claim was made and Stripe's answer is unknown; an operator reviews it. */
    case NeedsReview = 'needs_review';
    /** Another run holds this invoice's one attempt. */
    case AlreadyClaimed = 'already_claimed';
    /** The facts changed between the claim and the charge; the claim was released and nothing was sent. */
    case Released = 'released';
    /**
     * Stripe refused the workspace's own key or account, or the workspace is waiting out
     * the backoff after such a refusal. Nothing was charged and no attempt was spent: the
     * invoice is charged on the first run after the connection is fixed.
     */
    case ConnectionNeedsAttention = 'connection_needs_attention';
    /** Not charged now; the verdict says why. */
    case Withheld = 'withheld';
}
