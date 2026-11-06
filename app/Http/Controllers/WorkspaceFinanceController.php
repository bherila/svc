<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Support\Billing\InvoiceStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Money owed to the workspace, and where to record what arrived: Finance.
 *
 * The reconciliation half of the retired Operations screen. Recording a payment
 * itself stays on the invoice, where the version and idempotency checks live;
 * this lists the balances that are waiting for one. Owners and admins only,
 * workspace-scoped, one page.
 */
final class WorkspaceFinanceController extends Controller
{
    public const int PER_PAGE = 50;

    public function __invoke(Request $request, Workspace $workspace, AgentAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        $page = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', InvoiceStatus::recordable())
            ->where('balance_amount', '>', 0)
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $page->getCollection()->pluck('client_company_id')->unique()->all())
            ->get(['id', 'public_id', 'name'])->keyBy('id');

        $rows = [];

        foreach ($page->getCollection() as $invoice) {
            $company = $companies->get($invoice->client_company_id);

            if (! $company instanceof ClientCompany) {
                continue;
            }

            $rows[] = [
                'id' => $invoice->public_id,
                'invoice_number' => $invoice->invoice_number,
                'client' => $company->name,
                'status' => $invoice->status,
                'due_date' => $invoice->due_date?->toDateString(),
                'balance_amount' => $invoice->balance_amount,
                'currency' => $invoice->currency,
                'href' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
            ];
        }

        return Inertia::render('workspaces/finance', [
            'open_invoices' => $rows,
            'next_href' => $page->nextPageUrl(),
            'previous_href' => $page->previousPageUrl(),
        ]);
    }
}
