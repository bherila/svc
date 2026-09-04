<?php

namespace App\Queries\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Support\WorkspaceClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The agreement reads and shared lock that protect contract transitions.
 *
 * Proposal acceptance, activation, active-term correction, and the standing
 * auditor must not acquire subtly different meanings of linked, overlapping,
 * active, company, project, or workspace.
 */
final class ProposalAcceptanceAgreementQuery
{
    public function __construct(private readonly WorkspaceClock $clock = new WorkspaceClock) {}

    public function linkedAgreement(ClientProposal $proposal): ?ClientAgreement
    {
        return ClientAgreement::query()
            ->where('workspace_id', $proposal->workspace_id)
            ->where('client_company_id', $proposal->client_company_id)
            ->where('source_proposal_id', $proposal->id)
            ->first();
    }

    /**
     * Serialize every agreement-state decision for one tenant/company.
     *
     * Locking only the proposal or agreement being changed lets two different
     * rows both observe an empty active set. The shared parent is the row every
     * acceptance, activation, and active-term correction has in common.
     */
    public function lockCompany(int $workspaceId, int $companyId): void
    {
        ClientCompany::query()
            ->whereKey($companyId)
            ->where('workspace_id', $workspaceId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function hasActiveUnlinkedAgreement(ClientProposal $proposal): bool
    {
        $proposalInItsTenant = ClientProposal::query()
            ->whereKey($proposal->id)
            ->where('workspace_id', $proposal->workspace_id)
            ->where('client_company_id', $proposal->client_company_id);

        return $this->withActiveUnlinkedAgreement(
            $proposalInItsTenant,
            $this->clock->today($proposal->workspace),
        )->exists();
    }

    /**
     * Whether activating this agreement would overlap another active contract
     * for the exact same tenant, company, and project scope.
     */
    public function hasOverlappingActiveAgreement(ClientAgreement $agreement): bool
    {
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $agreement->workspace_id)
            ->where('client_company_id', $agreement->client_company_id)
            ->whereKeyNot($agreement->id)
            ->where('status', 'active');

        $agreement->client_project_id === null
            ? $agreements->whereNull('client_project_id')
            : $agreements->where('client_project_id', $agreement->client_project_id);

        if ($agreement->ends_on !== null) {
            $agreements->where('starts_on', '<=', $agreement->ends_on->toDateString());
        }

        return $agreements
            ->where(function (Builder $candidate) use ($agreement): void {
                $candidate
                    ->whereNull('ends_on')
                    ->orWhere('ends_on', '>=', $agreement->starts_on->toDateString());
            })
            ->exists();
    }

    /**
     * @param  Builder<ClientProposal>  $proposals
     * @return Builder<ClientProposal>
     */
    public function withoutLinkedAgreement(Builder $proposals): Builder
    {
        return $proposals->whereNotExists(
            fn (QueryBuilder $agreements): QueryBuilder => $agreements
                ->selectRaw('1')
                ->from('client_agreements')
                ->whereColumn('client_agreements.source_proposal_id', 'client_proposals.id')
                ->whereColumn('client_agreements.client_company_id', 'client_proposals.client_company_id')
                ->whereColumn('client_agreements.workspace_id', 'client_proposals.workspace_id'),
        );
    }

    /**
     * @param  Builder<ClientProposal>  $proposals
     * @return Builder<ClientProposal>
     */
    public function withUnlinkedAgreement(Builder $proposals): Builder
    {
        return $this->withUnlinkedAgreementMatching($proposals, activeOnly: false);
    }

    /**
     * @param  Builder<ClientProposal>  $proposals
     * @return Builder<ClientProposal>
     */
    public function withActiveUnlinkedAgreement(Builder $proposals, ?CarbonImmutable $today = null): Builder
    {
        // Acceptance creates an active [today, infinity] term. Any active
        // candidate that has not ended before today overlaps that term,
        // including one whose own start date is still in the future.
        return $this->withUnlinkedAgreementMatching(
            $proposals,
            activeOnly: true,
            today: $today ?? $this->clock->today(),
        );
    }

    /**
     * @param  Builder<ClientProposal>  $proposals
     * @return Builder<ClientProposal>
     */
    private function withUnlinkedAgreementMatching(
        Builder $proposals,
        bool $activeOnly,
        ?CarbonImmutable $today = null,
    ): Builder {
        return $proposals->whereExists(
            function (QueryBuilder $agreements) use ($activeOnly, $today): QueryBuilder {
                $agreements
                    ->selectRaw('1')
                    ->from('client_agreements')
                    ->whereColumn('client_agreements.client_company_id', 'client_proposals.client_company_id')
                    ->whereColumn('client_agreements.workspace_id', 'client_proposals.workspace_id')
                    ->where(function (QueryBuilder $projects): void {
                        $projects
                            ->whereColumn('client_agreements.client_project_id', 'client_proposals.client_project_id')
                            ->orWhere(function (QueryBuilder $companyWide): void {
                                $companyWide
                                    ->whereNull('client_agreements.client_project_id')
                                    ->whereNull('client_proposals.client_project_id');
                            });
                    })
                    ->whereNull('client_agreements.source_proposal_id');

                return $activeOnly
                    ? $agreements
                        ->where('client_agreements.status', 'active')
                        ->where(function (QueryBuilder $term) use ($today): void {
                            $term
                                ->whereNull('client_agreements.ends_on')
                                ->orWhere('client_agreements.ends_on', '>=', $today?->toDateString());
                        })
                    : $agreements;
            },
        );
    }
}
