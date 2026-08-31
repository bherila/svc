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
        $workspaceRole = $this->workspaceRole($user, $project->workspace);

        if ($workspaceRole === null) {
            return null;
        }

        if (in_array($workspaceRole, ['owner', 'admin'], true)) {
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

    /**
     * Every project id this user may view in this workspace, or null for all.
     *
     * Null means an owner or admin, who reaches everything - including a
     * company with no projects at all, which nobody could otherwise reach.
     *
     * Resolved in one query on purpose. This service holds no cache, so asking
     * {@see self::canView()} while filtering a list costs a membership lookup
     * per row: scoping the client directory that way turned a 13-query page
     * into a 53-query one before this existed.
     *
     * @return list<int>|null
     */
    public function viewableProjectIds(User|AgentPrincipal $user, Workspace $workspace): ?array
    {
        if (in_array($this->workspaceRole($user, $workspace), ['owner', 'admin'], true)) {
            return null;
        }

        return array_values(ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->pluck('client_project_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * The client companies this user reaches in this workspace, or null for all.
     *
     * Reachability runs through projects (#157), so this is the one place that
     * turns "which projects" into "which clients". It exists because three
     * surfaces needed the same answer - the directory, the company switcher and
     * the workspace invoice list - and two copies of it is how the directory
     * and the time sheet came to disagree in the first place.
     *
     * Null means an owner or admin, who reaches every client including one with
     * no projects at all.
     *
     * @return list<int>|null
     */
    public function reachableCompanyIds(User|AgentPrincipal $user, Workspace $workspace): ?array
    {
        $projectIds = $this->viewableProjectIds($user, $workspace);

        if ($projectIds === null) {
            return null;
        }

        if ($projectIds === []) {
            return [];
        }

        return array_values(array_unique(ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $projectIds)
            ->pluck('client_company_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
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

    public function canLogTime(User|AgentPrincipal $user, ClientProject $project): bool
    {
        return $this->projectRole($user, $project)?->canLogTime() ?? false;
    }

    public function isWorkspaceManager(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return in_array($this->workspaceRole($user, $workspace), ['owner', 'admin'], true);
    }
}
