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

    /** @return array{id: string, title: string, status: string, is_visible_to_client: bool} */
    public static function summary(ClientTask $task): array
    {
        return self::identity($task) + ['status' => $task->status, 'is_visible_to_client' => $task->is_visible_to_client];
    }

    /** @return array{id: string, title: string, description: ?string, status: string, is_visible_to_client: bool, completed_at: ?string, version: string} */
    public static function details(ClientTask $task): array
    {
        $summary = self::summary($task);

        return ['id' => $summary['id'], 'title' => $summary['title'], 'description' => $task->description,
            'status' => $summary['status'], 'is_visible_to_client' => $summary['is_visible_to_client'],
            'completed_at' => self::completedAt($task), 'version' => AgentApiVersion::for($task)];
    }

    /** The native list displays a calendar day; the API retains the instant. */
    public static function completedAt(ClientTask $task, bool $dateOnly = false): ?string
    {
        return $dateOnly ? $task->completed_at?->toDateString() : $task->completed_at?->toAtomString();
    }

    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientTask $task, bool $includePlanning = false, bool $canManage = false): array
    {
        $details = self::details($task);

        return [
            'id' => $details['id'],
            'project_id' => $task->project->public_id,
            'title' => $details['title'],
            'description' => $details['description'],
            'status' => $details['status'],
            'is_visible_to_client' => $details['is_visible_to_client'],
            'completed_at' => $details['completed_at'],
            'version' => $details['version'],
            'web_url' => $includePlanning
                ? ($canManage ? route('tasks.show', [$workspace, $task]) : route('clients.tasks', [$workspace, $task->project->clientCompany, 'project' => $task->project->public_id]))
                : route('portal.tasks', [$task->project->clientCompany, 'project' => $task->project->public_id]),
        ] + ($includePlanning ? app(TaskPlanningPresenter::class)->fields($task) + app(TaskPlanningDetailsPresenter::class)->taskFields($task) : []);
    }
}
