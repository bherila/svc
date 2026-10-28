<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\Workspace;

final class AgentWorkspacePresenter
{
    /** @return array{id: string, name: string} */
    public static function identity(Workspace $workspace): array
    {
        return ['id' => $workspace->public_id, 'name' => $workspace->name];
    }
}
