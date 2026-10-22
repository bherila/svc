<?php

namespace App\Services\Tasks\Planning;

/** Projection only: audience and bounded eager reads precede this boundary. */
final class TaskPlanningDetailsPresenter
{
    /** @return array{labels:list<array{id:string,name:string,is_archived:bool,version:string}>,dependency_summary:array{blocked:bool,incomplete_prerequisite_count:int,dependent_count:int}} */
    public function fields(TaskPlanningDetailsSnapshot $snapshot): array
    {
        return ['labels' => $snapshot->labels, 'dependency_summary' => $snapshot->dependencySummary];
    }

    /** @return array{data:array<string,mixed>} */
    public function response(TaskPlanningDetailsSnapshot $snapshot): array
    {
        return ['data' => ['task_id' => $snapshot->taskId, 'version' => $snapshot->version] + $this->fields($snapshot)];
    }
}
