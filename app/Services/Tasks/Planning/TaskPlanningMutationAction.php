<?php

namespace App\Services\Tasks\Planning;

use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingMaintenance;
use App\Services\Tasks\Organization\TaskOrganizationRepository;
use App\Services\Tasks\TaskGraphRevisionRepository;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\WorkspaceTaskRepository;
use App\Support\AgentApi\AgentApiVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Planning writes preserve lifecycle, billing, ownership, history and access grants. */
final class TaskPlanningMutationAction
{
    public function __construct(private readonly TaskPlanningRepository $planning, private readonly WorkspaceTaskRepository $tasks,
        private readonly TaskGraphRevisionRepository $revisions, private readonly TaskOrganizationRepository $organization) {}

    public function perform(User $actor, Workspace $workspace, ?string $targetId, TaskPlanningCommand $command, TaskMutationOrigin $origin = TaskMutationOrigin::Internal): string
    {
        TaskPlanningDetailsGate::assertEnabled();

        return DB::transaction(function () use ($actor, $workspace, $targetId, $command, $origin): string {
            BillingMaintenance::share($workspace->id);
            $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
            if (in_array($command->operation, ['labels.create', 'labels.update', 'labels.archive', 'labels.restore'], true)) {
                $label = $command->operation === 'labels.create' ? null : $this->planning->label($lockedWorkspace, $targetId ?? abort(404));

                return $this->planning->saveLabel($lockedWorkspace, $label, $command, function () use ($lockedWorkspace, $actor, $origin, $label, $command): void {
                    $this->planning->authorizeManager($lockedWorkspace, $actor, $origin);
                    if ($label !== null) {
                        abort_unless(AgentApiVersion::matches($label, $command->version()), 409, 'The label changed. Read it again before saving.');
                    }
                })->public_id;
            }
            $candidate = $this->tasks->find($lockedWorkspace, $targetId ?? abort(404));
            $project = $candidate->project;
            $state = $this->revisions->lockForMutation($lockedWorkspace, $project);
            try {
                if ($command->operation === 'dependencies.replace') {
                    $records = $this->organization->load($lockedWorkspace, $project, true);
                    $source = $records->tasks->firstWhere('public_id', $targetId);
                    abort_unless($source !== null, 404);
                    $source->setRelation('project', $project);
                    $dependencies = $this->planning->dependencyGraph($lockedWorkspace, $records,
                        fn () => $this->authorizeTask($actor, $lockedWorkspace, $source, $origin));
                    $graph = $this->organization->graph($records);
                    $graph->assertValid();
                    abort_unless(AgentApiVersion::matches($source, $command->version())
                        && hash_equals($graph->graphVersion(), $command->version('expected_graph_version')), 409, 'The task graph changed. Read it again before saving.');
                    $change = $dependencies->changePrerequisites($source->public_id, $command->ids());
                    $this->planning->replaceDependencies($lockedWorkspace, $records, $source, $change);
                    foreach ($change->taskIdsToAdvance as $id) {
                        $this->tasks->save($records->tasks->firstWhere('public_id', $id) ?? abort(404), [], true);
                    }
                } else {
                    $source = $this->tasks->find($lockedWorkspace, $targetId, true);
                    $authorize = function () use ($actor, $lockedWorkspace, $source, $origin, $command): void {
                        if ($command->operation === 'contacts.replace') {
                            $this->planning->authorizeManager($lockedWorkspace, $actor, $origin);
                        } else {
                            $this->authorizeTask($actor, $lockedWorkspace, $source, $origin);
                        }
                        abort_unless(AgentApiVersion::matches($source, $command->version()), 409, 'The task changed. Read it again before saving.');
                    };
                    match ($command->operation) {
                        'labels.replace' => $this->planning->replaceLabels($lockedWorkspace, $source, $command->ids(), $authorize),
                        'contacts.replace' => $this->planning->replaceContacts($lockedWorkspace, $project, $source, $command->ids(), $authorize),
                        default => abort(404),
                    };
                    $this->tasks->save($source, [], true);
                }
                $this->revisions->advance($lockedWorkspace, $project, $state);

                return $source->public_id;
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['planning' => $exception->getMessage()]);
            }
        });
    }

    /** Current authorization only: receipt replay never initializes or advances graph state. */
    public function authorizeReplay(User $actor, Workspace $workspace, string $operation, string $id, TaskMutationOrigin $origin): void
    {
        TaskPlanningDetailsGate::assertEnabled();
        DB::transaction(function () use ($actor, $workspace, $operation, $id, $origin): void {
            BillingMaintenance::share($workspace->id);
            $lockedWorkspace = $this->tasks->lockWorkspace($workspace);
            if (in_array($operation, ['labels.create', 'labels.update', 'labels.archive', 'labels.restore'], true)) {
                $this->planning->label($lockedWorkspace, $id);
                $this->planning->authorizeManager($lockedWorkspace, $actor, $origin);
            } else {
                $task = $this->tasks->find($lockedWorkspace, $id, true);
                if ($operation === 'contacts.replace') {
                    $this->planning->authorizeManager($lockedWorkspace, $actor, $origin);
                } else {
                    $this->authorizeTask($actor, $lockedWorkspace, $task, $origin);
                }
            }
        });
    }

    private function authorizeTask(User $actor, Workspace $workspace, ClientTask $task, TaskMutationOrigin $origin): void
    {
        $access = $this->tasks->access($workspace, $task->project, $actor, []);
        abort_if($origin === TaskMutationOrigin::Agent && $access->portalMember, 403);
        abort_unless($access->canManage($actor->id), 403);
    }
}
