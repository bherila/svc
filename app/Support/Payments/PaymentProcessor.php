<?php

namespace App\Support\Payments;

/** The payment processors a workspace can connect. */
enum PaymentProcessor: string
{
    case Stripe = 'stripe';
    case Square = 'square';

    public function label(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Square => 'Square',
        };
    }
}
