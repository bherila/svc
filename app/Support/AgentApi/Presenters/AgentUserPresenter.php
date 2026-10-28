<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\User;

/** Public identities for already-authorized member choices; never email/account metadata. */
final class AgentUserPresenter
{
    /** @return array{id:string,name:string} */
    public static function identity(User $user): array
    {
        return ['id' => $user->public_id, 'name' => $user->name];
    }
}
