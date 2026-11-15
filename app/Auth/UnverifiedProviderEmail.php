<?php

namespace App\Auth;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * A sign-in refused because the identity provider says the address it asserts is not verified
 * ({@see ProviderEmailVerification}). Nothing was created, linked or updated.
 *
 * Carries no address or subject: the provider name is all a log needs.
 */
final class UnverifiedProviderEmail extends AccessDeniedHttpException
{
    public function __construct(public readonly string $provider)
    {
        parent::__construct('Your identity provider has not verified the email address on your account. Verify it with the provider, then sign in again.');
    }
}
