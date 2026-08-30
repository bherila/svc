<?php

namespace App\Support\Billing;

use Carbon\CarbonImmutable;

/** Immutable agreement, tenant, project, and period boundary for replay sources. */
final readonly class ReplayInvoiceSourceScope
{
    public function __construct(
        public int $workspaceId,
        public int $companyId,
        public ?int $agreementProjectId,
        public CarbonImmutable $servicePeriodStart,
        public CarbonImmutable $servicePeriodEnd,
    ) {}

    /**
     * Can one already-loaded time entry legitimately back this invoice?
     *
     * Project ownership is checked even for company-wide agreements. The
     * schema stores independent company and project foreign keys, so trusting
     * the entry's company alone would accept a project owned by another client.
     */
    public function contains(
        int $entryWorkspaceId,
        int $entryCompanyId,
        int $entryProjectId,
        int $projectWorkspaceId,
        int $projectCompanyId,
        CarbonImmutable $workedOn,
        bool $deferred,
    ): bool {
        return $this->servicePeriodStart->lte($this->servicePeriodEnd)
            && $entryWorkspaceId === $this->workspaceId
            && $entryCompanyId === $this->companyId
            && $projectWorkspaceId === $this->workspaceId
            && $projectCompanyId === $this->companyId
            && ($this->agreementProjectId === null || $entryProjectId === $this->agreementProjectId)
            // DeferredBillingAllocator intentionally reaches back without a
            // lower date bound once deferred work becomes allocatable. It
            // retains the invoice's upper boundary and every tenant/project
            // check above.
            && $workedOn->lte($this->servicePeriodEnd)
            && ($deferred || $workedOn->gte($this->servicePeriodStart));
    }
}
