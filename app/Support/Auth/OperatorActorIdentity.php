<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * Whether an operator command may act as a named account (docs/provider-identity.md, gap 1).
 *
 * A command that takes `--actor` or `--user` acts as that account: the record it writes is
 * attributed to them, and it is authorized on their memberships. With provider identity
 * enforcement on, it may do so only while the provider says that identity is still active,
 * as their own session or token could. The provider is asked now, never a cached answer, so
 * an outage refuses (retryably) instead of passing. Only liveness is checked: the command
 * carries no credential, so there is no generation baseline to compare
 * ({@see ProviderIdentityLiveness}, which autopay's consent check shares).
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

    public function __construct(private ProviderIdentityLiveness $liveness) {}

    /**
     * Null when a command may act as this account; otherwise why not, for the operator.
     */
    public function refusal(User $actor): ?string
    {
        return match ($this->liveness->of($actor)) {
            ProviderLiveness::NotChecked, ProviderLiveness::Active => null,
            ProviderLiveness::Ended => self::ENDED,
            ProviderLiveness::BrokenBinding => self::BROKEN_BINDING,
            ProviderLiveness::Unavailable => self::UNAVAILABLE,
        };
    }
}
