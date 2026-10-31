<?php

namespace App\Services\Tasks\Planning;

use App\Http\Requests\TaskPlanningInput;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskMutationOrigin;

/** One read dispatch boundary for native, REST and trusted CLI adapters. */
final class TaskPlanningTransport
{
    public function __construct(private readonly TaskPlanningDetailsReadService $reads, private readonly TaskPlanningDetailsPresenter $presenter) {}

    /** @param array<string,mixed> $query
     * @return array<string,mixed> */
    public function read(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $operation, ?string $target, array $query = []): array
    {
        TaskPlanningDetailsGate::assertEnabled();
        $input = TaskPlanningInput::query($operation, $query);
        $id = $target ?? '';
        abort_if(TaskPlanningInput::targetName($operation) !== null && $id === '', 404);
        $q = $input['q'] ?? '';
        $limit = $input['limit'] ?? 25;
        $cursor = $input['cursor'] ?? null;

        $response = match ($operation) {
            'get' => $this->presenter->response($this->reads->get($actor, $workspace, $origin, $id)),
            'labels.list' => $this->reads->labels($actor, $workspace, $origin, $input['status'] ?? 'active', $q, $limit, $cursor),
            'labels.get' => $this->reads->label($actor, $workspace, $origin, $id),
            'labels.options' => $this->reads->labelOptions($actor, $workspace, $origin, $id, $q, $limit, $cursor),
            'dependencies.get' => $this->reads->dependencies($actor, $workspace, $origin, $id, $limit, $cursor),
            'dependencies.options' => $this->reads->dependencyOptions($actor, $workspace, $origin, $id, $q, $limit, $cursor),
            'contacts.get' => $this->reads->contacts($actor, $workspace, $origin, $id),
            'contacts.options' => $this->reads->contactOptions($actor, $workspace, $origin, $id, $q, $limit, $cursor),
            default => abort(404),
        };
        if (in_array($operation, ['labels.options', 'dependencies.get', 'dependencies.options', 'contacts.options'], true)) {
            $parameters = ['workspace' => $workspace, TaskPlanningInput::targetName($operation) => $id];
            $next = $response['meta']['next_cursor'];
            $response['next_href'] = $next === null ? null : route('agent-api.v1.task-planning.'.$operation, $parameters + array_replace($input, ['cursor' => $next]));
            $response['previous_href'] = null;
        }

        return $response;
    }
}
