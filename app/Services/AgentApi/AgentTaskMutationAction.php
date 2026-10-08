<?php

namespace App\Services\AgentApi;

use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceTaskMutationAction;

/** The tenant-scoped task mutation workflow shared by REST and MCP. */
final class AgentTaskMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly ProjectAccess $access,
        private readonly WorkspaceTaskMutationAction $tasks,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $projectId, array $data): ClientTask
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'tasks.create', $idempotencyKey,
            ['project_id' => $projectId, 'body' => $data],
            function () use ($workspace, $projectId, $user, $data): array {
                $task = $this->tasks->create($user, $workspace, $projectId, $data);

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
                $task = $this->tasks->update($user, $workspace, $taskId, $data, $data['expected_version']);

                return [$task->public_id];
            },
            fn (array $ids) => $this->assertReplayAllowed($workspace, $user, $ids),
        );

        return $this->task($workspace, $ids[0] ?? null);
    }

    /** @param list<string> $ids */
    private function assertReplayAllowed(Workspace $workspace, User $user, array $ids): void
    {
        // Both task operations produce exactly one task; refuse malformed receipts.
        abort_unless(count($ids) === 1, 404);
        $task = $this->tasks->find($workspace, $ids[0]);
        $task->project->setRelation('workspace', $workspace);
        abort_unless($this->access->canManageTasks($user, $task->project), 403);
    }

    private function task(Workspace $workspace, ?string $id): ClientTask
    {
        return $this->tasks->find($workspace, $id);
    }
}
