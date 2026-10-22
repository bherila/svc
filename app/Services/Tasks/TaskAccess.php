<?php

namespace App\Services\Tasks;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\Eloquent\Builder;

/** Read capabilities only; writes recheck current facts under the workspace lock. */
final class TaskAccess
{
    /** null means workspace administration; an empty list grants no project controls.
     * @return list<int>|null
     */
    public function manageableProjectIds(User|AgentPrincipal $actor, Workspace $workspace): ?array
    {
        $role = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)->value('role');
        if (in_array($role, ['owner', 'admin'], true)) {
            return null;
        }
        if ($role === null) {
            return [];
        }

        return array_values(ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $actor->id)
            ->whereIn('role', ['owner', 'manager'])
            ->whereHas('project', fn (Builder $projects): Builder => $projects->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $companies): Builder => $companies->where('workspace_id', $workspace->id)))
            ->pluck('client_project_id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    public function canManage(User|AgentPrincipal $actor, Workspace $workspace, ClientProject $project): bool
    {
        $ids = $this->manageableProjectIds($actor, $workspace);

        return (int) $project->workspace_id === (int) $workspace->id && ($ids === null || in_array((int) $project->id, $ids, true));
    }
}
