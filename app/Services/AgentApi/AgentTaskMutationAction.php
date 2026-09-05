<?php

namespace App\Services\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\WorkspaceClock;
use Illuminate\Support\Facades\DB;

/** The tenant-scoped task mutation workflow shared by REST and MCP. */
final class AgentTaskMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly ProjectAccess $access,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $projectId, array $data): ClientTask
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'tasks.create', $idempotencyKey,
            ['project_id' => $projectId, 'body' => $data],
            function () use ($workspace, $projectId, $user, $data): array {
                $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectId)->firstOrFail();
                abort_unless($this->access->canManageTasks($user, $project), 403);
                $task = $project->tasks()->create(['workspace_id' => $workspace->id] + $data + ['is_visible_to_client' => $data['is_visible_to_client'] ?? false]);

                return [$task->public_id];
            },
            fn (array $ids) => $this->assertReplayAllowed($workspace, $user, $ids),
        );

        return $this->task($workspace, $ids[0] ?? null);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $taskId, array $data): ClientTask
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'tasks.update', $idempotencyKey,
            ['task_id' => $taskId, 'body' => $data],
            function () use ($workspace, $taskId, $user, $data): array {
                $task = $this->task($workspace, $taskId);
                abort_unless($this->access->canManageTasks($user, $task->project), 403);
                $attributes = $data;
                if (($attributes['status'] ?? null) === 'completed') {
                    $attributes['completed_at'] = $this->clock->now($workspace);
                } elseif (array_key_exists('status', $attributes)) {
                    $attributes['completed_at'] = null;
                }
                unset($attributes['expected_version']);
                abort_unless(AgentApiVersion::matches($task, $data['expected_version']), 409, 'The task has changed; read it and retry.');
                $updated = ClientTask::query()
                    ->whereKey($task->id)
                    ->where('workspace_id', $workspace->id)
                    ->where('lock_version', $task->lock_version)
                    ->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
                abort_unless($updated === 1, 409, 'The task has changed; read it and retry.');

                return [$task->public_id];
            },
            fn (array $ids) => $this->assertReplayAllowed($workspace, $user, $ids),
        );

        return $this->task($workspace, $ids[0] ?? null);
    }

    /** @param list<string> $ids */
    private function assertReplayAllowed(Workspace $workspace, User $user, array $ids): void
    {
        $tasks = ClientTask::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $ids)->with('project')->get();
        abort_unless($tasks->count() === count($ids), 404);
        foreach ($tasks as $task) {
            abort_unless($this->access->canManageTasks($user, $task->project), 403);
        }
    }

    private function task(Workspace $workspace, ?string $id): ClientTask
    {
        return ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->with('project')->firstOrFail();
    }
}
