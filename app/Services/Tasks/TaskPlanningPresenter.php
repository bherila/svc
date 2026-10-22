<?php

namespace App\Services\Tasks;

use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\Workspace;
use LogicException;

/** Internal planning metadata. Callers must authorize its audience before opting in. */
final class TaskPlanningPresenter
{
    /** Eager-load once for a bounded page, never once per presented task.
     * @return array<string, \Closure>
     */
    public static function relations(Workspace $workspace): array
    {
        return ['assignments' => fn ($query) => $query->where('workspace_id', $workspace->id)->with('user')->orderBy('id')];
    }

    /** @return array{starts_on: ?string, due_on: ?string, assignees: list<array{id: string, name: string}>,completion_advisory?:?string} */
    public function fields(ClientTask $task): array
    {
        if (! $task->relationLoaded('assignments')) {
            throw new LogicException('Load task assignments for the page before presenting internal planning metadata.');
        }

        return [
            'starts_on' => $task->starts_on?->toDateString(),
            'due_on' => $task->due_on?->toDateString(),
            'assignees' => array_values($task->assignments->map(fn (ClientTaskAssignment $assignment): array => [
                'id' => $assignment->user->public_id, 'name' => $assignment->user->name,
            ])->all()),
        ] + app(TaskCompletionAdvisory::class)->fields($task);
    }
}
