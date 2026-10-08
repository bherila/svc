<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\CursorPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** Bounded member access decisions, visible only to workspace managers. */
final class AgentProjectMemberReadService
{
    public function __construct(private readonly ProjectAccess $access) {}

    /** @return array<string, mixed> */
    public function list(User|AgentPrincipal $actor, Workspace $workspace, string $projectId, int $limit, ?string $cursor): array
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
        $project = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $projectId)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))->firstOrFail();
        // Enumerate existing workspace members, including those without a
        // grant. No account search or email address is disclosed here.
        $query = WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('role', 'member')->with('user')->orderBy('id');
        $page = CursorPage::run($workspace, 'project_members|project='.$projectId, $limit, $cursor, function (int $take, ?int $after) use ($query, $workspace, $project): Collection {
            $memberships = $query->when($after !== null, fn (Builder $rows): Builder => $rows->where('id', '>', $after))->limit($take)->get();
            $roles = ClientProjectMembership::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
                ->whereIn('user_id', $memberships->pluck('user_id'))->get()->keyBy('user_id');
            foreach ($memberships as $membership) {
                $membership->setRelation('projectGrant', $roles->get($membership->user_id));
            }

            return $memberships;
        }, function (WorkspaceMembership $membership): array {
            $grant = $membership->getRelation('projectGrant');

            return ['user_id' => $membership->user->public_id, 'name' => $membership->user->name, 'role' => $grant instanceof ClientProjectMembership ? $grant->role->value : 'none'];
        });

        return [...$page, 'project_id' => $project->public_id, 'version' => AgentApiVersion::for($project)];
    }
}
