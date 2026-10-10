<?php

namespace App\Support\Auth;

use App\Models\User;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderIdentityPolicy;
use BWH\Auth\OAuth\Session\ProviderIdentityStatusClient;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;
use DomainException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Provider identity enforcement for Sanctum personal access tokens, the
 * finance integration's credentials, on the same terms as the OAuth ones.
 *
 * A token issued to a provider-bound account records the provider subject and
 * the credential generation it was issued under. A brand-new credential has no
 * login to inherit a generation from, so it takes the provider's current one;
 * an identity the provider reports inactive is never issued a token. With
 * enforcement on, a bound account's token is refused when it carries no stamp
 * (issued before stamping, so retired rather than upgraded), names another
 * subject, or no longer matches the provider's answer; an unavailable provider
 * is a retryable 503. Unsafe methods check freshly. Accounts with no provider
 * binding are left to the application.
 */
final readonly class PersonalAccessTokenIdentity
{
    public function __construct(
        private ProviderBindingResolver $bindings,
        private ProviderIdentityStatusClient $status,
        private ProviderIdentityPolicy $policy,
    ) {}

    /**
     * The columns to record on a token about to be issued to this user.
     *
     * @return array{provider_subject?: string, provider_generation?: int}
     *
     * @throws DomainException when the token must not be issued
     * @throws ProviderStatusUnavailable when enforcement is on and the provider cannot answer
     */
    public function stampForIssue(User $user): array
    {
        try {
            $binding = $this->bindings->binding($user->fresh() ?? $user);
        } catch (ProviderSessionExpired) {
            throw new DomainException('This account\'s provider binding is invalid, so it cannot be issued a token.');
        }
        if ($binding === null) {
            return [];
        }

        try {
            $status = $this->status->status($binding->subject);
        } catch (ProviderStatusUnavailable $exception) {
            if (ProviderIdentityTokens::enabled()) {
                throw $exception;
            }

            // Issued unstamped while enforcement is off; refused once it is on.
            return [];
        } catch (ProviderSessionExpired) {
            throw new DomainException('This account\'s provider binding is invalid, so it cannot be issued a token.');
        }
        if ($status === null) {
            throw new DomainException('The identity provider reports this account inactive, so it cannot be issued a token.');
        }

        return [
            ProviderIdentityTokens::SUBJECT_COLUMN => $binding->subject,
            ProviderIdentityTokens::GENERATION_COLUMN => $status->credentialVersion,
        ];
    }

    /**
     * Sanctum's token authentication callback: whether a token Sanctum found
     * valid may still act for its owner's provider identity.
     *
     * @throws ProviderStatusUnavailable rendered as a retryable 503
     */
    public function accepts(PersonalAccessToken $token, bool $isValid): bool
    {
        if (! $isValid || ! ProviderIdentityTokens::enabled()) {
            return $isValid;
        }
        $owner = $token->tokenable;
        if (! $owner instanceof User) {
            return $isValid;
        }

        try {
            $binding = $this->bindings->binding($owner);
        } catch (ProviderSessionExpired) {
            return false;
        }
        if ($binding === null) {
            return true;
        }
        // An integer however the driver returns it (MariaDB: a string); the policy
        // refuses a negative baseline itself.
        $generation = filter_var($token->getAttribute(ProviderIdentityTokens::GENERATION_COLUMN), FILTER_VALIDATE_INT);
        if ($token->getAttribute(ProviderIdentityTokens::SUBJECT_COLUMN) !== $binding->subject || $generation === false) {
            return false;
        }

        try {
            $this->policy->verify($binding->subject, $generation, fresh: ! request()->isMethodSafe());
        } catch (ProviderSessionExpired) {
            return false;
        }

        return true;
    }
}
