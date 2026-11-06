<?php

namespace App\Support\SharedProjects;

/** An invited address, compared the way every lookup of it is: trimmed and lower-cased. */
final class InviteeAddress
{
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Whether the signed-in person's address is the one the invitation was written to. */
    public static function matches(string $invited, ?string $account): bool
    {
        if ($account === null || trim($account) === '') {
            return false;
        }

        return hash_equals(self::normalize($invited), self::normalize($account));
    }
}
