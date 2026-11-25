<?php

namespace App\Support\Auth;

use App\Models\User;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderIdentityEnforcement;
use BWH\Auth\OAuth\Session\ProviderIdentityStatusClient;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;

/**
 * Whether an account's provider identity is still live, asked of the provider now, for code
 * that acts on someone's standing authority without their credential: an operator command
 * acting as a named account (gap 1) and autopay charging on a client contact's consent
 * (gap 2) in docs/provider-identity.md.
 *
 * Only liveness is checked: there is no credential, so no generation baseline to compare.
 * The provider is asked freshly, never a cached observation, so an outage is
 * {@see ProviderLiveness::Unavailable} and never a pass. Nothing is asked while enforcement
 * is off or for an account with no provider binding. auth-laravel v0.30.2 has no
 * `ProviderIdentityPolicy::current()`, so this calls `ProviderIdentityStatusClient`
 * directly, as issuing a token does.
 */
final readonly class ProviderIdentityLiveness
{
    public function __construct(
        private ProviderIdentityEnforcement $enforcement,
        private ProviderBindingResolver $bindings,
        private ProviderIdentityStatusClient $status,
    ) {}

    public function of(User $user): ProviderLiveness
    {
        // Asks the package, not the setting: a recorded cutover keeps enforcement on even
        // when the enforcement setting is off.
        if (! $this->enforcement->enabled()) {
            return ProviderLiveness::NotChecked;
        }

        try {
            $binding = $this->bindings->binding($user);
            if ($binding === null) {
                return ProviderLiveness::NotChecked;
            }

            return $this->status->status($binding->subject) === null ? ProviderLiveness::Ended : ProviderLiveness::Active;
        } catch (ProviderSessionExpired) {
            return ProviderLiveness::BrokenBinding;
        } catch (ProviderStatusUnavailable) {
            return ProviderLiveness::Unavailable;
        }
    }
}
