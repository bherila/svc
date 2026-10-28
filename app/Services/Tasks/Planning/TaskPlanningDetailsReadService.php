<?php

namespace App\Services\Tasks\Planning;

use App\Models\AgentPrincipal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Tasks\Organization\TaskOrganizationRepository;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskReadScope;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal-only planning readers; adapters cannot select a broader task or contact policy. */
final class TaskPlanningDetailsReadService
{
    public function __construct(private readonly TaskReadScope $scope, private readonly TaskAccess $access,
        private readonly AgentAccess $audience, private readonly TaskPlanningReadRepository $repository,
        private readonly WorkspaceTaskRepository $tasks, private readonly TaskOrganizationRepository $organization) {}

    public function get(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $taskId): TaskPlanningDetailsSnapshot
    {
        $this->authorize($actor, $workspace, $origin);

        return DB::transaction(function () use ($actor, $workspace, $origin, $taskId): TaskPlanningDetailsSnapshot {
            $task = $this->task($actor, $workspace, $origin, $taskId);

            return $this->repository->summaries($workspace, [$task])[$task->id];
        });
    }

    /** Ordinary task output omits planning entirely while disabled or for Portal/dual Agent identities.
     * @param iterable<ClientTask> $tasks
     * @return array<int,TaskPlanningDetailsSnapshot> */
    public function summariesForAuthorizedTasks(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, iterable $tasks): array
    {
        if (config('task_planning_details.enabled') !== true
            || ($origin === TaskMutationOrigin::Agent && $this->audience->isWorkspaceClient($actor, $workspace))) {
            return [];
        }
        $this->authorize($actor, $workspace, $origin);
        $ids = [];
        foreach ($tasks as $task) {
            $ids[] = $task->id;
            if (count($ids) > TaskPlanningReadRepository::MAX_BATCH) {
                throw new \LogicException('Task planning batches support at most 100 authorized tasks.');
            }
        }
        if ($ids === []) {
            return [];
        }
        $authorized = $this->scope->query($actor, $workspace, $origin)->whereIn('client_tasks.id', $ids)
            ->get(['client_tasks.id', 'client_tasks.workspace_id', 'client_tasks.client_project_id', 'client_tasks.public_id', 'client_tasks.lock_version']);

        return $this->repository->summaries($workspace, array_values($authorized->all()));
    }

    /** @return array<string,mixed> */
    public function labels(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $status = 'active', string $q = '', int $limit = 25, ?string $cursor = null): array
    {
        $this->authorize($actor, $workspace, $origin, manager: true);
        $this->page($limit, $cursor, $q);
        Validator::make(['status' => $status], ['status' => ['required', 'in:active,archived,all']])->validate();
        $search = mb_trim($q, encoding: 'UTF-8');

        return $this->repository->labels($workspace, $this->key('labels.list', $actor, $origin, $status.'|'.$search), $status, $search, $limit, $cursor);
    }

    /** @return array<string,mixed> */
    public function label(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $labelId): array
    {
        $this->authorize($actor, $workspace, $origin, manager: true);

        return ['data' => $this->repository->label($workspace, $labelId)];
    }

    /** @return array<string,mixed> */
    public function labelOptions(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $projectId, string $q = '', int $limit = 25, ?string $cursor = null): array
    {
        $this->authorize($actor, $workspace, $origin);
        $project = $this->tasks->project($workspace, $projectId);
        abort_unless($this->access->canManage($actor, $workspace, $project), 403);
        $this->page($limit, $cursor, $q);
        $search = mb_trim($q, encoding: 'UTF-8');

        return $this->repository->labels($workspace, $this->key('labels.options', $actor, $origin, $projectId.'|'.$search), 'active', $search, $limit, $cursor);
    }

    /** @return array<string,mixed> */
    public function contacts(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $taskId): array
    {
        $this->authorize($actor, $workspace, $origin, manager: true);
        $task = $this->task($actor, $workspace, $origin, $taskId);
        $this->repository->assertOrganization($workspace, [$task]);

        return ['data' => ['task_id' => $task->public_id, 'version' => AgentApiVersion::for($task), 'contacts' => $this->repository->contacts($workspace, $task, $task->project)]];
    }

    /** @return array<string,mixed> */
    public function contactOptions(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $taskId, string $q = '', int $limit = 25, ?string $cursor = null): array
    {
        $this->authorize($actor, $workspace, $origin, manager: true);
        $task = $this->task($actor, $workspace, $origin, $taskId);
        $this->repository->assertOrganization($workspace, [$task]);
        $this->page($limit, $cursor, $q);
        $search = mb_trim($q, encoding: 'UTF-8');

        return $this->repository->contactOptions($workspace, $task->project, $this->key('contacts.options', $actor, $origin, $taskId.'|'.$search), $search, $limit, $cursor);
    }

    /** @return array<string,mixed> */
    public function dependencies(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $taskId, int $limit = 25, ?string $cursor = null): array
    {
        $this->authorize($actor, $workspace, $origin);
        $this->page($limit, $cursor);

        return DB::transaction(function () use ($actor, $workspace, $origin, $taskId, $limit, $cursor): array {
            $task = $this->task($actor, $workspace, $origin, $taskId);
            [$graph, $graphVersion] = $this->graph($workspace, $task);
            $query = $this->scope->query($actor, $workspace, $origin)->where('client_tasks.client_project_id', $task->client_project_id);
            $canManage = $this->access->canManage($actor, $workspace, $task->project);
            $data = ['task_id' => $task->public_id, 'version' => AgentApiVersion::for($task), 'graph_version' => $graphVersion,
                'prerequisites' => $this->repository->prerequisites($workspace, $query, $graph->prerequisites($taskId), $canManage), 'dependents' => []];
            $budget = AgentPageBudget::dataBytes() - strlen(json_encode($data, JSON_THROW_ON_ERROR)) + 2;
            abort_if($budget < 1, 422, 'Planning details exceed the configured response budget.');
            $page = $this->repository->references($workspace, $query, $graph->dependents($taskId),
                $this->key('dependencies.get', $actor, $origin, $taskId.'|incoming'), '', $limit, $cursor, $canManage, $budget);
            $data['dependents'] = $page['data'];
            abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > AgentPageBudget::dataBytes(), 422, 'Planning details exceed the configured response budget.');

            return ['data' => $data, 'meta' => $page['meta']];
        });
    }

    /** @return array<string,mixed> */
    public function dependencyOptions(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $taskId, string $q = '', int $limit = 25, ?string $cursor = null): array
    {
        $this->authorize($actor, $workspace, $origin);
        $this->page($limit, $cursor, $q);

        return DB::transaction(function () use ($actor, $workspace, $origin, $taskId, $q, $limit, $cursor): array {
            $task = $this->task($actor, $workspace, $origin, $taskId);
            abort_unless($this->access->canManage($actor, $workspace, $task->project), 403);
            [$graph, , $allTaskIds] = $this->graph($workspace, $task);
            $reverse = [];
            foreach ($graph->edges() as $edge) {
                $reverse[$edge->prerequisiteId][] = $edge->taskId;
            }
            $excluded = [$taskId => true];
            $pending = [$taskId];
            for ($index = 0; $index < count($pending); $index++) {
                foreach ($reverse[$pending[$index]] ?? [] as $id) {
                    if (! isset($excluded[$id])) {
                        $excluded[$id] = true;
                        $pending[] = $id;
                    }
                }
            }
            // A candidate may not reach the source, regardless of its current outgoing set size.
            $ids = array_values(array_diff($allTaskIds, array_keys($excluded)));
            $search = mb_trim($q, encoding: 'UTF-8');

            return $this->repository->references($workspace, $this->scope->query($actor, $workspace, $origin)->where('client_tasks.client_project_id', $task->client_project_id),
                $ids, $this->key('dependencies.options', $actor, $origin, $taskId.'|'.$search), $search, $limit, $cursor, true);
        });
    }

    private function authorize(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, bool $manager = false): void
    {
        TaskPlanningDetailsGate::assertEnabled();
        abort_unless($this->audience->canViewWorkspace($actor, $workspace), 403);
        abort_if($origin === TaskMutationOrigin::Agent && $this->audience->isWorkspaceClient($actor, $workspace), 403);
        if ($manager) {
            abort_unless($this->audience->isWorkspaceManager($actor, $workspace), 403);
        }
    }

    private function task(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, string $id): ClientTask
    {
        return $this->scope->query($actor, $workspace, $origin)->where('client_tasks.public_id', $id)->with('project.clientCompany')->firstOrFail();
    }

    /** @return array{TaskDependencyGraph,string,list<string>} */
    private function graph(Workspace $workspace, ClientTask $task): array
    {
        try {
            $records = $this->organization->load($workspace, $task->project, false);
            $organization = $this->organization->graph($records);
            $organization->assertValid();

            return [$this->repository->dependencyGraph($workspace, $records), $organization->graphVersion(), array_values($records->tasks->pluck('public_id')->all())];
        } catch (DomainException $exception) {
            $message = str_contains($exception->getMessage(), 'supports at most') || str_contains($exception->getMessage(), 'support at most')
                ? $exception->getMessage() : 'The task organization is invalid.';
            throw ValidationException::withMessages(['planning' => $message]);
        }
    }

    private function page(int $limit, ?string $cursor, string $q = ''): void
    {
        Validator::make(compact('limit', 'cursor', 'q'), ['limit' => ['required', 'integer', 'min:1', 'max:25'],
            'cursor' => ['nullable', 'string', 'max:2048'], 'q' => ['string', 'max:100']])->validate();
    }

    private function key(string $operation, User|AgentPrincipal $actor, TaskMutationOrigin $origin, string $filter): string
    {
        return 'task-planning|'.$operation.'|actor='.$actor->public_id.'|origin='.$origin->name.'|'.$filter;
    }
}
