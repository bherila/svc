<?php

namespace App\Services\Tasks\Planning;

use App\Models\AgentPrincipal;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\TaskMutationOrigin;

/** Scoped eager loading remains separate from database-free DTO projection. */
final class TaskPlanningDetailsLoader
{
    public function __construct(private readonly TaskPlanningDetailsReadService $reads) {}

    /** Attach authorized value facts only, in bounded batches. No task columns become dirty.
     * @param iterable<ClientTask> $tasks */
    public function load(User|AgentPrincipal $actor, Workspace $workspace, TaskMutationOrigin $origin, iterable $tasks): void
    {
        if (config('task_planning_details.enabled') !== true) {
            return;
        }
        $rows = is_array($tasks) ? array_values($tasks) : iterator_to_array($tasks, false);
        foreach (array_chunk($rows, TaskPlanningReadRepository::MAX_BATCH) as $batch) {
            $snapshots = $this->reads->summariesForAuthorizedTasks($actor, $workspace, $origin, $batch);
            foreach ($batch as $task) {
                if (isset($snapshots[$task->id])) {
                    $task->setRelation('planningDetails', $snapshots[$task->id])->makeHidden('planningDetails');
                }
            }
        }
    }

}
