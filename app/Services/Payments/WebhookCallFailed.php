<?php

namespace App\Services\Payments;

use App\Support\Payments\WebhookHealthStatus;
use RuntimeException;

/** A call to Stripe's webhook endpoints that did not succeed, and which kind of failure it was. */
final class WebhookCallFailed extends RuntimeException
{
    public function __construct(public readonly WebhookHealthStatus $outcome)
    {
        parent::__construct('A Stripe webhook endpoint call failed: '.$outcome->value.'.');
    }
}
