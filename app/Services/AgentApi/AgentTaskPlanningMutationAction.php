<?php

namespace App\Services\AgentApi;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Planning\TaskPlanningCommand;
use App\Services\Tasks\Planning\TaskPlanningDetailsGate;
use App\Services\Tasks\Planning\TaskPlanningMutationAction;
use App\Services\Tasks\TaskMutationOrigin;

/** Unadvertised until the complete native/transport slice is integrated. */
final class AgentTaskPlanningMutationAction
{
    public function __construct(private readonly AgentMutationExecutor $receipts, private readonly TaskPlanningMutationAction $planning) {}

    /** @param array<string,mixed> $input */
    public function execute(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $targetId, array $input): string
    {
        return $this->executeForOrigin($actor, $workspace, $clientId, $key, $operation, $targetId, $input, TaskMutationOrigin::Agent);
    }

    /** Trusted native/CLI adapter only; request fields never select this origin.
     * @param  array<string,mixed>  $input
     */
    public function executeInternal(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $targetId, array $input): string
    {
        return $this->executeForOrigin($actor, $workspace, $clientId, $key, $operation, $targetId, $input, TaskMutationOrigin::Internal);
    }

    /** @param array<string,mixed> $input */
    private function executeForOrigin(User $actor, Workspace $workspace, string $clientId, string $key, string $operation, ?string $targetId, array $input, TaskMutationOrigin $origin): string
    {
        TaskPlanningDetailsGate::assertEnabled();
        $command = TaskPlanningCommand::fromInput($operation, $input);
        $ids = $this->receipts->run($actor, $workspace, $clientId, 'task_planning.'.$operation, $key,
            ['target_id' => $targetId, 'body' => $input],
            fn (): array => [$this->planning->perform($actor, $workspace, $targetId, $command, $origin)],
            function (array $ids) use ($actor, $workspace, $operation, $origin): void {
                abort_unless(count($ids) === 1, 404);
                $this->planning->authorizeReplay($actor, $workspace, $operation, $ids[0], $origin);
            });

        return $ids[0];
    }
}
