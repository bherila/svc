<?php

namespace App\Http\Controllers;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Every invoice in the workspace, across clients: the workspace tier's Invoices.
 *
 * Owners and admins only - a scoped member reaches invoices through the
 * clients they hold, one client at a time. The read is bounded by a page, scoped
 * by workspace, and a row whose company is not this workspace's is dropped, so a
 * migrated row naming another tenant's company shows nothing.
 */
final class WorkspaceInvoiceListController extends Controller
{
    public const int PER_PAGE = 50;

    public function __invoke(Request $request, Workspace $workspace, AgentAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($access->isWorkspaceManager($user, $workspace), 403);

        $page = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('issue_date')->orderByDesc('id')
            ->simplePaginate(self::PER_PAGE)
            ->withQueryString();

        $companies = ClientCompany::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $page->getCollection()->pluck('client_company_id')->unique()->all())
            ->get(['id', 'public_id', 'name'])
            ->keyBy('id');

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
                'issue_date' => $invoice->issue_date?->toDateString(),
                'due_date' => $invoice->due_date?->toDateString(),
                'balance_amount' => $invoice->balance_amount,
                'currency' => $invoice->currency,
                'href' => route('clients.invoice', [$workspace, $company, $invoice], absolute: false),
            ];
        }

        return Inertia::render('workspaces/invoices', [
            'invoices' => $rows,
            'next_href' => $page->nextPageUrl(),
            'previous_href' => $page->previousPageUrl(),
        ]);
    }
}
