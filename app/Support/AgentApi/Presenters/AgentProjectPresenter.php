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
            'company_name' => $project->clientCompany->name,
            'name' => $project->name,
            'description' => $project->description,
            // The canonical `host/owner/name` this project is worked in, or
            // null when nobody has said. A client resolves its own checkout by
            // normalizing its remote the same way and comparing; see #243.
            'repository' => $project->repository,
            'status' => $project->status,
            'is_visible_to_client' => $project->is_visible_to_client,
            'version' => AgentApiVersion::for($project),
            'web_url' => route('workspaces.operations', $workspace).'?project='.$project->public_id,
        ];
    }
}
