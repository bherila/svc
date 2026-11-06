<?php

namespace App\Services\Authorization;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\DeferredLineageVisibilityAuditor;
use App\Services\Billing\InvoiceDeferredWorkStatements;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/** Client-visible invoice policy shared by portal and agent readers. */
final class PortalInvoiceQuery
{
    /** @return Builder<ClientInvoice> */
    public function visibleTo(ClientCompany $company, ?User $viewer): Builder
    {
        return $this->visible($company->workspace_id, $viewer?->id)
            ->where('client_company_id', $company->id);
    }

    /** @return Builder<ClientInvoice> */
    public function visibleInWorkspace(Workspace $workspace, User|AgentPrincipal $viewer): Builder
    {
        return $this->visible($workspace->id, $viewer->id);
    }

    /** Exact Portal grants, including for a dual workspace manager. Existing preview callers retain their policy.
     * @return Builder<ClientInvoice>
     */
    public function strictlyVisibleInWorkspace(Workspace $workspace, User|AgentPrincipal $viewer): Builder
    {
        return $this->visible($workspace->id, $viewer->id, false);
    }

    /**
     * Correlate an outer recipient users query with this invoice's strict
     * portal grants. Composed with whereExists, this checks every recipient in
     * one query and never admits a workspace manager through preview access.
     *
     * @return Builder<ClientInvoice>
     */
    public function visibleToRecipientUsers(ClientInvoice $invoice): Builder
    {
        return $this->visible($invoice->workspace_id, DB::raw('users.id'), false)
            ->where('client_company_id', $invoice->client_company_id)
            ->whereKey($invoice->id);
    }

    /**
     * Keep membership and project decisions in the invoice query. A workspace
     * can contain many portal companies; listing their invoices must not run
     * another policy query for each company or materialize disallowed rows.
     *
     * @return Builder<ClientInvoice>
     */
    private function visible(int $workspaceId, int|Expression|null $viewerId, bool $managerPreview = true): Builder
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_visible_to_client', true)
            ->whereIn('status', InvoiceStatus::charged());

        if ($viewerId === null) {
            return $invoices->whereRaw('1 = 0');
        }

        return $invoices->where(fn (Builder $access): Builder => $access
            ->whereExists(fn (QueryBuilder $manager): QueryBuilder => $manager
                ->select(DB::raw('1'))->from('workspace_memberships')
                ->whereColumn('workspace_memberships.workspace_id', 'client_invoices.workspace_id')
                ->where('workspace_memberships.user_id', $viewerId)
                ->whereIn('workspace_memberships.role', ['owner', 'admin'])
                ->when(! $managerPreview, fn (QueryBuilder $query): QueryBuilder => $query->whereRaw('1 = 0')))
            ->orWhereExists(fn (QueryBuilder $membership): QueryBuilder => $membership
                ->select(DB::raw('1'))->from('client_company_memberships')
                ->whereColumn('client_company_memberships.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_company_memberships.client_company_id', 'client_invoices.client_company_id')
                ->where('client_company_memberships.user_id', $viewerId)
                ->where(fn (QueryBuilder $scope): QueryBuilder => $scope
                    // Preserve the existing portal interpretation: only the
                    // explicit projects scope narrows a company membership.
                    ->where('client_company_memberships.access_scope', '!=', ClientCompanyMembership::SCOPE_PROJECTS)
                    ->orWhereNull('client_company_memberships.access_scope')
                    ->orWhere(fn (QueryBuilder $projects): QueryBuilder => $this->projectMembership($projects)))));
    }

    /**
     * Charged, client-visible invoices whose stored deferred snapshot predates
     * project lineage and still lists work (#610). Null spans every workspace:
     * the operator reading. A tenant-facing caller passes its own workspace.
     *
     * @return Builder<ClientInvoice>
     */
    public function withUnverifiableDeferredLineage(?Workspace $workspace): Builder
    {
        return ClientInvoice::query()
            ->when($workspace, fn (Builder $scoped, Workspace $workspace): Builder => $scoped->where('workspace_id', $workspace->id))
            ->where('is_visible_to_client', true)
            ->whereIn('status', InvoiceStatus::charged())
            ->where(fn (Builder $legacy): QueryBuilder => $this->unverifiableDeferredLineage($legacy->getQuery()));
    }

    /**
     * Of those, the invoices a project-scoped portal member would see but for
     * the unverifiable snapshot: every other project attributed to the invoice
     * is granted to that member. The population
     * {@see DeferredLineageVisibilityAuditor} reports as now hidden.
     *
     * @return Builder<ClientInvoice>
     */
    public function hiddenByUnverifiableDeferredLineage(?Workspace $workspace): Builder
    {
        return $this->withUnverifiableDeferredLineage($workspace)
            ->whereExists(fn (QueryBuilder $membership): QueryBuilder => $this->lineageHiddenMembership($membership));
    }

    /**
     * The project-scoped portal memberships those invoices are now hidden from.
     *
     * @return Builder<ClientCompanyMembership>
     */
    public function membershipsHiddenByUnverifiableDeferredLineage(?Workspace $workspace): Builder
    {
        return ClientCompanyMembership::query()
            ->when($workspace, fn (Builder $scoped, Workspace $workspace): Builder => $scoped->where('workspace_id', $workspace->id))
            ->where('access_scope', ClientCompanyMembership::SCOPE_PROJECTS)
            ->whereExists(fn (QueryBuilder $invoices): QueryBuilder => $invoices
                ->select(DB::raw('1'))->from('client_invoices')
                ->whereColumn('client_invoices.workspace_id', 'client_company_memberships.workspace_id')
                ->whereColumn('client_invoices.client_company_id', 'client_company_memberships.client_company_id')
                ->where('client_invoices.is_visible_to_client', true)
                ->whereIn('client_invoices.status', InvoiceStatus::charged())
                ->where(fn (QueryBuilder $legacy): QueryBuilder => $this->unverifiableDeferredLineage($legacy))
                ->where(fn (QueryBuilder $projects): QueryBuilder => $this->projectMembership($projects, requireVerifiableLineage: false)));
    }

    private function lineageHiddenMembership(QueryBuilder $membership): QueryBuilder
    {
        return $membership->select(DB::raw('1'))->from('client_company_memberships')
            ->whereColumn('client_company_memberships.workspace_id', 'client_invoices.workspace_id')
            ->whereColumn('client_company_memberships.client_company_id', 'client_invoices.client_company_id')
            ->where(fn (QueryBuilder $projects): QueryBuilder => $this->projectMembership($projects, requireVerifiableLineage: false));
    }

    /**
     * Agreement, line and deferred-snapshot attribution form one indivisible
     * invoice (#610): a project-scoped member sees it only when every project
     * it names is granted. A legacy snapshot that lists work whose project was
     * never recorded counts as ungranted.
     */
    private function projectMembership(QueryBuilder $projects, bool $requireVerifiableLineage = true): QueryBuilder
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
                ->whereNotExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_agreements.client_project_id')))
            // The deferred snapshot's frozen projects, never the live entries.
            ->whereNotExists(fn (QueryBuilder $deferred): QueryBuilder => $deferred
                ->select(DB::raw('1'))->from(InvoiceDeferredWorkStatements::PROJECTS_TABLE)
                ->whereColumn('client_invoice_deferred_projects.workspace_id', 'client_invoices.workspace_id')
                ->whereColumn('client_invoice_deferred_projects.client_invoice_id', 'client_invoices.id')
                ->whereNotExists(fn (QueryBuilder $grant): QueryBuilder => $this->projectGrant($grant, 'client_invoice_deferred_projects.client_project_id')))
            ->when($requireVerifiableLineage, fn (QueryBuilder $verified): QueryBuilder => $verified
                ->whereNot(fn (QueryBuilder $legacy): QueryBuilder => $this->unverifiableDeferredLineage($legacy)));
    }

    /**
     * A stored deferred snapshot older than the version that records each
     * entry's project, still listing at least one entry. Decided from the
     * stored JSON itself, so a snapshot the document reader cannot render is
     * unverifiable too: a missing section never decides access. One that lists
     * nothing discloses nothing and does not hide the invoice.
     *
     */
    private function unverifiableDeferredLineage(QueryBuilder $query): QueryBuilder
    {
        $statement = 'client_invoices.deferred_work_statement';

        return $query->whereNotNull($statement)
            ->where(fn (QueryBuilder $version): QueryBuilder => $version
                ->whereNull($statement.'->version')
                ->orWhere($statement.'->version', '!=', InvoiceDeferredWorkSnapshot::VERSION))
            ->where(fn (QueryBuilder $entries): QueryBuilder => $entries
                ->whereNull($statement.'->entries')
                ->orWhereJsonLength($statement.'->entries', '>', 0));
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
