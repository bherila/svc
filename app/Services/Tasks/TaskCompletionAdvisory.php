<?php

namespace App\Services\Tasks;

use App\Models\ClientTask;
use App\Models\Workspace;

/** Callers supply already authorized internal records; no child identities/counts are presented. */
final class TaskCompletionAdvisory
{
    private const string PREREQUISITE_MESSAGE = 'This task has unfinished prerequisites. Completing it leaves those prerequisites unchanged.';

    private const string BOTH_MESSAGE = 'This task has unfinished subtasks and prerequisites. Completing it leaves them unchanged.';

    private const string MESSAGE = 'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.';

    public function __construct(private readonly TaskCompletionAdvisoryRepository $repository) {}

    /** Transient relation metadata cannot become dirty task columns or change lifecycle.
     * @param iterable<ClientTask> $tasks */
    public function load(Workspace $workspace, iterable $tasks): void
    {
        $childrenEnabled = config('task_organization.enabled') === true;
        $prerequisitesEnabled = config('task_planning_details.enabled') === true;
        if (! $childrenEnabled && ! $prerequisitesEnabled) {
            return;
        }
        $parents = [];
        foreach ($tasks as $task) {
            if ($task->workspace_id === $workspace->id) {
                $parents[] = $task;
            }
        }
        foreach (array_chunk($parents, TaskCompletionAdvisoryRepository::BATCH_SIZE) as $batch) {
            $ids = array_map(static fn (ClientTask $task): int => $task->id, $batch);
            $unfinished = $childrenEnabled ? array_fill_keys($this->repository->unfinishedParentIds($workspace, $ids), true) : [];
            $prerequisites = $prerequisitesEnabled ? array_fill_keys($this->repository->unfinishedPrerequisiteTaskIds($workspace, $ids), true) : [];
            foreach ($batch as $task) {
                $task->setRelation('completionAdvisory', isset($unfinished[$task->id], $prerequisites[$task->id]) ? self::BOTH_MESSAGE
                    : (isset($unfinished[$task->id]) ? self::MESSAGE : (isset($prerequisites[$task->id]) ? self::PREREQUISITE_MESSAGE : null)))
                    ->makeHidden('completionAdvisory');
            }
        }
    }

    /** Audience authorization remains the presenter's responsibility even on reused models.
     * @return array{completion_advisory?: ?string} */
    public function fields(ClientTask $task): array
    {
        if ((config('task_organization.enabled') !== true && config('task_planning_details.enabled') !== true) || ! $task->relationLoaded('completionAdvisory')) {
            return [];
        }
        $message = $task->getRelation('completionAdvisory');

        return ['completion_advisory' => is_string($message) ? $message : null];
    }
}
