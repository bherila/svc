<?php

namespace App\Services\Tasks;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;

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
        return AgentProjectPresenter::identity($project) + [
            'create_action' => route('tasks.store', [$workspace, $project]),
            'assignees_action' => route('tasks.assignees', [$workspace, $project]),
        ] + ($assigneeOptions === null ? [] : ['assignee_options' => $assigneeOptions]);
    }

    /**
     * @param  ?array{data: list<array{id: string, name: string}>, has_more: bool}  $assigneeOptions
     * @return array<string, mixed>
     */
    public function task(Workspace $workspace, ClientTask $task, ?array $assigneeOptions = null): array
    {
        return AgentTaskPresenter::details($task) + [
            'update_action' => route('tasks.update', [$workspace, $task]),
            'assignees_action' => route('tasks.assignees', [$workspace, $task->project]),
        ] + $this->planning->fields($task) + ($assigneeOptions === null ? [] : ['assignee_options' => $assigneeOptions]);
    }
}
