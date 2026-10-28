<?php

namespace App\Services\Tasks;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;

/** Bounded staff identity choices; membership administration is a separate operation. */
final class TaskAssigneeReadService
{
    public function __construct(private readonly WorkspaceTaskRepository $tasks, private readonly TaskAccess $access, private readonly AgentAccess $workspaceAccess) {}

    /** @return array{data:list<array{id:string,name:string}>,has_more:bool} */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, string $projectId, ?string $q = '', TaskMutationOrigin $origin = TaskMutationOrigin::Agent): array
    {
        $input = validator(['q' => $q], ['q' => ['nullable', 'string', 'max:100']])->validate();
        abort_unless($this->workspaceAccess->canViewWorkspace($actor, $workspace), 403);
        abort_if($origin === TaskMutationOrigin::Agent && $this->workspaceAccess->isWorkspaceClient($actor, $workspace), 403);
        $project = $this->tasks->project($workspace, $projectId);
        abort_unless($this->access->canManage($actor, $workspace, $project), 403);

        return $this->tasks->assigneeOptions($workspace, $project, $input['q'] ?? '');
    }
}
