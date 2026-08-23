<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientProject;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

final class AgentProjectPresenter
{
    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientProject $project): array
    {
        return [
            'id' => $project->public_id,
            'company_id' => $project->clientCompany->public_id,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'is_visible_to_client' => $project->is_visible_to_client,
            'version' => AgentApiVersion::for($project),
            'web_url' => route('workspaces.operations', $workspace).'?project='.$project->public_id,
        ];
    }
}
