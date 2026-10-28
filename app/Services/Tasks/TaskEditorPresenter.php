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

    /**
     * @param  ?array{data: list<array{id: string, name: string}>, has_more: bool}  $assigneeOptions
     * @return array{id: string, name: string, create_action: string, assignees_action: string, assignee_options?: array{data: list<array{id: string, name: string}>, has_more: bool}}
     */
    public function project(Workspace $workspace, ClientProject $project, ?array $assigneeOptions = null): array
    {
        return ['id' => $project->public_id, 'name' => $project->name,
            'create_action' => route('agent-api.v1.tasks.store', [$workspace, $project->public_id]),
            'assignees_action' => route('tasks.assignees', [$workspace, $project]),
        ] + ($assigneeOptions === null ? [] : ['assignee_options' => $assigneeOptions]);
    }

    /**
     * @param  ?array{data: list<array{id: string, name: string}>, has_more: bool}  $assigneeOptions
     * @return array<string, mixed>
     */
    public function task(Workspace $workspace, ClientTask $task, ?array $assigneeOptions = null): array
    {
        return ['id' => $task->public_id, 'title' => $task->title, 'description' => $task->description,
            'status' => $task->status, 'is_visible_to_client' => $task->is_visible_to_client,
            'completed_at' => $task->completed_at?->toAtomString(), 'version' => AgentApiVersion::for($task),
            'update_action' => route('agent-api.v1.tasks.update', [$workspace, $task->public_id]),
            'assignees_action' => route('tasks.assignees', [$workspace, $task->project]),
        ] + $this->planning->fields($task) + ($assigneeOptions === null ? [] : ['assignee_options' => $assigneeOptions]);
    }
}
