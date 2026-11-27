<?php

namespace App\Support\Auth;

/** What the identity provider says, now, about one account here ({@see ProviderIdentityLiveness}). */
enum ProviderLiveness: string
{
    /** Not the provider's to answer: enforcement is off, or the account has no provider binding. */
    case NotChecked = 'not_checked';
    /** The provider says the identity is active. */
    case Active = 'active';
    /** The provider says the identity has ended: disabled, deleted or ungranted. */
    case Ended = 'ended';
    /** The account's binding names another issuer or an empty subject; never treated as unbound. */
    case BrokenBinding = 'broken_binding';
    /** The provider could not answer. Not a pass: ask again later. */
    case Unavailable = 'unavailable';

    /** Whether the account must be treated as no longer a live identity. */
    public function ended(): bool
    {
        return match ($this) {
            self::Ended, self::BrokenBinding => true,
            self::NotChecked, self::Active, self::Unavailable => false,
        };
    }
}
