<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Support\Billing\DeferredLineageVisibilityCounts;

/**
 * Report the invoices now hidden from project-scoped portal members because
 * their deferred snapshot's project lineage cannot be established (#610).
 *
 * Version 1 of the deferred-work snapshot stored each entry's date, minutes
 * and description but not its project. The portal's invoice grant rule
 * ({@see PortalInvoiceQuery}) requires every project an invoice discloses to
 * be granted, and a legacy snapshot that lists work cannot prove which projects
 * that is, so project-scoped members are refused it. Inferring the project
 * from today's time entry would rewrite history when an entry is edited, and
 * cannot reach a deleted one, so nothing here guesses.
 *
 * Read-only. The population comes from the same query the portal applies, so
 * the audit and the refusal cannot disagree about which invoices are affected.
 * Scope is a parameter: unscoped is the operator reading and lists no invoice;
 * a tenant-facing caller passes its own workspace and may ask for a bounded
 * list of the invoices to fix.
 */
final class DeferredLineageVisibilityAuditor
{
    public function __construct(private readonly PortalInvoiceQuery $invoices) {}

    public function count(?Workspace $workspace = null, int $listLimit = 0): DeferredLineageVisibilityCounts
    {
        $hidden = $this->invoices->hiddenByUnverifiableDeferredLineage($workspace);
        $listed = [];
        if ($workspace !== null && $listLimit > 0) {
            foreach ((clone $hidden)->orderBy('id')->limit($listLimit + 1)->get(['id', 'public_id', 'invoice_number']) as $invoice) {
                $listed[] = $this->identity($invoice);
            }
        }

        return new DeferredLineageVisibilityCounts(
            withUnverifiableLineage: $this->invoices->withUnverifiableDeferredLineage($workspace)->count(),
            hiddenFromProjectScopedMembers: (clone $hidden)->count(),
            affectedProjectScopedMemberships: $this->invoices->membershipsHiddenByUnverifiableDeferredLineage($workspace)->count(),
            hiddenInvoices: array_slice($listed, 0, max(0, $listLimit)),
            hiddenInvoicesTruncated: count($listed) > $listLimit,
        );
    }

    /** @return array{id: string, invoice_number: string|null} */
    private function identity(ClientInvoice $invoice): array
    {
        $id = $invoice->getAttribute('public_id');
        $number = $invoice->getAttribute('invoice_number');

        return [
            'id' => is_string($id) ? $id : '',
            'invoice_number' => is_string($number) ? $number : null,
        ];
    }
}
