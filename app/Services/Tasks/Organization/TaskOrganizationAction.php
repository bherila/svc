<?php

namespace App\Services\Tasks\Organization;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Billing\BillingMaintenance;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskCreateData;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\WorkspaceClock;
use DomainException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Graph writes never alter task lifecycle, billing claims, ownership or grants. */
final class TaskOrganizationAction
{
    public function __construct(private readonly TaskOrganizationRepository $organization, private readonly WorkspaceTaskRepository $tasks, private readonly TaskAccess $access, private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function inspect(User $actor, Workspace $workspace, string $projectId, TaskMutationOrigin $origin = TaskMutationOrigin::Internal, bool $current = false): TaskGraph
    {
        TaskOrganizationGate::assertEnabled();
        if ($current) {
            return DB::transaction(fn (): TaskGraph => $this->graph($this->lock($actor, $workspace, $projectId, $origin)->records));
        }
        $project = $this->tasks->project($workspace, $projectId);
        abort_unless($this->access->canManage($actor, $workspace, $project), 403);
        abort_if($origin === TaskMutationOrigin::Agent && app(AgentAccess::class)->isWorkspaceClient($actor, $workspace), 403);

        return $this->graph($this->organization->load($workspace, $project, false));
    }

    public function snapshot(User $actor, Workspace $workspace, string $projectId, ?TaskContainer $container = null, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): TaskOrganizationSnapshot
    {
        TaskOrganizationGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $projectId, $container, $origin): TaskOrganizationSnapshot {
            $records = $this->lock($actor, $workspace, $projectId, $origin)->records;
            try {
                return TaskOrganizationSnapshot::capture($records, $this->graph($records), $container);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['organization' => $exception->getMessage()]);
            }
        });
    }

    /** @return list<string> public identities affected, for the common receipt/audit adapter */
    public function perform(User $actor, Workspace $workspace, string $projectId, TaskOrganizationCommand $command, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): array
    {
        TaskOrganizationGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $projectId, $command, $origin): array {
            $creation = $command->operation === 'tasks.create' ? TaskCreateData::fromValidated($command->data['task']) : null;
            $context = $this->lock($actor, $workspace, $projectId, $origin, true, $creation === null ? [] : $creation->assigneeIds);
            $records = $context->records;
            $graph = $this->graph($records);
            $data = $command->data;
            if (isset($data['expected_graph_version'])) {
                abort_unless(hash_equals($graph->graphVersion(), $data['expected_graph_version']), 409, 'The organization changed. Read it again before saving.');
            }
            try {
                if ($command->operation === 'lists.create') {
                    if (count($graph->lists) >= TaskGraph::MAX_LISTS) {
                        throw new DomainException('Organization operations support at most 100 lists per project.');
                    }

                    return [$this->organization->createList($workspace, $records, $command->name(), (bool) ($data['is_visible_to_client'] ?? false))->public_id];
                }
                if (in_array($command->operation, ['lists.update', 'lists.archive', 'lists.restore'], true)) {
                    $list = $records->lists->firstWhere('public_id', $data['list_id']);
                    abort_unless($list !== null, 404);
                    abort_unless(AgentApiVersion::matches($list, $data['expected_version']), 409, 'The list changed. Read it again before saving.');
                    $attributes = match ($command->operation) {
                        'lists.update' => Arr::only($data, ['name', 'is_visible_to_client']),
                        'lists.archive' => ['deleted_at' => $this->clock->now($workspace)],
                        'lists.restore' => ['deleted_at' => null],
                    };
                    abort_if($command->operation === 'lists.update' && $list->deleted_at !== null, 409, 'Restore this list before editing it.');
                    $this->organization->updateList($records, $list, $attributes);

                    return [$list->public_id];
                }
                if ($command->operation === 'lists.reorder') {
                    $live = array_filter($graph->lists, static fn (array $list): bool => ! $list['deleted']);
                    $expected = array_keys($live);
                    $given = $command->orderedIds();
                    sort($expected);
                    sort($given);
                    abort_unless(hash_equals($graph->graphVersion(), $data['expected_container_version']), 409, 'The list container changed. Read it again before saving.');
                    if ($given !== $expected) {
                        throw new DomainException('Supply the complete current sibling order.');
                    }
                    $this->organization->reorderLists($records, $command->orderedIds());

                    return $command->orderedIds();
                }
                $container = new TaskContainer($data['parent_id'], $data['list_id']);
                if ($creation !== null) {
                    if (count($graph->tasks) >= TaskGraph::MAX_TASKS) {
                        throw new DomainException('Organization operations support at most 1000 tasks per project.');
                    }
                    // Validate placement before creating either a task or assignments.
                    $graph->assertPlacement($container);
                    $task = $this->tasks->create($workspace, $records->project, $creation, $context->access);
                    $records->tasks->push($task);
                    $next = $this->organization->graph($records)->move($task->public_id, $container);
                    $next = $next->reorder($container, $next->siblings($container));
                    $this->organization->apply($workspace, $records, $next, $task->public_id);

                    return [$task->public_id];
                }
                if ($command->operation === 'tasks.reorder') {
                    abort_unless(hash_equals($graph->containerVersion($container), $data['expected_container_version']), 409, 'The task container changed. Read it again before saving.');
                    $next = $graph->reorder($container, $command->orderedIds());
                    $this->organization->apply($workspace, $records, $next);

                    return $command->orderedIds();
                }
                $task = $records->tasks->firstWhere('public_id', $data['task_id']);
                abort_unless($task !== null, 404);
                abort_unless(AgentApiVersion::matches($task, $data['expected_version']), 409, 'The task changed. Read it again before saving.');
                $before = new TaskContainer($graph->tasks[$task->public_id]['parent'], $graph->tasks[$task->public_id]['list']);
                $next = $graph->move($task->public_id, $container);
                foreach ([$before, $container] as $siblings) {
                    $next = $next->reorder($siblings, $next->siblings($siblings));
                }
                $this->organization->apply($workspace, $records, $next, $task->public_id);

                return [$task->public_id];
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['organization' => $exception->getMessage()]);
            }
        });
    }

    /** @param list<string> $candidateIds */
    private function lock(User $actor, Workspace $workspace, string $projectId, TaskMutationOrigin $origin, bool $initialize = false, array $candidateIds = []): TaskOrganizationContext
    {
        BillingMaintenance::share($workspace->id);
        $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
        $project = $this->tasks->project($lockedWorkspace, $projectId);
        $records = $this->organization->load($lockedWorkspace, $project, true, $initialize);
        // Direct locking membership reads see current committed authority even
        // inside an older caller snapshot; graph/task locks always precede them.
        $access = $this->tasks->access($lockedWorkspace, $project, $actor, $candidateIds);
        abort_if($origin === TaskMutationOrigin::Agent && $access->portalMember, 403);
        abort_unless($access->canManage($actor->id), 403);

        return new TaskOrganizationContext($records, $access);
    }

    private function graph(TaskOrganizationRecords $records): TaskGraph
    {
        try {
            $graph = $this->organization->graph($records);
            $graph->assertValid();

            return $graph;
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['organization' => $exception->getMessage()]);
        }
    }
}
