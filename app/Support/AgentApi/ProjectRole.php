<?php

namespace App\Support\AgentApi;

enum ProjectRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Contributor = 'contributor';
    case Viewer = 'viewer';

    public function canManageTasks(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }

    public function canApproveTime(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
