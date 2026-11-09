<?php

namespace Tests\StripeLive\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * The safety gate of the Stripe test-mode suite. The suite creates customers and
 * charges them, so it must never run against a live account: only a key that
 * starts with `sk_test_` or `rk_test_` passes. Absent keys skip the suite; every
 * other key is refused loudly. The key itself is never echoed.
 */
final class StripeTestKeyGate
{
    public const string ENVIRONMENT_VARIABLE = 'STRIPE_TEST_SECRET_KEY';

    public static function verdict(#[SensitiveParameter] ?string $key): StripeKeyVerdict
    {
        $key = $key === null ? '' : trim($key);
        if ($key === '') {
            return StripeKeyVerdict::Absent;
        }

        return str_starts_with($key, 'sk_test_') || str_starts_with($key, 'rk_test_')
            ? StripeKeyVerdict::TestMode
            : StripeKeyVerdict::Refused;
    }

    /** The key to use, or null when none is configured (skip). Throws for a key that is not test mode. */
    public static function acceptedKey(#[SensitiveParameter] ?string $key): ?string
    {
        return match (self::verdict($key)) {
            StripeKeyVerdict::Absent => null,
            StripeKeyVerdict::TestMode => trim((string) $key),
            StripeKeyVerdict::Refused => throw new RuntimeException(self::ENVIRONMENT_VARIABLE.' is not a Stripe test-mode key (it must start with sk_test_ or rk_test_). The live suite refuses to run, and the key is not printed.'),
        };
    }

    /** The key from the process environment, wherever PHPUnit or the shell put it. */
    public static function fromEnvironment(): ?string
    {
        foreach ([getenv(self::ENVIRONMENT_VARIABLE), $_ENV[self::ENVIRONMENT_VARIABLE] ?? null, $_SERVER[self::ENVIRONMENT_VARIABLE] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }
}
