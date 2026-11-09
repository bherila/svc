<?php

namespace Tests\StripeLive\Support;

use SensitiveParameter;

/**
 * Signs a webhook body the way Stripe does, so an event fetched from the Events
 * API can be delivered to SVC's real endpoint without a public URL:
 * `Stripe-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<payload>">`.
 */
final class StripeWebhookSignature
{
    public static function header(string $payload, #[SensitiveParameter] string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }
}
