<?php

namespace App\Support\Engagement;

use App\Services\Engagement\UnlinkedProposalAgreementAuditor;

/**
 * How many proposals accept() cannot see an agreement for.
 *
 * Counts only - never a row, a proposal title, a company or a workspace. That
 * is a property of this type rather than of the code that renders it: a caller
 * physically cannot leak an identifier through it, so the console command and
 * any later operator screen are both safe against a database of real client
 * records without each having to be careful in its own way.
 *
 * The `sent` funnel is cumulative and each stage alone overstates, which is why
 * the stages are reported separately rather than as one number. See
 * {@see UnlinkedProposalAgreementAuditor} for what each stage removes and why.
 */
final readonly class UnlinkedProposalAgreementCounts
{
    public function __construct(
        public int $proposals,
        public int $sentWithoutALinkedAgreement,
        public int $withAnUnlinkedAgreementOnTheCompany,
        public int $withAnActiveUnlinkedAgreement,
        public int $acceptedWithoutALinkedAgreement,
        public int $unlinkedAgreements,
    ) {}

    /**
     * The narrowest population that would create a duplicate contract today.
     *
     * The end of the `sent` funnel, named rather than left as the last field so
     * that callers deciding whether the defect is live ask one question instead
     * of choosing a stage themselves and choosing differently from each other.
     */
    public function isLive(): bool
    {
        return $this->withAnActiveUnlinkedAgreement > 0;
    }

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     proposals: int,
     *     sent_without_a_linked_agreement: int,
     *     with_an_unlinked_agreement_on_the_company: int,
     *     with_an_active_unlinked_agreement: int,
     *     accepted_without_a_linked_agreement: int,
     *     unlinked_agreements: int,
     * }
     */
    public function toArray(): array
    {
        return [
            'proposals' => $this->proposals,
            'sent_without_a_linked_agreement' => $this->sentWithoutALinkedAgreement,
            'with_an_unlinked_agreement_on_the_company' => $this->withAnUnlinkedAgreementOnTheCompany,
            'with_an_active_unlinked_agreement' => $this->withAnActiveUnlinkedAgreement,
            'accepted_without_a_linked_agreement' => $this->acceptedWithoutALinkedAgreement,
            'unlinked_agreements' => $this->unlinkedAgreements,
        ];
    }
}
