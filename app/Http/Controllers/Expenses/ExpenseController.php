<?php

namespace App\Http\Controllers\Expenses;

use App\Http\Controllers\Controller;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\AgentApi\Presenters\AgentExpensePresenter;
use App\Support\Expenses\ExpenseStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The client expense page decorates the canonical API presentation with scoped
 * project names, billing links and browser capabilities. All writes use the
 * authenticated /api/v1 operations and their version/idempotency guards.
 */
class ExpenseController extends Controller
{
    public function index(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
    ): Response {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        // Membership of a workspace is not access to every client in it. The
        // `view` gate passes for an ordinary member, so without this a member
        // scoped to one client's projects reads another client's spending -
        // the amounts, what they were for, and who signed them off. Same rule
        // the other client tabs apply, and `TenantRouteReachabilityTest` is
        // what caught this route missing it.
        $reachable = $access->reachableCompanyIds($user, $workspace);
        abort_if(
            $reachable !== null && ! in_array((int) $clientCompany->id, $reachable, true),
            404,
        );

        // And reaching the client is not reading all of its money. A member on
        // one of a client's projects reaches the company, so the check above
        // passes and every one of that client's expenses would follow -
        // including the other projects' and the company-level ones.
        //
        // `BillingRecordAccess` settled this shape already: a record naming no
        // project "covers work this viewer cannot see, so it is refused on the
        // same reasoning as an invoice with no lineage". An unattributed
        // expense is exactly that, so it is a manager's to read. Null means
        // the viewer is unscoped and everything is theirs.
        $viewable = $access->viewableProjectIds($user, $workspace);
        $isManager = Gate::allows('manage', $workspace);

        $expenses = $this->expenses($workspace)
            ->query()
            ->where('client_company_id', $clientCompany->id)
            ->when(
                $viewable !== null,
                fn ($query) => $query->whereIn('client_project_id', $viewable ?? []),
            )
            ->with([
                'project' => fn ($query) => $query->where('workspace_id', $workspace->id),
                'approvedBy',
                'clientCompany' => fn ($query) => $query->where('workspace_id', $workspace->id),
                ...($isManager ? [
                    'invoiceLine' => fn ($query) => $query->where('workspace_id', $workspace->id)->with([
                        'invoice' => fn ($invoice) => $invoice->where('workspace_id', $workspace->id)->where('client_company_id', $clientCompany->id),
                    ]),
                ] : []),
            ])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('clients/expenses', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            // Finished capabilities rather than a role for the browser to
            // interpret. A viewer who cannot manage the workspace reads the
            // list and is offered nothing to press.
            'permissions' => [
                'record' => $isManager,
                'approve' => $isManager,
            ],
            // Finished URLs, not ids for the browser to assemble. The writes
            // are keyed by the expense and the record form by the company, so
            // a page building these itself would need the workspace id as
            // well - one more thing to pass and one more chance to pass the
            // wrong one.
            'schedule_url' => $isManager ? route('clients.expense-schedules', [$workspace, $clientCompany], absolute: false) : null,
            'urls' => [
                'store' => route('agent-api.v1.expenses.store', $workspace, absolute: false),
            ],
            // The workspace's own calendar and currency. A new expense
            // defaulting to UTC's date records an evening in Los Angeles as
            // tomorrow, and one defaulting to USD misprices every workspace
            // that bills in something else - both accepted without warning
            // because both are valid answers, just not the right ones.
            'workspace' => [
                'timezone' => (string) $workspace->timezone,
                'default_currency' => (string) $workspace->default_currency,
            ],
            // Scoped the same way, so the list of names a reader can attribute
            // to is the list they may see at all.
            'projects' => ClientProject::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id)
                ->when(
                    $viewable !== null,
                    fn ($query) => $query->whereIn('id', $viewable ?? []),
                )
                ->orderBy('name')
                ->get()
                ->map(fn (ClientProject $project): array => [
                    'id' => $project->public_id,
                    'name' => $project->name,
                ])
                ->values(),
            'expenses' => $expenses->map(fn (ClientExpense $expense): array => [
                ...app(AgentExpensePresenter::class)->present($expense, $isManager, $isManager),
                'id' => $expense->public_id,
                'spent_on' => $expense->spent_on->toDateString(),
                'amount' => (int) $expense->amount,
                'currency' => (string) $expense->currency,
                'description' => (string) $expense->description,
                'status' => (string) $expense->status,
                'billing' => $isManager ? $this->billingState($workspace, $expense) : null,
                'project' => $expense->project === null ? null : [
                    'id' => $expense->project->public_id,
                    'name' => $expense->project->name,
                ],
                'approved_by' => $expense->approvedBy?->name,
                // `spent_on` is a date and stands on its own; `approved_at` is
                // an instant, and reducing it to a day in UTC reports the
                // adjacent date for an approval made near midnight anywhere
                // else. The workspace's calendar is the one the reader means.
                'approved_at' => $expense->approved_at
                    ?->setTimezone((string) $workspace->timezone)
                    ->toDateString(),
                // The row states what may be done to it, rather than the
                // browser re-deriving the lifecycle from a status string. The
                // rules are the enum's and they are asked here once.
                'can_edit' => $isManager && ExpenseStatus::isEditableValue($expense->status),
                'can_approve' => $isManager && ExpenseStatus::mayTransitionValue($expense->status, ExpenseStatus::Approved),
                'can_unapprove' => $isManager && ExpenseStatus::mayTransitionValue($expense->status, ExpenseStatus::Draft),
                'can_discard' => $isManager && ExpenseStatus::isEditableValue($expense->status),
                'urls' => [
                    'receipts' => $isManager ? route('svc.expenses.receipts', [$workspace, $clientCompany, $expense->public_id], absolute: false) : null,
                    'update' => route('agent-api.v1.expenses.update', [$workspace, $expense->public_id], absolute: false),
                    'approve' => route('agent-api.v1.expenses.approve', [$workspace, $expense->public_id], absolute: false),
                    'unapprove' => route('agent-api.v1.expenses.unapprove', [$workspace, $expense->public_id], absolute: false),
                    'discard' => route('agent-api.v1.expenses.destroy', [$workspace, $expense->public_id], absolute: false),
                ],
            ])->values(),
        ]);
    }

    /** @return array{invoice: array{url: string, number: string}|null, reason: string|null} */
    private function billingState(Workspace $workspace, ClientExpense $expense): array
    {
        if ($expense->client_invoice_line_id !== null) {
            $invoice = $expense->invoiceLine?->invoice;

            return [
                'invoice' => $invoice === null ? null : [
                    'url' => route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false),
                    'number' => $invoice->invoice_number,
                ],
                'reason' => $invoice === null ? 'The invoice allocation needs review.' : null,
            ];
        }

        return ['invoice' => null, 'reason' => match ($expense->status) {
            'draft' => 'Approval is required before this expense can be invoiced.',
            'approved' => 'Awaiting an eligible '.$expense->currency.' invoice covering '.$expense->spent_on->toDateString().' or later.',
            default => 'The expense allocation needs review.',
        }];
    }

    private function expenses(Workspace $workspace): WorkspaceExpenses
    {
        return new WorkspaceExpenses($workspace);
    }
}
