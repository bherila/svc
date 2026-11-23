<?php

namespace App\Support\Auth;

use App\Models\User;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderIdentityEnforcement;
use BWH\Auth\OAuth\Session\ProviderIdentityStatusClient;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use BWH\Auth\OAuth\Session\ProviderStatusUnavailable;

/**
 * Whether an operator command may act as a named account (docs/provider-identity.md, gap 1).
 *
 * A command that takes `--actor` or `--user` acts as that account: the record it writes is
 * attributed to them, and it is authorized on their memberships. With provider identity
 * enforcement on, it may do so only while the provider says that identity is still active,
 * as their own session or token could. The provider is asked now, never a cached answer, so
 * an outage refuses (retryably) instead of passing. Only liveness is checked: the command
 * carries no credential, so there is no generation baseline to compare.
 *
 * Accounts with no provider binding are not the provider's to end, and nothing changes
 * while enforcement is off. A broken binding (another issuer, an empty subject) is refused,
 * never treated as unbound, as everywhere else.
 *
 * Commands that only name a credential's owner (issuing and revoking credentials) do not
 * ask: revoking must work for an ended identity and during an outage, and issuing makes the
 * provider check itself (PersonalAccessTokenIdentity::stampForIssue).
 */
final readonly class OperatorActorIdentity
{
    public const string ENDED = 'The identity provider reports that this account\'s identity has ended, so a command cannot act as it. Nothing was written.';

    public const string BROKEN_BINDING = 'This account\'s provider binding is invalid, so a command cannot act as it. Nothing was written.';

    public const string UNAVAILABLE = 'Provider identity verification is unavailable, so a command cannot act as this account now. Nothing was written; retry later.';

    public function __construct(
        private ProviderIdentityEnforcement $enforcement,
        private ProviderBindingResolver $bindings,
        private ProviderIdentityStatusClient $status,
    ) {}

    /**
     * Null when a command may act as this account; otherwise why not, for the operator.
     */
    public function refusal(User $actor): ?string
    {
        // Asks the package, not the setting: a recorded cutover keeps enforcement on even
        // when the enforcement setting is off.
        if (! $this->enforcement->enabled()) {
            return null;
        }

        try {
            $binding = $this->bindings->binding($actor);
            if ($binding === null) {
                return null;
            }

            return $this->status->status($binding->subject) === null ? self::ENDED : null;
        } catch (ProviderSessionExpired) {
            return self::BROKEN_BINDING;
        } catch (ProviderStatusUnavailable) {
            return self::UNAVAILABLE;
        }
    }
}
