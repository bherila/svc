<?php

namespace App\Support\ClientHome;

/**
 * What this client is currently engaged under, and what is waiting on them.
 *
 * At most one of each. Home answers "where does this stand"; the terms, the
 * items and the signature live on the detail pages these link to, because a
 * signature form on a summary screen is a decision taken next to a list of
 * other things.
 */
final class EngagementSummary
{
    public function __construct(
        public readonly ?string $agreementTitle,
        public readonly ?string $agreementStatus,
        public readonly ?string $agreementCadence,
        public readonly ?string $agreementHref,
        public readonly ?string $proposalTitle,
        public readonly ?string $proposalStatus,
        public readonly ?string $proposalHref,
    ) {}

    public function isEmpty(): bool
    {
        return $this->agreementHref === null && $this->proposalHref === null;
    }

    /**
     * @return array{
     *     agreement_title: string|null, agreement_status: string|null,
     *     agreement_cadence: string|null, agreement_href: string|null,
     *     proposal_title: string|null, proposal_status: string|null, proposal_href: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'agreement_title' => $this->agreementTitle,
            'agreement_status' => $this->agreementStatus,
            'agreement_cadence' => $this->agreementCadence,
            'agreement_href' => $this->agreementHref,
            'proposal_title' => $this->proposalTitle,
            'proposal_status' => $this->proposalStatus,
            'proposal_href' => $this->proposalHref,
        ];
    }
}
