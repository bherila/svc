<?php

declare(strict_types=1);

namespace App\Support\Payments;

/** Where a processor delivers a connection's webhook events. */
final class WebhookUrl
{
    /** The per-connection URL: the opaque token picks the connection (see StripeWebhookController). */
    public static function forConnection(PaymentProcessor $processor, string $token): string
    {
        return self::base($processor).'/'.$token;
    }

    /** The legacy single-account URL, still served from the environment connection. */
    public static function legacy(PaymentProcessor $processor): string
    {
        return self::base($processor);
    }

    private static function base(PaymentProcessor $processor): string
    {
        $app = config('app.url');

        return rtrim(is_string($app) ? $app : '', '/').'/api/webhooks/'.$processor->value;
    }
}
