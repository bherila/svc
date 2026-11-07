<?php

namespace App\Support\Billing\Autopay;

/** What a reader is told about autopay on one invoice. */
enum AutopayInvoiceState: string
{
    /** Autopay plays no part in this invoice; nothing is shown. */
    case NotApplicable = 'not_applicable';
    case WillCharge = 'will_charge';
    case Processing = 'processing';
    case Charged = 'charged';
    case Failed = 'failed';
    case OverLimit = 'over_limit';
    case NoDefaultMethod = 'no_default_method';
    case Skipped = 'skipped';
    /** An operator checked Stripe for a held attempt and recorded that nothing was charged: collect by hand. */
    case ResolvedNotCharged = 'resolved_not_charged';
    /** Claimed with no PaymentIntent: an operator has to look. */
    case NeedsReview = 'needs_review';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable',
            self::WillCharge => 'Will autopay',
            self::Processing => 'Autopay processing',
            self::Charged => 'Paid by autopay',
            self::Failed => 'Autopay failed',
            self::OverLimit => 'Not autopaid: over limit',
            self::NoDefaultMethod => 'Not autopaid: no default method',
            self::Skipped => 'Autopay skipped',
            self::ResolvedNotCharged => 'Not autopaid: left for manual collection',
            self::NeedsReview => 'Autopay needs review',
        };
    }
}
