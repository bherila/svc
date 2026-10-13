<?php

namespace App\Support\Access;

/**
 * Where a client portal invitation stands.
 *
 * `expired` is stored once an invitation past its expiry is met at sign-in;
 * until then a pending invitation past its expiry is reported as expired by
 * {@see self::of()} without being written.
 */
enum PortalInvitationStatus: string
{
    /** Waiting for the invited person to accept and sign in. */
    case Pending = 'pending';

    /** Applied at the invited person's sign-in. */
    case Accepted = 'accepted';

    /** Met at sign-in, but the access it carried could no longer be given. */
    case NotApplied = 'not_applied';

    /** Withdrawn by a manager, or superseded by a newer invitation. */
    case Revoked = 'revoked';

    /** Its link lapsed before the person signed in; resending renews it. */
    case Expired = 'expired';

    /** The status as reported: a pending invitation past its expiry reads as expired. */
    public static function of(string $stored, ?\DateTimeInterface $expiresAt, \DateTimeInterface $now): self
    {
        $status = self::from($stored);

        return $status === self::Pending && $expiresAt !== null && $expiresAt <= $now ? self::Expired : $status;
    }

    /** Whether the provider still holds a live or renewable invitation for it. */
    public function isOpen(): bool
    {
        return match ($this) {
            self::Pending, self::Expired => true,
            self::Accepted, self::NotApplied, self::Revoked => false,
        };
    }
}
