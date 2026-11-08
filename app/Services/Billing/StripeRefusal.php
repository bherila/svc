<?php

namespace App\Services\Billing;

use DomainException;
use Throwable;

/** Stripe did not do what a saved payment method change asked. The message is safe to show a client. */
final class StripeRefusal extends DomainException
{
    public function __construct(string $message, public readonly bool $alreadyGone, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
