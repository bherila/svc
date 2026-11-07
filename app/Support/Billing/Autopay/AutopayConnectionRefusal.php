<?php

declare(strict_types=1);

namespace App\Support\Billing\Autopay;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;

/**
 * Whether Stripe refused an autopay charge because of the provider's own
 * connection rather than the client's card (#641). Pure: it reads the error
 * and nothing else.
 *
 * A bad, revoked or under-permissioned key, or an account that cannot take the
 * charge, charged no one and says nothing about the client, so the one attempt
 * is not spent. A card decline, or any other refusal of the charge itself, is
 * the invoice's one failed attempt. `InvalidRequestException` is a connection
 * problem only for the few codes that name the key or the account; the rest
 * (a deleted customer, a bad parameter) are about this charge and stay failed.
 */
final class AutopayConnectionRefusal
{
    /** Stripe error codes that name the key or the account, not the charge. */
    public const array ACCOUNT_CODES = [
        'api_key_expired',
        'account_invalid',
        'platform_account_required',
        'secret_key_required',
        'livemode_mismatch',
        'testmode_charges_only',
    ];

    public static function refuses(ApiErrorException $refusal): bool
    {
        if ($refusal instanceof AuthenticationException || $refusal instanceof PermissionException) {
            return true;
        }

        return $refusal instanceof InvalidRequestException
            && in_array($refusal->getStripeCode(), self::ACCOUNT_CODES, true);
    }
}
