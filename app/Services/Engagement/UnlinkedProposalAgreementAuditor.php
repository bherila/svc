<?php

namespace App\Services\Engagement;

use App\Models\ClientProposal;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Services\Billing\UnplaceableInvoiceAuditor;
use App\Support\Engagement\UnlinkedProposalAgreementCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Count the proposals whose agreement cannot be found through the link that
 * decides whether acceptance proceeds or asks an operator to intervene.
 *
 * `ProposalWorkflow::accept()` asks the shared acceptance query whether a
 * proposal has already produced an agreement. The linking column is nullable,
 * so an agreement whose `source_proposal_id` is null is invisible to that
 * question regardless of how it was actually created. Acceptance now refuses
 * the active same-company state rather than creating a second agreement.
 *
 * This is the null-semantics class of #141 on a foreign key rather than a date.
 * The others drop a row out of a window; this one makes a duplicate guard fail
 * to see the very row it exists to find, which is the more expensive shape - a
 * dropped row reports a wrong number, an unseen row creates a contract.
 *
 * ## Why the column stays nullable, and why that is correct
 *
 * Not every agreement comes from a proposal. An operator can write one
 * directly, and retained historical agreements may not have a source proposal.
 * So a null means two different things - "this agreement had no proposal" and
 * "this agreement's proposal is no longer known" - and nothing in the schema
 * separates them.
 * Making the column non-nullable would be wrong for the first meaning and would
 * not recover the second.
 *
 * That ambiguity is exactly why this audit remains useful after the guard. The
 * repair is to restore known links, and #148 is explicit that acceptance must not guess:
 * matching an existing agreement by company, title or date inside a write path
 * trades a duplicate for a mis-attribution, which is worse and far harder to
 * detect afterwards. Sizing the population is what decides whether the defect is
 * live or latent, and therefore what the fix has to be.
 *
 * ## Why this is a service and not just a console command
 *
 * The same reason as {@see UnplaceableInvoiceAuditor}: the
 * column stays nullable, the importer keeps passing source values through, and
 * an operator can create an unlinked agreement at any time, so this is a
 * standing data-quality question rather than a migration one-off. Console and
 * any later operator screen should consume one definition of "affected" instead
 * of each re-deriving it and drifting apart.
 *
 * Scope is a parameter for the same reason. Unscoped is the operator reading;
 * anything tenant-facing must pass its own workspace, because nothing else here
 * scopes for it.
 */
final class UnlinkedProposalAgreementAuditor
{
    public function __construct(private readonly ProposalAcceptanceAgreementQuery $acceptanceAgreements) {}

    /**
     * Count the affected proposals, optionally within one workspace.
     *
     * Passing null counts across every workspace. That is deliberate and is the
     * operator/CLI reading; any caller rendering to a tenant must pass that
     * tenant's workspace.
     */
    public function count(?Workspace $workspace = null): UnlinkedProposalAgreementCounts
    {
        // A proposal needs intervention if the linked-agreement lookup comes
        // back empty. Use the same tenant-scoped predicate as acceptance so the
        // operator's count and the write guard cannot drift apart.
        $unlinked = fn (Builder $proposals): Builder => $this->acceptanceAgreements
            ->withoutLinkedAgreement($proposals);

        // The status guard is the whole difference between live and latent, so
        // the two populations are counted separately rather than summed.
        //
        // `sent` is the actionable one: accept() refuses its active-unlinked
        // subset until an operator resolves the agreement state.
        // `accepted` takes the early return today and creates nothing, so it is
        // inert - but it is the same broken link, and it is worth sizing
        // because it is the population that would become dangerous if that
        // guard were ever relaxed or if a proposal were moved back to `sent`.
        $sent = $unlinked($this->proposals($workspace)->where('status', 'sent'));
        $accepted = $unlinked($this->proposals($workspace)->where('status', 'accepted'));

        // An unlinked `sent` proposal is only evidence of a *duplicate* if the
        // company already has an agreement that the missing link might have
        // pointed at. Without one there is nothing to duplicate: accepting it
        // creates the company's first agreement, which is the correct outcome
        // and not this defect.
        //
        // Restricted to agreements that are not themselves linked to some other
        // proposal. One that names a different proposal is accounted for and
        // cannot be this proposal's lost link, so counting it would inflate the
        // population with companies whose proposals are all properly linked.
        $withACandidate = $this->acceptanceAgreements->withUnlinkedAgreement(clone $sent);

        // Narrowed once more to an agreement that is currently active. A
        // cancelled or expired agreement can still be the proposal's true
        // origin, so this is not a smaller count of the same thing - it is the
        // subset where a second agreement would bill alongside a live one, and
        // therefore where the duplicate costs money now rather than
        // retrospectively muddying the record.
        $withAnActiveCandidate = $this->acceptanceAgreements->withActiveUnlinkedAgreement(clone $sent);

        return new UnlinkedProposalAgreementCounts(
            proposals: $this->proposals($workspace)->count(),
            sentWithoutALinkedAgreement: $sent->count(),
            withAnUnlinkedAgreementOnTheCompany: $withACandidate->count(),
            withAnActiveUnlinkedAgreement: $withAnActiveCandidate->count(),
            acceptedWithoutALinkedAgreement: $accepted->count(),
            unlinkedAgreements: $this->unlinkedAgreements($workspace),
        );
    }

    /**
     * @return Builder<ClientProposal>
     */
    private function proposals(?Workspace $workspace): Builder
    {
        $proposals = ClientProposal::query();

        return $workspace === null
            ? $proposals
            : $proposals->where('workspace_id', $workspace->id);
    }

    /**
     * Agreements carrying no proposal link at all, as context for the rest.
     *
     * Most of these are legitimate - an agreement written directly has no
     * proposal and never did. It is reported because it is the ceiling on how
     * much repair work exists: no link can be restored to an agreement outside
     * this set, and a count far larger than the affected proposals says the
     * nulls are mostly benign rather than mostly lost.
     */
    private function unlinkedAgreements(?Workspace $workspace): int
    {
        $agreements = DB::table('client_agreements')->whereNull('source_proposal_id');

        if ($workspace !== null) {
            $agreements->where('workspace_id', $workspace->id);
        }

        return $agreements->count();
    }
}
