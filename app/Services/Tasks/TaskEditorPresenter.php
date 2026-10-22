<?php

namespace App\Services\Tasks;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

/** URLs/capabilities for callers already authorized by the browser manager gate. */
final class TaskEditorPresenter
{
    public function __construct(private readonly TaskPlanningPresenter $planning) {}

    /** @return array{id: string, name: string, create_action: string, assignees_action: string} */
    public function project(Workspace $workspace, ClientProject $project): array
    {
        return ['id' => $project->public_id, 'name' => $project->name,
            'create_action' => route('tasks.store', [$workspace, $project]),
            'assignees_action' => route('tasks.assignees', [$workspace, $project])];
    }

    /** @return array<string, mixed> */
    public function task(Workspace $workspace, ClientTask $task): array
    {
        return ['id' => $task->public_id, 'title' => $task->title, 'description' => $task->description,
            'status' => $task->status, 'is_visible_to_client' => $task->is_visible_to_client,
            'completed_at' => $task->completed_at?->toAtomString(), 'version' => AgentApiVersion::for($task),
            'update_action' => route('tasks.update', [$workspace, $task]),
            'assignees_action' => route('tasks.assignees', [$workspace, $task->project]),
        ] + $this->planning->fields($task);
    }
}
