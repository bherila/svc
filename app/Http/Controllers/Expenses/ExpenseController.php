<?php

namespace App\Http\Controllers\Expenses;

use App\Exceptions\ExpenseTransitionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\ExpenseFactsRequest;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\Expenses\ExpenseStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The manager's expense surface for one client.
 *
 * Every read and write goes through {@see WorkspaceExpenses}, which is
 * constructed with the workspace and cannot be widened afterwards. That is
 * deliberate and it is why this controller is thin: the tenancy rule, the
 * lifecycle rules and the locking all live in the query object, so a second
 * surface - the invoicing hook, a recurrence - inherits them rather than
 * re-deriving them from whatever this controller happened to do.
 *
 * ## Why the writes refuse rather than validate first
 *
 * The lifecycle guards re-read the row under its own lock and decide from the
 * locked copy, so this controller cannot usefully pre-check a status: between
 * its check and the write, the other manager looking at the same list presses
 * approve. So the refusals arrive as exceptions and are rendered as validation
 * errors on the screen the operator is already looking at, carrying the status
 * the row is *now* - which is what tells them to re-read rather than retry.
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
            ])
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();

        $isManager = Gate::allows('manage', $workspace);

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
            'urls' => [
                'store' => route('svc.expenses.store', [$workspace, $clientCompany], absolute: false),
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
                'id' => $expense->public_id,
                'spent_on' => $expense->spent_on->toDateString(),
                'amount' => (int) $expense->amount,
                'currency' => (string) $expense->currency,
                'description' => (string) $expense->description,
                'status' => (string) $expense->status,
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
                'can_discard' => $isManager && ! ExpenseStatus::hasBeenInvoicedValue($expense->status),
                'urls' => [
                    'update' => route('svc.expenses.update', [$workspace, $expense->public_id], absolute: false),
                    'approve' => route('svc.expenses.approve', [$workspace, $expense->public_id], absolute: false),
                    'unapprove' => route('svc.expenses.unapprove', [$workspace, $expense->public_id], absolute: false),
                    'discard' => route('svc.expenses.destroy', [$workspace, $expense->public_id], absolute: false),
                ],
            ])->values(),
        ]);
    }

    public function store(
        ExpenseFactsRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        // The recorder is stamped on the row, so it has to be a person. An
        // agent principal reaches its own API rather than this form, and
        // writing null here instead would leave an expense nobody recorded.
        $recordedBy = $request->user();
        abort_unless($recordedBy instanceof User, 401);

        $this->expenses($workspace)->record(
            $clientCompany,
            $this->project($request, $workspace, $clientCompany),
            $request->facts(),
            $recordedBy,
        );

        return redirect()->back()->with('status', 'Expense recorded.');
    }

    public function update(
        ExpenseFactsRequest $request,
        Workspace $workspace,
        string $expense,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        return $this->refusable(function () use ($request, $workspace, $expense): void {
            $record = $this->find($workspace, $expense);
            // Attribution travels separately from the facts and only when the
            // form actually submitted it. Passing it unconditionally would
            // clear the project of any caller that edited only the money.
            $reattribute = $request->has('project_id');

            $this->expenses($workspace)->update(
                $record,
                $request->facts(),
                $reattribute
                    ? $this->project($request, $workspace, $record->clientCompany)
                    : null,
                $reattribute,
            );
        });
    }

    public function approve(
        Request $request,
        Workspace $workspace,
        string $expense,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $this->refusable(function () use ($workspace, $expense, $user): void {
            $this->expenses($workspace)->approve($this->find($workspace, $expense), $user);
        }, 'Expense approved.');
    }

    public function unapprove(
        Workspace $workspace,
        string $expense,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        return $this->refusable(function () use ($workspace, $expense): void {
            $this->expenses($workspace)->unapprove($this->find($workspace, $expense));
        }, 'Expense returned to draft.');
    }

    public function destroy(
        Workspace $workspace,
        string $expense,
    ): RedirectResponse {
        Gate::authorize('manage', $workspace);

        return $this->refusable(function () use ($workspace, $expense): void {
            $this->expenses($workspace)->discard($this->find($workspace, $expense));
        }, 'Expense discarded.');
    }

    /**
     * Run a lifecycle move, turning a refusal into a message on the screen.
     *
     * A refusal here is very often not a bug: two managers on the same list,
     * both pressing approve, and the second one loses. A 500 tells that
     * operator nothing; the exception's own message names the status the row
     * holds now, which is what tells them to re-read rather than retry.
     *
     * @param  callable(): void  $move
     */
    private function refusable(callable $move, string $success = 'Expense updated.'): RedirectResponse
    {
        try {
            $move();
        } catch (ExpenseTransitionRefused $refusal) {
            throw ValidationException::withMessages(['expense' => $refusal->getMessage()]);
        }

        return redirect()->back()->with('status', $success);
    }

    /**
     * The expense this request names, or a 404.
     *
     * Resolved through the workspace-scoped finder rather than by route model
     * binding, because binding resolves on the id alone and would hand this
     * controller another tenant's row to then check. `find()` answers null for
     * both "no such expense" and "another tenant's", which is the same answer a
     * caller must get for both.
     */
    private function find(Workspace $workspace, string $publicId): ClientExpense
    {
        $expense = $this->expenses($workspace)->find($publicId);

        abort_unless($expense instanceof ClientExpense, 404);

        return $expense;
    }

    /**
     * The project this request attributes the expense to, if any.
     *
     * Resolved against the company as well as the workspace. `WorkspaceExpenses`
     * checks both again before writing - that is its job and it keeps the rule
     * where every caller inherits it - but resolving a public id here means an
     * unknown one is a validation message on the form rather than a refusal
     * from two layers down.
     */
    private function project(
        ExpenseFactsRequest $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
    ): ?ClientProject {
        $publicId = $request->validated('project_id');

        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $project = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->where('public_id', $publicId)
            ->first();

        if (! $project instanceof ClientProject) {
            throw ValidationException::withMessages([
                'project_id' => 'That project does not belong to this client.',
            ]);
        }

        return $project;
    }

    private function expenses(Workspace $workspace): WorkspaceExpenses
    {
        return new WorkspaceExpenses($workspace);
    }
}
