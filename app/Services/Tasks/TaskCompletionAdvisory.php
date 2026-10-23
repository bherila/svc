<?php

namespace App\Services\Tasks;

use App\Models\ClientTask;
use App\Models\Workspace;

/** Callers supply already authorized internal records; no child identities/counts are presented. */
final class TaskCompletionAdvisory
{
    private const string MESSAGE = 'This task has unfinished subtasks. Completing it leaves those subtasks unchanged.';

    public function __construct(private readonly TaskCompletionAdvisoryRepository $repository) {}

    /** Transient relation metadata cannot become dirty task columns or change lifecycle.
     * @param iterable<ClientTask> $tasks */
    public function load(Workspace $workspace, iterable $tasks): void
    {
        if (config('task_organization.enabled') !== true) {
            return;
        }
        $parents = [];
        foreach ($tasks as $task) {
            if ($task->workspace_id === $workspace->id) {
                $parents[] = $task;
            }
        }
        foreach (array_chunk($parents, TaskCompletionAdvisoryRepository::BATCH_SIZE) as $batch) {
            $unfinished = array_fill_keys($this->repository->unfinishedParentIds($workspace, array_map(static fn (ClientTask $task): int => $task->id, $batch)), true);
            foreach ($batch as $task) {
                $task->setRelation('completionAdvisory', isset($unfinished[$task->id]) ? self::MESSAGE : null)
                    ->makeHidden('completionAdvisory');
            }
        }
    }

    /** Audience authorization remains the presenter's responsibility even on reused models.
     * @return array{completion_advisory?: ?string} */
    public function fields(ClientTask $task): array
    {
        if (config('task_organization.enabled') !== true || ! $task->relationLoaded('completionAdvisory')) {
            return [];
        }
        $message = $task->getRelation('completionAdvisory');

        return ['completion_advisory' => is_string($message) ? $message : null];
    }
}
