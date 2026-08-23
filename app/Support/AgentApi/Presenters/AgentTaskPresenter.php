<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientTask;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

final class AgentTaskPresenter
{
    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientTask $task): array
    {
        return [
            'id' => $task->public_id,
            'project_id' => $task->project->public_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'is_visible_to_client' => $task->is_visible_to_client,
            'completed_at' => $task->completed_at?->toAtomString(),
            'version' => AgentApiVersion::for($task),
            'web_url' => route('workspaces.operations', $workspace).'?task='.$task->public_id,
        ];
    }
}
