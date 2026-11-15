<?php

namespace App\Auth;

use App\Listeners\RecordProviderEmailVerification;
use BWH\Auth\OAuth\Client\AuthorizationServer;
use BWH\Auth\OAuth\OAuthIdentity;
use Closure;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;

/**
 * The identity provider's `email_verified` claim for the sign-in callback being handled.
 *
 * The auth package reads the provider's identity response into an {@see OAuthIdentity}, which
 * has no place for the claim. So while {@see during()} runs a sign-in callback, this keeps the
 * body of that one response as the HTTP client receives it
 * ({@see RecordProviderEmailVerification}), and {@see OAuthAccounts} asks
 * {@see refuses()} before it creates an account or refreshes one from the identity.
 *
 * An absent claim keeps the provider's address trusted, which is what SECURITY.md records as
 * the identity-provider assumption. A present claim is honoured only when it is `true` or the
 * string "true" (some providers send booleans as strings); any other value, `false`, "false",
 * "0", null or something unrecognised, means the address is not verified. While a callback is
 * being handled, an identity this class did not see arrive, or one for a different subject, is
 * refused too: the claim cannot be read for it, so it is not assumed.
 */
final class ProviderEmailVerification
{
    private bool $watching = false;

    /** @var array<array-key, mixed>|null the provider's identity response body, once received */
    private ?array $claims = null;

    /**
     * Run a sign-in callback with the provider's identity response watched.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(Closure $callback): mixed
    {
        $this->watching = true;
        $this->claims = null;

        try {
            return $callback();
        } finally {
            $this->watching = false;
            $this->claims = null;
        }
    }

    /** Keep the body of the sign-in provider's identity response, the first one only. */
    public function observe(ClientRequest $request, ClientResponse $response): void
    {
        if (! $this->watching || $this->claims !== null || $request->method() !== 'GET') {
            return;
        }

        // Read only once the callback has reached the provider, so a misconfigured provider
        // fails where the package checks it rather than here.
        if ($request->url() !== AuthorizationServer::resolve(null)->endpoint('identity_path')) {
            return;
        }

        $body = $response->json();
        $this->claims = is_array($body) ? $body : [];
    }

    /** Whether the provider's address for this identity must not be used. */
    public function refuses(OAuthIdentity $identity): bool
    {
        if (! $this->watching) {
            return false;
        }

        if ($this->claims === null || ($this->claims['sub'] ?? null) !== $identity->subject) {
            return true;
        }

        if (! array_key_exists('email_verified', $this->claims)) {
            return false;
        }

        return ! self::verified($this->claims['email_verified']);
    }

    private static function verified(mixed $claim): bool
    {
        return $claim === true || (is_string($claim) && strtolower(trim($claim)) === 'true');
    }
}
