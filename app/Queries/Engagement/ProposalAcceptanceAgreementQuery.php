<?php

namespace App\Queries\Engagement;

use App\Models\ClientAgreement;
use App\Models\ClientProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The agreement reads that decide whether a proposal may be accepted.
 *
 * These predicates are shared with the standing auditor. Keeping them here
 * prevents the write path and the operator's count from acquiring subtly
 * different meanings of linked, unlinked, active, company, or workspace.
 */
final class ProposalAcceptanceAgreementQuery
{
    public function linkedAgreement(ClientProposal $proposal): ?ClientAgreement
    {
        return ClientAgreement::query()
            ->where('workspace_id', $proposal->workspace_id)
            ->where('client_company_id', $proposal->client_company_id)
            ->where('source_proposal_id', $proposal->id)
            ->first();
    }

    public function hasActiveUnlinkedAgreement(ClientProposal $proposal): bool
    {
        $proposalInItsTenant = ClientProposal::query()
            ->whereKey($proposal->id)
            ->where('workspace_id', $proposal->workspace_id)
            ->where('client_company_id', $proposal->client_company_id);

        return $this->withActiveUnlinkedAgreement($proposalInItsTenant)->exists();
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
    public function withActiveUnlinkedAgreement(Builder $proposals): Builder
    {
        return $this->withUnlinkedAgreementMatching($proposals, activeOnly: true);
    }

    /**
     * @param  Builder<ClientProposal>  $proposals
     * @return Builder<ClientProposal>
     */
    private function withUnlinkedAgreementMatching(Builder $proposals, bool $activeOnly): Builder
    {
        return $proposals->whereExists(
            function (QueryBuilder $agreements) use ($activeOnly): QueryBuilder {
                $agreements
                    ->selectRaw('1')
                    ->from('client_agreements')
                    ->whereColumn('client_agreements.client_company_id', 'client_proposals.client_company_id')
                    ->whereColumn('client_agreements.workspace_id', 'client_proposals.workspace_id')
                    ->whereNull('client_agreements.source_proposal_id');

                return $activeOnly
                    ? $agreements->where('client_agreements.status', 'active')
                    : $agreements;
            },
        );
    }
}
