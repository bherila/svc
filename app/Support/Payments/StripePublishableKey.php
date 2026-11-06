<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * What can be checked about a Stripe publishable key without calling Stripe.
 * Pure and database-free.
 *
 * Mode is the only fact a publishable key carries: it is `pk_live_` or
 * `pk_test_` followed by an opaque string. The account it belongs to cannot be
 * resolved from the key alone, so that is checked against Stripe itself when
 * the key is saved ({@see StripeAccountCheck}); this class is the part that
 * needs no call, and it runs first.
 */
final class StripePublishableKey
{
    /** `live`, `test`, or null when this is not a publishable key. */
    public static function modeOf(string $key): ?string
    {
        if (preg_match('/^pk_(live|test)_[A-Za-z0-9]{8,250}$/D', $key, $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /**
     * Why a key typed beside a restricted key of `$secretMode` is refused, or
     * null when it may be stored.
     */
    public static function rejection(string $key, string $secretMode): ?string
    {
        $mode = self::modeOf($key);
        if ($mode === null) {
            return 'Enter a Stripe publishable key (it starts with pk_live_ or pk_test_).';
        }
        if ($mode !== $secretMode) {
            return "This is a {$mode}-mode publishable key but the restricted key is {$secretMode}-mode. Use keys from the same mode.";
        }

        return null;
    }
}
