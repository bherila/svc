<?php

namespace App\Support\Billing;

use App\Services\Billing\DeferredLineageVisibilityAuditor;

/**
 * Invoices a project-scoped portal member can no longer read because their
 * stored deferred snapshot predates project lineage (#610), and the supported
 * fix.
 *
 * Counts, plus - only for a caller reading one workspace it manages - a bounded
 * list of the affected invoices' public ids and numbers, because the fix is
 * applied invoice by invoice. Nothing about the hidden work itself (dates,
 * hours, descriptions, projects or people) is ever reported. See
 * {@see DeferredLineageVisibilityAuditor}.
 */
final readonly class DeferredLineageVisibilityCounts
{
    public const string REMEDY = 'These invoices list deferred work recorded before SVC captured each entry\'s project, so project-scoped portal members are refused them (not found) while company-wide members and workspace managers keep access. To restore a project-scoped member\'s access, regenerate the draft, or reopen and reissue the issued invoice, so its deferred snapshot records project lineage; then grant that member every project the invoice names. Alternatively give the member company-wide portal access. A paid invoice cannot be reopened, so only company-wide access reaches it. This audit changes nothing.';

    /**
     * @param  list<array{id: string, invoice_number: string|null}>  $hiddenInvoices
     */
    public function __construct(
        public int $withUnverifiableLineage,
        public int $hiddenFromProjectScopedMembers,
        public int $affectedProjectScopedMemberships,
        public array $hiddenInvoices,
        public bool $hiddenInvoicesTruncated,
    ) {}

    /** Whether any project-scoped member is refused an invoice by this rule today. */
    public function isLive(): bool
    {
        return $this->hiddenFromProjectScopedMembers > 0;
    }

    /**
     * The machine-readable shape, stable for the `--format=json` contract.
     *
     * @return array{
     *     with_unverifiable_lineage: int,
     *     hidden_from_project_scoped_members: int,
     *     affected_project_scoped_memberships: int,
     *     hidden_invoices: list<array{id: string, invoice_number: string|null}>,
     *     hidden_invoices_truncated: bool,
     *     remedy: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'with_unverifiable_lineage' => $this->withUnverifiableLineage,
            'hidden_from_project_scoped_members' => $this->hiddenFromProjectScopedMembers,
            'affected_project_scoped_memberships' => $this->affectedProjectScopedMemberships,
            'hidden_invoices' => $this->hiddenInvoices,
            'hidden_invoices_truncated' => $this->hiddenInvoicesTruncated,
            'remedy' => self::REMEDY,
        ];
    }
}
