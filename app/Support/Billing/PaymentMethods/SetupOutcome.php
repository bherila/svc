<?php

declare(strict_types=1);

namespace App\Support\Billing\PaymentMethods;

/** What a client is told about a payment method they just tried to add. */
enum SetupOutcome: string
{
    /** Stripe confirmed it and SVC recorded it. */
    case Saved = 'saved';
    /** Stripe is still verifying it (a bank account can take days); the webhook records it when done. */
    case Pending = 'pending';
    /** Stripe did not accept it. Nothing was saved. */
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::Saved => 'Payment method saved.',
            self::Pending => 'Stripe is still verifying this payment method. It appears here once it is confirmed.',
            self::Failed => 'That payment method was not accepted, so nothing was saved. Try again or use another.',
        };
    }
}
