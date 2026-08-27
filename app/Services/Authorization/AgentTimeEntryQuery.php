<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

final class AgentTimeEntryQuery
{
    public function __construct(private readonly AgentAccess $access) {}

    /** @return Builder<ClientTimeEntry> */
    public function visibleTo(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientTimeEntry::query()->where('workspace_id', $workspace->id);
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where(function (Builder $entries) use ($user): void {
            $entries
                ->whereHas('project.members', fn (Builder $members) => $members
                    ->whereKey($user->id)
                    ->whereIn('client_project_memberships.role', ['owner', 'manager']))
                ->orWhere(fn (Builder $own) => $own
                    ->where('user_id', $user->id)
                    ->whereHas('project.members', fn (Builder $members) => $members
                        ->whereKey($user->id)
                        ->whereIn('client_project_memberships.role', ['owner', 'manager', 'contributor'])))
                // A portal user narrowed to named projects must not read time
                // for the rest of the company. Same decision as the portal page,
                // asked the same way.
                ->orWhere(fn (Builder $shared) => $shared
                    ->where('status', 'approved')
                    ->where('is_visible_to_client', true)
                    ->whereHas('project', fn (Builder $projects) => app(PortalAccess::class)->constrainProjectQuery($projects, $user)));
        });
    }
}
