<?php

namespace App\Support\Billing;

use DomainException;

/** Trusted adapter metadata; never populated from a received-payment request. */
final readonly class ReceivedPaymentProvider
{
    public function __construct(public string $name, public string $paymentIdentifier)
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/D', $name) !== 1
            || $paymentIdentifier === '' || strlen($paymentIdentifier) > 255) {
            throw new DomainException('Invalid received-payment provider identity.');
        }
    }
}
