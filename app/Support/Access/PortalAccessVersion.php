<?php

namespace App\Support\Access;

/** A portal contact's identity, role and exact reach, independent of project order. */
final class PortalAccessVersion
{
    public static function for(string $membershipId, string $role, PortalAccessState $state, string $key): string
    {
        $projects = $state->projectPublicIds;
        sort($projects);

        return hash_hmac('sha256', json_encode([$membershipId, $role, $state->scope->value, $projects], JSON_THROW_ON_ERROR), $key);
    }
}
