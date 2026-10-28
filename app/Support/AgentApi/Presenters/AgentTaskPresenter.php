<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientTask;
use App\Models\Workspace;
use App\Services\Tasks\Planning\TaskPlanningDetailsPresenter;
use App\Services\Tasks\TaskPlanningPresenter;
use App\Support\AgentApi\AgentApiVersion;

final class AgentTaskPresenter
{
    /** @return array{id: string, title: string} */
    public static function identity(ClientTask $task): array
    {
        return ['id' => $task->public_id, 'title' => $task->title];
    }

    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientTask $task, bool $includePlanning = false, bool $canManage = false): array
    {
        $identity = self::identity($task);

        return [
            'id' => $identity['id'],
            'project_id' => $task->project->public_id,
            'title' => $identity['title'],
            'description' => $task->description,
            'status' => $task->status,
            'is_visible_to_client' => $task->is_visible_to_client,
            'completed_at' => $task->completed_at?->toAtomString(),
            'version' => AgentApiVersion::for($task),
            'web_url' => $includePlanning
                ? ($canManage ? route('tasks.show', [$workspace, $task]) : route('clients.tasks', [$workspace, $task->project->clientCompany, 'project' => $task->project->public_id]))
                : route('portal.tasks', [$task->project->clientCompany, 'project' => $task->project->public_id]),
        ] + ($includePlanning ? app(TaskPlanningPresenter::class)->fields($task) + app(TaskPlanningDetailsPresenter::class)->taskFields($task) : []);
    }
}
