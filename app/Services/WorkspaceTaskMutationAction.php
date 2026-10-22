<?php

namespace App\Services;

use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskPatchData;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/** The tenant-scoped task lifecycle shared by website, REST, MCP and CLI adapters. */
final class WorkspaceTaskMutationAction
{
    public function __construct(
        private readonly WorkspaceTaskRepository $tasks,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param TaskCreateData|array<string,mixed> $facts */
    public function create(User $actor, Workspace $workspace, string $projectId, TaskCreateData|array $facts, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): ClientTask
    {
        $data = $facts instanceof TaskCreateData ? $facts : TaskCreateData::fromValidated($facts);

        return DB::transaction(function () use ($actor, $workspace, $projectId, $data, $origin): ClientTask {
            BillingMaintenance::share($workspace->id);
            $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
            $project = $this->tasks->project($lockedWorkspace, $projectId);
            $access = $this->tasks->access($lockedWorkspace, $project, $actor, $data->assigneeIds);
            abort_if($origin === TaskMutationOrigin::Agent && $access->portalMember, 403);
            abort_unless($access->canManage($actor->id), 403);

            return $this->tasks->create($lockedWorkspace, $project, $data, $access);
        });
    }

    /** @param TaskPatchData|array<string,mixed> $facts */
    public function update(User $actor, Workspace $workspace, string $taskId, TaskPatchData|array $facts, ?string $expectedVersion = null, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): ClientTask
    {
        $data = $facts instanceof TaskPatchData ? $facts : TaskPatchData::fromValidated($facts);

        return DB::transaction(function () use ($actor, $workspace, $taskId, $data, $expectedVersion, $origin): ClientTask {
            BillingMaintenance::share($workspace->id);
            $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
            $task = $this->tasks->find($lockedWorkspace, $taskId, lock: true);
            $task->project->setRelation('workspace', $lockedWorkspace);
            $access = $this->tasks->access($lockedWorkspace, $task->project, $actor, $data->assigneeIds ?? []);
            abort_if($origin === TaskMutationOrigin::Agent && $access->portalMember, 403);
            abort_unless($access->canManage($actor->id), 403);
            abort_unless($expectedVersion === null || AgentApiVersion::matches($task, $expectedVersion), 409, 'The task has changed; read it and retry.');
            $attributes = $data->attributes($task->starts_on?->toDateString(), $task->due_on?->toDateString());
            if (array_key_exists('status', $attributes) && $attributes['status'] !== $task->status) {
                $attributes['completed_at'] = $attributes['status'] === 'completed' ? $this->clock->now($lockedWorkspace)->utc() : null;
            }
            $assignmentsChanged = $data->assigneeIds !== null && $this->tasks->assign($lockedWorkspace, $task, $data->assigneeIds, $access);
            // Pivot-only updates advance the same revision as metadata updates.
            $this->tasks->save($task, $attributes, $expectedVersion !== null || $assignmentsChanged);

            return $this->tasks->find($lockedWorkspace, $taskId, lock: true);
        });
    }

    /** Replays recheck current authority, including inside a caller's older snapshot. */
    public function readManaged(User $actor, Workspace $workspace, string $id, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): ClientTask
    {
        return DB::transaction(function () use ($actor, $workspace, $id, $origin): ClientTask {
            BillingMaintenance::share($workspace->id);
            $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
            $task = $this->tasks->find($lockedWorkspace, $id, lock: true);
            $access = $this->tasks->access($lockedWorkspace, $task->project, $actor, []);
            abort_if($origin === TaskMutationOrigin::Agent && $access->portalMember, 403);
            abort_unless($access->canManage($actor->id), 403);

            return $task;
        });
    }

    public function find(Workspace $workspace, ?string $id): ClientTask
    {
        return $this->tasks->find($workspace, $id);
    }
}
