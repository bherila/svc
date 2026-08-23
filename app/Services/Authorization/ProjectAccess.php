<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;

final class ProjectAccess
{
    public function workspaceRole(User|AgentPrincipal $user, Workspace $workspace): ?string
    {
        $role = $workspace->memberships()->where('user_id', $user->id)->value('role');

        return is_string($role) ? $role : null;
    }

    public function projectRole(User|AgentPrincipal $user, ClientProject $project): ?ProjectRole
    {
        if ($this->isWorkspaceManager($user, $project->workspace)) {
            return ProjectRole::Owner;
        }

        $role = ClientProjectMembership::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('client_project_id', $project->id)
            ->where('user_id', $user->id)
            ->value('role');

        if ($role instanceof ProjectRole) {
            return $role;
        }

        return is_string($role) ? ProjectRole::tryFrom($role) : null;
    }

    public function canView(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return $this->projectRole($user, $project) !== null;
    }

    public function canManageTasks(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return $this->projectRole($user, $project)?->canManageTasks() ?? false;
    }

    public function canApproveTime(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return $this->projectRole($user, $project)?->canApproveTime() ?? false;
    }

    public function isWorkspaceManager(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return in_array($this->workspaceRole($user, $workspace), ['owner', 'admin'], true);
    }
}
