<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientProjectMembership;

final class AgentProjectMemberPresenter
{
    /** @return array{user_id:string,role:string} */
    public static function grant(string $publicUserId, ?ClientProjectMembership $grant): array
    {
        return ['user_id' => $publicUserId, 'role' => $grant === null ? 'none' : $grant->role->value];
    }
}
