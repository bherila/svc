<?php

namespace App\Services\AgentApi;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskGraph;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationCommand;
use App\Services\Tasks\Organization\TaskOrganizationGate;
use App\Services\Tasks\TaskMutationOrigin;

/** One retry/audit boundary; origin is chosen only by a trusted adapter. */
final class AgentTaskOrganizationMutationAction
{
    public function __construct(private readonly AgentMutationExecutor $mutations, private readonly TaskOrganizationAction $organization) {}

    /** @param array<string, mixed> $input
     * @return list<string> */
    public function execute(User $actor, Workspace $workspace, string $clientId, string $key, string $projectId, string $operation, array $input): array
    {
        return $this->executeForOrigin($actor, $workspace, $clientId, $key, $projectId, $operation, $input, TaskMutationOrigin::Agent);
    }

    /** Native website/CLI entry point; never selected by request input.
     * @param array<string, mixed> $input
     * @return list<string> */
    public function executeInternal(User $actor, Workspace $workspace, string $clientId, string $key, string $projectId, string $operation, array $input): array
    {
        return $this->executeForOrigin($actor, $workspace, $clientId, $key, $projectId, $operation, $input, TaskMutationOrigin::Internal);
    }

    /** @param array<string, mixed> $input
     * @return list<string> */
    private function executeForOrigin(User $actor, Workspace $workspace, string $clientId, string $key, string $projectId, string $operation, array $input, TaskMutationOrigin $origin): array
    {
        TaskOrganizationGate::assertEnabled();
        $command = TaskOrganizationCommand::fromInput($operation, $input);
        // Fail before a receipt is attempted; publication/replay repeats current
        // locked authority inside the receipt transaction, including dual users.
        $this->organization->inspect($actor, $workspace, $projectId, $origin);

        return $this->mutations->run($actor, $workspace, $clientId, 'task_organization.'.$operation, $key,
            ['project_id' => $projectId, 'body' => $input],
            fn (): array => $this->organization->perform($actor, $workspace, $projectId, $command, $origin),
            function (array $ids) use ($actor, $workspace, $projectId, $origin, $command): void {
                $graph = $this->organization->inspect($actor, $workspace, $projectId, $origin, current: true);
                $this->assertResult($graph, $command, $ids);
            });
    }

    /** @param list<string> $ids */
    private function assertResult(TaskGraph $graph, TaskOrganizationCommand $command, array $ids): void
    {
        $available = str_starts_with($command->operation, 'lists.') ? $graph->lists : $graph->tasks;
        foreach ($ids as $id) {
            abort_unless(isset($available[$id]), 404);
        }
        if (str_ends_with($command->operation, '.reorder')) {
            abort_unless($ids === $command->data['ordered_ids'], 404);
        } else {
            abort_unless(count($ids) === 1, 404);
            $expected = $command->data['task_id'] ?? $command->data['list_id'] ?? null;
            abort_if($expected !== null && $ids[0] !== $expected, 404);
        }
    }
}
