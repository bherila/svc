<?php

namespace App\Policies;

use App\Models\ClientProject;
use App\Models\User;
use App\Services\Authorization\ProjectAccess;

class ClientProjectPolicy
{
    public function view(User $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canView($user, $project);
    }

    public function manageTasks(User $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canManageTasks($user, $project);
    }

    public function approveTime(User $user, ClientProject $project): bool
    {
        return app(ProjectAccess::class)->canApproveTime($user, $project);
    }
}
