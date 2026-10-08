<?php

namespace App\Services\Engagement;

use App\Models\AgentPrincipal;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\PortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** One visibility rule for portal acceptance, REST and MCP discovery. */
final class ProposalAccess
{
    public function __construct(private readonly AgentAccess $access, private readonly PortalAccess $portal) {}

    public function requireManager(User $actor, Workspace $workspace): void
    {
        abort_unless($this->access->isWorkspaceManager($actor, $workspace), 403);
    }

    /** @return Builder<ClientProposal> */
    public function visible(User|AgentPrincipal $actor, Workspace $workspace): Builder
    {
        $query = ClientProposal::query()->where('workspace_id', $workspace->id)
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
            ->where(fn (Builder $parents): Builder => $parents->whereNull('client_project_id')->orWhereHas('project', fn (Builder $project): Builder => $project
                ->where('workspace_id', $workspace->id)->whereColumn('client_projects.client_company_id', 'client_proposals.client_company_id')));
        if ($this->access->isWorkspaceManager($actor, $workspace)) {
            return $query;
        }
        abort_unless($this->access->canViewWorkspace($actor, $workspace), 404);

        return $query->where('is_visible_to_client', true)->whereIn('status', ['sent', 'accepted'])
            ->whereExists(fn (QueryBuilder $membership): QueryBuilder => $membership->selectRaw('1')->from('client_company_memberships')
                ->whereColumn('client_company_memberships.workspace_id', 'client_proposals.workspace_id')
                ->whereColumn('client_company_memberships.client_company_id', 'client_proposals.client_company_id')->where('user_id', $actor->id))
            ->where(fn (Builder $scope): Builder => $scope->whereNull('client_project_id')->orWhereIn('client_project_id',
                $this->portal->constrainProjectQuery(ClientProject::query()->where('workspace_id', $workspace->id), $actor)->select('id')));
    }

    public function requireAcceptance(User $actor, Workspace $workspace, ClientProposal $proposal): void
    {
        abort_unless($proposal->is_visible_to_client && in_array($proposal->status, ['sent', 'accepted'], true), 404);
        abort_unless($this->visible($actor, $workspace)->whereKey($proposal->id)->exists(), 404);
    }
}
