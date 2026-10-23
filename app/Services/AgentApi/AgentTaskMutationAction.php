<?php

namespace App\Services\AgentApi;

use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskPatchData;
use App\Services\WorkspaceTaskMutationAction;

/** The tenant-scoped task mutation workflow shared by REST and MCP. */
final class AgentTaskMutationAction
{
    public function __construct(
        private readonly AgentMutationExecutor $mutations,
        private readonly WorkspaceTaskMutationAction $tasks,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $projectId, array $data): ClientTask
    {
        return $this->createForOrigin($user, $workspace, $clientId, $idempotencyKey, $projectId, $data, TaskMutationOrigin::Agent);
    }

    /** Trusted local adapter; never selected from request input.
     * @param  array<string, mixed>  $data
     */
    public function createInternal(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $projectId, array $data): ClientTask
    {
        return $this->createForOrigin($user, $workspace, $clientId, $idempotencyKey, $projectId, $data, TaskMutationOrigin::Internal);
    }

    /** @param array<string, mixed> $data */
    private function createForOrigin(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $projectId, array $data, TaskMutationOrigin $origin): ClientTask
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'tasks.create', $idempotencyKey,
            ['project_id' => $projectId, 'body' => $data],
            function () use ($workspace, $projectId, $user, $data, $origin): array {
                $task = $this->tasks->create($user, $workspace, $projectId, TaskCreateData::fromValidated($data), $origin);

                return [$task->public_id];
            },
            fn (array $ids) => $this->assertReplayAllowed($workspace, $user, $ids, $origin),
        );

        return $this->task($workspace, $ids[0] ?? null);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $taskId, array $data): ClientTask
    {
        return $this->updateForOrigin($user, $workspace, $clientId, $idempotencyKey, $taskId, $data, TaskMutationOrigin::Agent);
    }

    /** @param array<string, mixed> $data */
    public function updateInternal(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $taskId, array $data): ClientTask
    {
        return $this->updateForOrigin($user, $workspace, $clientId, $idempotencyKey, $taskId, $data, TaskMutationOrigin::Internal);
    }

    /** @param array<string, mixed> $data */
    private function updateForOrigin(User $user, Workspace $workspace, string $clientId, string $idempotencyKey, string $taskId, array $data, TaskMutationOrigin $origin): ClientTask
    {
        $ids = $this->mutations->run(
            $user, $workspace, $clientId, 'tasks.update', $idempotencyKey,
            ['task_id' => $taskId, 'body' => $data],
            function () use ($workspace, $taskId, $user, $data, $origin): array {
                $task = $this->tasks->update($user, $workspace, $taskId, TaskPatchData::fromValidated($data), $data['expected_version'], $origin);

                return [$task->public_id];
            },
            fn (array $ids) => $this->assertReplayAllowed($workspace, $user, $ids, $origin),
        );

        return $this->task($workspace, $ids[0] ?? null);
    }

    /** @param list<string> $ids */
    private function assertReplayAllowed(Workspace $workspace, User $user, array $ids, TaskMutationOrigin $origin): void
    {
        // Both task operations produce exactly one task; refuse malformed receipts.
        abort_unless(count($ids) === 1, 404);
        $this->tasks->readManaged($user, $workspace, $ids[0], $origin);
    }

    private function task(Workspace $workspace, ?string $id): ClientTask
    {
        return $this->tasks->find($workspace, $id);
    }
}
