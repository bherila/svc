<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Client-visible invoice policy shared by portal and agent readers. */
final class PortalInvoiceQuery
{
    /** @return Builder<ClientInvoice> */
    public function visibleTo(ClientCompany $company, ?User $viewer): Builder
    {
        return $this->visible($company->workspace_id, $viewer)
            ->where('client_company_id', $company->id);
    }

    /** @return Builder<ClientInvoice> */
    public function visibleInWorkspace(Workspace $workspace, User|AgentPrincipal $viewer): Builder
    {
        return $this->visible($workspace->id, $viewer);
    }

    /**
     * Keep membership and project decisions in the invoice query. A workspace
     * can contain many portal companies; listing their invoices must not run
     * another policy query for each company or materialize disallowed rows.
     *
     * @return Builder<ClientInvoice>
     */
    private function visible(int $workspaceId, User|AgentPrincipal|null $viewer): Builder
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid']);

        if ($viewer === null) {
            return $invoices->whereRaw('1 = 0');
        }

        return $invoices->where(fn (Builder $access): Builder => $access
            ->whereExists(fn (QueryBuilder $manager): QueryBuilder => $manager
                ->select(DB::raw('1'))->from('workspace_memberships')
                ->whereColumn('workspace_memberships.workspace_id', 'client_invoices.workspace_id')
                ->where('workspace_memberships.user_id', $viewer->id)
                ->whereIn('workspace_memberships.role', ['owner', 'admin']))
            ->orWhereExists(fn (QueryBuilder $membership): QueryBuilder => $membership
                ->select(DB::raw('1'))->from('client_company_memberships')
                ->whereColumn('client_company_memberships.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_company_memberships.client_company_id', 'client_invoices.client_company_id')
                ->where('client_company_memberships.user_id', $viewer->id)
                ->where(fn (QueryBuilder $scope): QueryBuilder => $scope
                    // Preserve the existing portal interpretation: only the
                    // explicit projects scope narrows a company membership.
                    ->where('client_company_memberships.access_scope', '!=', ClientCompanyMembership::SCOPE_PROJECTS)
                    ->orWhereNull('client_company_memberships.access_scope')
                    ->orWhere(fn (QueryBuilder $projects): QueryBuilder => $this->projectMembership($projects)))));
    }

    /** Agreement and line attribution form one indivisible invoice. */
    private function projectMembership(QueryBuilder $projects): QueryBuilder
    {
        return $projects->where('client_company_memberships.access_scope', ClientCompanyMembership::SCOPE_PROJECTS)
            // A missing or foreign agreement is not an agreement with no
            // project: its attribution cannot be established, so refuse it.
            ->where(fn (QueryBuilder $reference): QueryBuilder => $reference
                ->whereNull('client_invoices.client_agreement_id')
                ->orWhereExists(fn (QueryBuilder $agreement): QueryBuilder => $this->agreement($agreement)))
            ->where(fn (QueryBuilder $attributed): QueryBuilder => $attributed
                ->whereExists(fn (QueryBuilder $line): QueryBuilder => $this->projectLines($line)
                    ->whereExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_invoice_lines.client_project_id')))
                ->orWhereExists(fn (QueryBuilder $agreement): QueryBuilder => $this->agreement($agreement)
                    ->whereNotNull('client_agreements.client_project_id')
                    ->whereExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_agreements.client_project_id'))))
            ->whereNotExists(fn (QueryBuilder $line): QueryBuilder => $this->projectLines($line)
                ->whereNotExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_invoice_lines.client_project_id')))
            ->whereNotExists(fn (QueryBuilder $agreement): QueryBuilder => $this->agreement($agreement)
                ->whereNotNull('client_agreements.client_project_id')
                ->whereNotExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_agreements.client_project_id')));
    }

    private function agreement(QueryBuilder $agreement): QueryBuilder
    {
        return $agreement->select(DB::raw('1'))->from('client_agreements')
            ->whereColumn('client_agreements.id', 'client_invoices.client_agreement_id')
            ->whereColumn('client_agreements.workspace_id', 'client_invoices.workspace_id')
            ->whereColumn('client_agreements.client_company_id', 'client_invoices.client_company_id');
    }

    /** An invoice needs lineage, and every named project must be granted. */
    private function projectLines(QueryBuilder $line): QueryBuilder
    {
        return $line->select(DB::raw('1'))->from('client_invoice_lines')
            ->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
            ->whereColumn('client_invoice_lines.client_invoice_id', 'client_invoices.id')
            ->whereNotNull('client_invoice_lines.client_project_id');
    }

    private function projectGrant(QueryBuilder $grant, string $projectColumn): QueryBuilder
    {
        return $grant->select(DB::raw('1'))->from('client_portal_project_access')
            ->whereColumn('client_portal_project_access.workspace_id', 'client_invoices.workspace_id')
            ->whereColumn('client_portal_project_access.client_company_membership_id', 'client_company_memberships.id')
            ->whereColumn('client_portal_project_access.client_project_id', $projectColumn)
            ->whereExists(fn (QueryBuilder $project): QueryBuilder => $project
                ->select(DB::raw('1'))->from('client_projects')
                ->whereColumn('client_projects.id', 'client_portal_project_access.client_project_id')
                ->whereColumn('client_projects.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_projects.client_company_id', 'client_invoices.client_company_id'));
    }
}
