<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientProject;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

final class AgentProjectPresenter
{
    /**
     * Marks a key to drop rather than send.
     *
     * A sentinel because `null` is already a meaning here - "nobody has mapped
     * this project" - and `array_filter` on emptiness would also eat a false
     * `is_visible_to_client`.
     */
    private const WITHHELD = "\0withheld";

    /**
     * @param  bool  $includeRepository  False for a portal client. The mapping
     *                                   names an internal host, organization and
     *                                   repository, it is set on the manager-only
     *                                   settings screen, and a client cannot log
     *                                   time - so there is nothing it buys them
     *                                   and a private name to lose.
     * @return array<string, mixed>
     */
    public function present(Workspace $workspace, ClientProject $project, bool $includeRepository): array
    {
        return array_filter([
            'id' => $project->public_id,
            'company_id' => $project->clientCompany->public_id,
            'company_name' => $project->clientCompany->name,
            'name' => $project->name,
            'description' => $project->description,
            // The canonical `host/owner/name` this project is worked in, or
            // null when nobody has said. An internal caller resolves its own
            // checkout by normalizing its remote the same way and comparing;
            // see #243. Absent entirely rather than null for a client viewer,
            // so "withheld" cannot be read as "unmapped".
            'repository' => $includeRepository ? $project->repository : self::WITHHELD,
            'status' => $project->status,
            'is_visible_to_client' => $project->is_visible_to_client,
            'version' => AgentApiVersion::for($project),
            'web_url' => route('workspaces.operations', $workspace).'?project='.$project->public_id,
        ], static fn (mixed $value): bool => $value !== self::WITHHELD);
    }
}
