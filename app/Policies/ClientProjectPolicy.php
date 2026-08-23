<?php

namespace App\Policies;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\User;
use App\Services\Authorization\ProjectAccess;

class ClientProjectPolicy
{
    public function view(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canView($user, $project);
    }

    public function manageTasks(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canManageTasks($user, $project);
    }

    public function approveTime(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canApproveTime($user, $project);
    }
}
