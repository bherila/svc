<?php

namespace App\Services;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The tenant-scoped task lifecycle shared by website, REST and MCP adapters. */
final class WorkspaceTaskMutationAction
{
    public function __construct(private readonly ProjectAccess $access, private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    /** @param array<string,mixed> $facts */
    public function create(User $actor, Workspace $workspace, string $projectId, array $facts, bool $visibleDefault = false): ClientTask
    {
        return DB::transaction(function () use ($actor, $workspace, $projectId, $facts, $visibleDefault): ClientTask {
            $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectId)
                ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))->firstOrFail();
            $project->setRelation('workspace', $workspace);
            abort_unless($this->access->canManageTasks($actor, $project), 403);
            $task = $project->tasks()->create([
                'workspace_id' => $workspace->id, 'title' => $facts['title'], 'description' => $facts['description'] ?? null,
                'status' => 'open', 'is_visible_to_client' => $facts['is_visible_to_client'] ?? $visibleDefault,
            ]);

            return $task->setRelation('project', $project);
        });
    }

    /** @param array<string,mixed> $facts */
    public function update(User $actor, Workspace $workspace, string $taskId, array $facts, ?string $expectedVersion = null): ClientTask
    {
        return DB::transaction(function () use ($actor, $workspace, $taskId, $facts, $expectedVersion): ClientTask {
            $task = $this->query($workspace)->where('public_id', $taskId)->tap(Locks::forUpdate())->firstOrFail();
            $task->project->setRelation('workspace', $workspace);
            abort_unless($this->access->canManageTasks($actor, $task->project), 403);
            abort_unless($expectedVersion === null || AgentApiVersion::matches($task, $expectedVersion), 409, 'The task has changed; read it and retry.');
            $attributes = array_intersect_key($facts, array_flip(['title', 'description', 'status', 'is_visible_to_client']));
            if (array_key_exists('status', $attributes)) {
                $attributes['completed_at'] = $attributes['status'] === 'completed' ? $this->clock->now($workspace)->utc() : null;
            }
            $task->forceFill($attributes);
            if ($expectedVersion !== null && ! $task->isDirty()) {
                // Agent updates have always advanced the revision, including no-op updates.
                $task->forceFill(['lock_version' => $task->lock_version + 1]);
            }
            $task->save();

            return $task;
        });
    }

    public function find(Workspace $workspace, ?string $id): ClientTask
    {
        return $this->query($workspace)->where('public_id', $id)->firstOrFail();
    }

    /** @return Builder<ClientTask> */
    private function query(Workspace $workspace): Builder
    {
        return ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereHas('project', fn (Builder $project): Builder => $project->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)))
            ->with(['project' => fn ($project) => $project->where('workspace_id', $workspace->id)]);
    }
}
