<?php

namespace App\Services\SharedProjects;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Support\Access\StaffRole;
use App\Support\SharedProjects\SharedViewer;
use App\Support\SharedProjects\ShareSide;

/**
 * The one place that decides whether a workspace may stand at a shared
 * project: the host workspace, or a workspace with an active participation.
 * Every task, comment and participant read or write on a shared project
 * resolves through {@see self::resolve()} and nothing else, so there is exactly
 * one answer to "who may be here" and exactly one way for it to be wrong.
 *
 * It fails closed. A missing or non-member viewer, a project that is not in
 * the host, a participation that was never accepted, was revoked or was left,
 * and a project id that exists in some other workspace all return null, which
 * every caller answers as 404 - the same as a project that does not exist.
 *
 * The project is looked up by its public id with no workspace filter on
 * purpose: the viewer's workspace is not the project's workspace, so a
 * workspace-scoped lookup cannot find it. That is safe only because the lookup
 * is never returned directly. What is returned is a {@see SharedProjectContext},
 * built only after the host-or-participation test passes.
 */
final class SharedProjectAccess
{
    public function __construct(private readonly ProjectAccess $projects) {}

    public function resolve(User|AgentPrincipal $actor, Workspace $viewerWorkspace, string $projectPublicId): ?SharedProjectContext
    {
        $role = StaffRole::tryFrom((string) $this->projects->workspaceRole($actor, $viewerWorkspace));
        if ($role === null) {
            return null;
        }

        $project = ClientProject::query()->where('public_id', $projectPublicId)->first();
        if ($project === null) {
            return null;
        }

        $host = Workspace::query()->whereKey($project->workspace_id)->first();
        if ($host === null) {
            return null;
        }

        if ((int) $project->workspace_id === (int) $viewerWorkspace->id) {
            $project->setRelation('workspace', $host);

            return new SharedProjectContext(
                $project,
                $host,
                $viewerWorkspace,
                new SharedViewer(ShareSide::Host, $role, $this->projects->projectRole($actor, $project)),
                null,
            );
        }

        $participation = $this->activeParticipation($project, $viewerWorkspace);
        if ($participation === null) {
            return null;
        }

        return new SharedProjectContext($project, $host, $viewerWorkspace, new SharedViewer(ShareSide::Participant, $role), $participation);
    }

    /** The viewer workspace's active participation in this project, if it has one. */
    public function activeParticipation(ClientProject $project, Workspace $workspace): ?ClientProjectParticipation
    {
        $participation = ClientProjectParticipation::query()
            ->where('host_workspace_id', $project->workspace_id)
            ->where('client_project_id', $project->id)
            ->where('participant_workspace_id', $workspace->id)
            ->whereNotNull('active_marker')
            ->first();

        return $participation?->state()->grantsAccess() === true ? $participation : null;
    }
}
