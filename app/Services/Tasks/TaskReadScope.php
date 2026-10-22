<?php

namespace App\Services\Tasks;

use App\Models\AgentPrincipal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\ProjectAccess;
use Illuminate\Database\Eloquent\Builder;

/** Shared read scope; the trusted adapter must choose its audience explicitly. */
final class TaskReadScope
{
    public function __construct(private readonly AgentAccess $access, private readonly ProjectAccess $projects) {}

    /** @return Builder<ClientTask> */
    public function query(User|AgentPrincipal $user, Workspace $workspace, TaskMutationOrigin $origin): Builder
    {
        $query = ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereHas('project', fn (Builder $projects): Builder => $projects->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $companies): Builder => $companies->where('workspace_id', $workspace->id)));
        if ($origin === TaskMutationOrigin::Internal && $this->projects->workspaceRole($user, $workspace) === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($origin === TaskMutationOrigin::Agent && $this->access->isWorkspaceClient($user, $workspace)) {
            return app(TaskVisibility::class)->constrainPortal($query, $workspace, $user);
        }
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->whereHas('project', fn (Builder $projects) => $projects
            ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id)
                ->where('client_project_memberships.workspace_id', $workspace->id)));
    }
}
