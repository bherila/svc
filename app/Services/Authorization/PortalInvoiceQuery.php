<?php

namespace App\Services\Authorization;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The invoices one client company's portal may show.
 *
 * Four conditions, each load-bearing. The workspace and the company bound it to
 * this tenant and this client; `is_visible_to_client` is the operator's own
 * decision about disclosure; and the status list keeps a draft out, because a
 * draft is working arithmetic and showing a client a figure nobody has
 * committed to invites an argument about a number that was never sent.
 *
 * A service rather than a private method on the controller that first needed
 * it. Three screens now ask this question - the client's home, their invoice
 * list and one invoice - and a detail screen that admitted an invoice the list
 * would not is the whole bug: the client never sees the row, and reaches it by
 * id.
 */
final class PortalInvoiceQuery
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    /**
     * @return Builder<ClientInvoice>
     */
    public function visibleTo(ClientCompany $company, ?User $viewer): Builder
    {
        $invoices = ClientInvoice::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            ->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid']);

        $visibleProjectIds = $this->portalAccess->visibleProjectIds($company, $viewer);

        // Null is the whole company - an owner, an admin, or a client whose
        // access is company-scoped. A project-scoped client is a different
        // question, and the invoice list ignored it: they were shown, and could
        // open, every invoice the company had, including for work on projects
        // their own portal deliberately hides.
        if ($visibleProjectIds === null) {
            return $invoices;
        }

        if ($visibleProjectIds === []) {
            return $invoices->whereRaw('1 = 0');
        }

        // The same rule the operator side applies: every project the invoice
        // names must be one they see, and an invoice naming none is not theirs
        // to read. A mixed invoice is refused rather than partly rendered,
        // because its totals and its PDF describe the whole document.
        return $invoices
            ->whereNotExists(fn (QueryBuilder $line): QueryBuilder => $line
                ->select(DB::raw('1'))
                ->from('client_invoice_lines')
                ->whereColumn('client_invoice_lines.client_invoice_id', 'client_invoices.id')
                ->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                ->whereNotNull('client_invoice_lines.client_project_id')
                ->whereNotIn('client_invoice_lines.client_project_id', $visibleProjectIds))
            ->whereExists(fn (QueryBuilder $line): QueryBuilder => $line
                ->select(DB::raw('1'))
                ->from('client_invoice_lines')
                ->whereColumn('client_invoice_lines.client_invoice_id', 'client_invoices.id')
                ->whereColumn('client_invoice_lines.workspace_id', 'client_invoices.workspace_id')
                ->whereIn('client_invoice_lines.client_project_id', $visibleProjectIds));
    }
}
