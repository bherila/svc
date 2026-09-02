<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProposal;
use App\Models\ClientProposalItem;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\ClientHome\OperatorClientHomeQuery;
use App\Services\Authorization\BillingRecordAccess;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceStatus;
use App\Support\Engagement\AgreementTermsPayload;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's client company directory.
 *
 * Two read-only screens: a workspace-scoped list of companies with the three
 * figures an operator scans for - how much work is open, how much money is
 * outstanding, and how much of this period's retainer is gone - and a detail
 * screen for one company.
 *
 * Nothing here writes. Every figure is assembled from queries bounded by the
 * workspace and, below it, by the company - never by a child key alone. The
 * schema carries independent foreign keys rather than composite
 * workspace/parent ones, so a row owned by another workspace can name a
 * company visible here, and keying on the company alone serializes it on its
 * parent's authority.
 */
class ClientDirectoryController extends Controller
{
    public function index(Request $request, Workspace $workspace, ProjectAccess $access): Response
    {
        Gate::authorize('view', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $viewable = $access->viewableProjectIds($user, $workspace);

        // The counts are correlated subqueries on the one companies query, so
        // the list costs the same whether the workspace has three companies or
        // three hundred. Each is workspace-scoped as well as company-scoped:
        // `client_company_id` alone would count another workspace's projects
        // and invoices against a company visible here.
        $companies = $workspace->clientCompanies()
            ->withCount([
                // Counted over the projects this viewer reaches, not the
                // company's whole portfolio: a count of 4 beside a detail
                // screen listing 1 says three projects exist that they may not
                // see, which is the disclosure the scoping is closing.
                'projects as project_count' => fn (Builder $query): Builder => $query
                    ->where('workspace_id', $workspace->id)
                    ->when($viewable !== null, fn (Builder $scoped): Builder => $scoped
                        ->whereIn('id', $viewable ?? [])),
                'invoices as draft_invoice_count' => fn (Builder $query): Builder => $query
                    ->where('workspace_id', $workspace->id)
                    ->where('status', InvoiceStatus::Draft->value),
                // "Open" is money still owed, which is issued and partially
                // paid - the vocabulary InvoiceStatus owns, rather than a
                // fourth hand-written list that forgets `partially_paid`.
                'invoices as open_invoice_count' => fn (Builder $query): Builder => $query
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('status', InvoiceStatus::collectible()),
            ])
            ->orderBy('name')
            ->get();

        $companies = $this->reachable($companies, $access->reachableCompanyIds($user, $workspace));
        $retainers = $this->retainerUsage($workspace, $companies);

        return Inertia::render('clients/index', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
            ],
            'companies' => $companies->map(fn (ClientCompany $company): array => [
                'id' => $company->public_id,
                'name' => $company->name,
                'billing_email' => $company->billing_email,
                'is_active' => $company->is_active,
                'project_count' => (int) $company->getAttribute('project_count'),
                'draft_invoice_count' => (int) $company->getAttribute('draft_invoice_count'),
                'open_invoice_count' => (int) $company->getAttribute('open_invoice_count'),
                'retainer' => $retainers[$company->id] ?? null,
            ])->values()->all(),
        ]);
    }

    /**
     * Client Home: this client, at a glance.
     *
     * Bounded on purpose. What this replaced sent every project, every
     * agreement and twenty invoices, so it grew with the engagement - the
     * longer a client had been worked for, the less the screen said. The
     * adapter behind it caps every section and the page links each one to the
     * module that holds the whole history.
     */
    public function show(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
        OperatorClientHomeQuery $home,
    ): Response {
        Gate::authorize('view', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // The company is bound by its public id, which is unique across every
        // workspace. Without this, a member of one workspace opens another's
        // company by pasting its id into a URL whose workspace segment they
        // legitimately pass the gate for.
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));

        return Inertia::render('clients/home', $home->for($workspace, $clientCompany, $user)->toArray());
    }

    /**
     * Every invoice this client has, as a tab of the client.
     *
     * Client Home carries the latest one and links here; this is the full
     * list. Both read the same two keys - the workspace and the company -
     * because `client_company_id` alone would serialize another workspace's
     * invoice on the strength of the company it names.
     */
    public function invoices(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
        BillingRecordAccess $billing,
    ): Response {
        Gate::authorize('view', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        // Same reason as `show()`: the company binds by a public id unique
        // across every workspace, so passing the workspace gate is not passing
        // a check on the company reached through it.
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $viewable = $access->viewableProjectIds($user, $workspace);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));

        $invoices = $billing->constrainInvoices(
            ClientInvoice::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id),
            $user,
            $workspace,
        )->orderByDesc('issue_date')->orderByDesc('id')->get();

        return Inertia::render('clients/invoices', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            // Where a row links. Sent rather than assembled in the browser
            // because the client's own copy of this screen is a different route
            // family entirely, and the page is the same page.
            'invoice_base_href' => route('clients.invoices', [$workspace, $clientCompany], absolute: false),
            'invoices' => $invoices->map(
                fn (ClientInvoice $invoice): array => $this->invoicePayload($invoice),
            )->values()->all(),
        ]);
    }

    /**
     * One invoice, inside the client it belongs to.
     *
     * Reached as a child of the Invoices tab rather than from a workspace-wide
     * route, so the chrome keeps saying which client this is and the back path
     * is the tab rather than a list the operator never opened.
     *
     * Three keys, not one. The invoice is bound by a public id unique across
     * every workspace, so it is checked against both the workspace and the
     * company in the URL - otherwise a member of one client's screens opens
     * another client's invoice by pasting its id, and the chrome would
     * cheerfully label it with the company they came from.
     */
    public function invoice(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientInvoice $clientInvoice,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
        BillingRecordAccess $billing,
    ): Response {
        Gate::authorize('view', $workspace);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $viewable = $access->viewableProjectIds($user, $workspace);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));
        $authorization->assertOwnedBy($workspace, $clientInvoice);

        abort_unless(
            (int) $clientInvoice->client_company_id === (int) $clientCompany->id,
            404,
        );

        abort_unless($billing->canViewInvoice($user, $workspace, $clientInvoice), 404);

        $clientInvoice->load([
            'lines' => fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->orderBy('sort_order')
                ->orderBy('id'),
            'payments' => fn ($query) => $query
                ->where('workspace_id', $workspace->id)
                ->orderByDesc('received_on')
                ->orderByDesc('id'),
        ]);

        // The lifecycle actions, where the invoice is - rather than on a
        // workspace-wide screen the operator had to leave the client to reach.
        // Each is offered only where the invoice's own status admits it, and
        // each authorizes again on the way in: an action nobody rendered is
        // not an authorization check, and a status nobody rendered is not a
        // state machine.
        $manages = Gate::forUser($user)->allows('manage', $workspace);
        $status = (string) $clientInvoice->status;
        $base = "/workspaces/{$workspace->public_id}/invoices/{$clientInvoice->public_id}";

        return Inertia::render('clients/invoice', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'invoices_href' => route('clients.invoices', [$workspace, $clientCompany], absolute: false),
            'pdf_href' => $base.'/pdf',
            'actions' => [
                'issue' => $manages && $status === InvoiceStatus::Draft->value ? $base.'/issue' : null,
                'send' => $manages && in_array($status, InvoiceStatus::collectible(), true)
                    ? $base.'/send'
                    : null,
                'payment' => $manages && in_array($status, InvoiceStatus::collectible(), true)
                    ? $base.'/payments'
                    : null,
                // Voiding a paid invoice is a correction the service refuses,
                // so it is not offered either.
                'void' => $manages && in_array($status, [
                    InvoiceStatus::Draft->value,
                    ...InvoiceStatus::collectible(),
                ], true) ? $base.'/void' : null,
            ],
            'invoice' => $this->invoicePayload($clientInvoice),
            'lines' => $clientInvoice->lines->map(fn (ClientInvoiceLine $line): array => [
                'id' => $line->public_id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'hours' => $line->hours === null ? null : (float) $line->hours,
                'line_date' => $line->line_date?->toDateString(),
                'unit_amount' => (int) $line->unit_amount,
                'total_amount' => (int) $line->total_amount,
            ])->values()->all(),
            'payments' => $clientInvoice->payments->map(fn (ClientInvoicePayment $payment): array => [
                'id' => $payment->public_id,
                'status' => $payment->status,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'received_on' => $payment->received_on?->toDateString(),
                'amount' => (int) $payment->amount,
                'refunded_amount' => (int) $payment->refunded_amount,
                'currency' => $payment->currency,
            ])->values()->all(),
        ]);
    }

    /**
     * One agreement's full terms, reached from the Overview that summarises it.
     *
     * Overview answers "what has this client agreed to" in a line each; this
     * answers "what exactly does this one say", including the recurring items
     * that generate invoice lines and the rollover and catch-up terms the
     * summary has no room for.
     *
     * Marked as the Overview tab rather than a tab of its own, because it is a
     * drill-down from there - an agreement is part of what the engagement is,
     * not a fifth section of the client.
     *
     * Three keys as usual: the agreement binds by a public id unique across
     * every workspace, so it is checked against the workspace and against the
     * company in the URL, or one client's terms render under another client's
     * name.
     */
    public function agreement(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientAgreement $clientAgreement,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
        BillingRecordAccess $billing,
    ): Response {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $authorization->assertOwnedBy($workspace, $clientAgreement);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $viewable = $access->viewableProjectIds($user, $workspace);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));

        abort_unless(
            (int) $clientAgreement->client_company_id === (int) $clientCompany->id,
            404,
        );

        abort_unless($billing->canViewAgreement($user, $workspace, $clientAgreement), 404);

        $projectNames = $this->viewableProjectsOf($workspace, $clientCompany, $viewable)
            ->pluck('name', 'id');

        $items = ClientAgreementRecurringItem::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $clientAgreement->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return Inertia::render('clients/agreement', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'home_href' => route('clients.show', [$workspace, $clientCompany], absolute: false),
            // The engine's behaviour when a term is unstated is an operator's
            // concern. The same page serves the client's portal, which reads
            // the commercial terms and not these.
            'audience' => 'operator',
            'agreement' => $this->agreementPayload($clientAgreement, $projectNames) + [
                // Terms the summary has no room for. Hours rather than minutes
                // where the operator reads hours, and null rather than zero
                // where the term is simply unstated - the difference decides
                // whether the engine defaults or refuses.
                'hourly_rate_amount' => $clientAgreement->hourly_rate_amount === null
                    ? null
                    : (int) $clientAgreement->hourly_rate_amount,
                'rollover_policy' => $clientAgreement->rollover_policy,
                'catch_up_threshold_minutes' => $clientAgreement->catch_up_threshold_minutes === null
                    ? null
                    : (int) $clientAgreement->catch_up_threshold_minutes,
                'first_cycle_proration' => $clientAgreement->first_cycle_proration,
                'bill_overage_interim' => $clientAgreement->bill_overage_interim,
                'activated_at' => $clientAgreement->activated_at?->toISOString(),
                'terminated_at' => $clientAgreement->terminated_at?->toISOString(),
                'signer_name' => $clientAgreement->signer_name,
                'signer_title' => $clientAgreement->signer_title,
            ],
            'recurring_items' => $items->map(fn (ClientAgreementRecurringItem $item): array => [
                'id' => $item->public_id,
                'description' => $item->description,
                'cadence' => $item->cadence,
                'quantity' => $item->quantity === null ? null : (float) $item->quantity,
                'amount' => $item->amount === null ? null : (int) $item->amount,
                'currency' => $item->currency,
                'effective_on' => $item->effective_on?->toDateString(),
                'expires_on' => $item->expires_on?->toDateString(),
                'is_active' => (bool) $item->is_active,
            ])->values()->all(),
        ]);
    }

    /**
     * One project: what it is, what is open on it, and what it has cost.
     *
     * A drill-down from the Overview that lists it, so it is marked as that
     * tab rather than becoming a section of its own.
     *
     * Gated on project access rather than only on reaching the client. A
     * member who reaches one project of a client must not read another's
     * description and task list by id - which is the whole point of #157, one
     * level further in.
     *
     * The time figures are aggregates rather than rows. A project can carry a
     * year of entries, and this screen answers "how much" rather than "which";
     * the Time tab answers the second question and is one click away.
     */
    /**
     * One proposal, for the operator who sent it.
     *
     * Read-only here. Acceptance is the client's act and lives on their copy of
     * this page; an operator accepting on their behalf is not a shortcut, it is
     * a signature nobody gave.
     */
    public function proposal(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientProposal $clientProposal,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
    ): Response {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $authorization->assertOwnedBy($workspace, $clientProposal);

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));

        abort_unless((int) $clientProposal->client_company_id === (int) $clientCompany->id, 404);

        // A proposal scoped to a project is not readable by someone who does
        // not hold that project, for the same reason its rates are not.
        $viewable = $access->viewableProjectIds($user, $workspace);
        abort_if(
            $viewable !== null
                && $clientProposal->client_project_id !== null
                && ! in_array((int) $clientProposal->client_project_id, $viewable, true),
            404,
        );

        return Inertia::render('clients/proposal', $this->proposalPayload($clientProposal) + [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'home_href' => route('clients.show', [$workspace, $clientCompany], absolute: false),
            'accept_href' => null,
        ]);
    }

    public function project(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientProject $clientProject,
        WorkspaceAuthorization $authorization,
        ProjectAccess $access,
        BillingRecordAccess $billing,
    ): Response {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $authorization->assertOwnedBy($workspace, $clientProject);

        $user = $request->user();
        abort_unless($user instanceof User, 401);

        abort_unless(
            (int) $clientProject->client_company_id === (int) $clientCompany->id,
            404,
        );

        // Reaching the client is not reaching this project.
        abort_unless($access->canView($user, $clientProject), 404);

        $tasks = ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_project_id', $clientProject->id)
            ->orderBy('status')
            ->orderBy('title')
            ->get();

        // One grouped query rather than one per status, so the cost does not
        // follow the number of statuses anyone invents later.
        $minutesByStatus = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_project_id', $clientProject->id)
            ->groupBy('status')
            ->selectRaw('status, sum(minutes) as total_minutes, count(*) as entry_count')
            ->get();

        $agreements = $billing->constrainAgreements(
            ClientAgreement::query()
                ->where('workspace_id', $workspace->id)
                ->where('client_company_id', $clientCompany->id)
                ->where('client_project_id', $clientProject->id),
            $user,
            $workspace,
        )->orderByDesc('starts_on')->orderByDesc('id')->get();

        $projectNames = collect([$clientProject->id => $clientProject->name]);

        return Inertia::render('clients/project', [
            'workspace' => ['id' => $workspace->public_id],
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            'project' => [
                'id' => $clientProject->public_id,
                'name' => $clientProject->name,
                'description' => $clientProject->description,
                'status' => $clientProject->status,
                'is_visible_to_client' => (bool) $clientProject->is_visible_to_client,
            ],
            'tasks' => $tasks->map(fn (ClientTask $task): array => [
                'id' => $task->public_id,
                'title' => $task->title,
                'status' => $task->status,
                'is_visible_to_client' => (bool) $task->is_visible_to_client,
                'completed_at' => $task->completed_at?->toDateString(),
            ])->values()->all(),
            'time' => $minutesByStatus->map(fn (ClientTimeEntry $row): array => [
                'status' => (string) $row->status,
                'minutes' => (int) $row->getAttribute('total_minutes'),
                'entries' => (int) $row->getAttribute('entry_count'),
            ])->values()->all(),
            // Only agreements scoped to this project. A company-wide agreement
            // covers it too, but saying so here would read as this project
            // having terms of its own.
            'agreements' => $agreements->map(
                fn (ClientAgreement $agreement): array => $this->agreementPayload($agreement, $projectNames),
            )->values()->all(),
        ]);
    }

    /**
     * The client record itself, and the shape of its projects.
     *
     * Manage is a tab rather than a parallel admin section, so it is reached
     * the same way every other tab is and carries the same chrome. It is gated
     * on the workspace `manage` ability - the tab does not appear without it,
     * and this refuses even if someone types the URL, because a hidden link is
     * not an authorization check.
     *
     * Projects are listed unscoped by project access on purpose: someone who
     * may manage the workspace may manage all of it, and a manage screen that
     * hid half the projects would let an operator create a duplicate of one
     * they cannot see.
     */
    public function manage(
        Request $request,
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): Response {
        Gate::authorize('manage', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderBy('name')
            ->get();

        // One query for every membership on these projects, grouped in memory.
        // Asking per project would cost a query per row on a screen whose
        // whole job is listing rows.
        $memberships = ClientProjectMembership::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_project_id', $projects->pluck('id')->all())
            ->get()
            ->groupBy('client_project_id');

        $members = $workspace->memberships()->with('user')->get();

        $assignableIds = $members
            ->filter(fn (mixed $membership): bool => ! in_array(
                (string) $membership->role,
                ['owner', 'admin'],
                true,
            ))
            ->pluck('user_id')
            ->all();

        // Keyed to the assignable set, so a membership row for anyone else has
        // no public id to be serialized with.
        $userIds = User::query()
            ->whereIn('id', $assignableIds)
            ->pluck('public_id', 'id');

        $assignable = $members
            ->filter(fn (mixed $membership): bool => in_array(
                (int) $membership->user_id,
                array_map('intval', $assignableIds),
                true,
            ))
            ->map(fn (mixed $membership): ?User => $membership->user)
            ->filter(fn (?User $member): bool => $member instanceof User);

        return Inertia::render('clients/settings', [
            'workspace' => ['id' => $workspace->public_id],
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
                'billing_email' => $clientCompany->billing_email,
                'is_active' => (bool) $clientCompany->is_active,
            ],
            'projects' => $projects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id,
                'name' => $project->name,
                'description' => $project->description,
                'status' => $project->status,
                'is_visible_to_client' => (bool) $project->is_visible_to_client,
                // The version this form is rendered from, sent back on save so
                // the write can refuse a payload composed against values
                // someone has since changed.
                'lock_version' => (int) $project->lock_version,
                // Who reaches this project, and with what role. Sent per
                // project rather than as one map because that is how the screen
                // reads it, and because an owner or admin holds no membership
                // row at all - they are listed separately below so the absence
                // does not read as "no access".
                //
                // Only rows for people the screen can actually offer. An
                // owner or admin can hold a membership row - written before a
                // promotion, or by the console command - and sending it would
                // disclose an identity the `assignable` list deliberately
                // omits, to no purpose, since the page correlates against that
                // list and would never render it.
                'members' => $memberships->get($project->id, collect())
                    ->filter(fn (ClientProjectMembership $membership): bool => isset($userIds[$membership->user_id]))
                    ->map(fn (ClientProjectMembership $membership): array => [
                        'user' => (string) ($userIds[$membership->user_id] ?? ''),
                        // The column is cast to the enum, so this is its value
                        // rather than a cast of the object.
                        'role' => $membership->role->value,
                    ])->values()->all(),
            ])->values()->all(),
            // Workspace members who can be given project access. Owners and
            // admins are excluded: they already reach every project, so
            // offering to grant them one would imply the grant does something.
            'assignable' => $assignable->map(fn (User $member): array => [
                'id' => $member->public_id,
                'name' => $member->name,
            ])->values()->all(),
        ]);
    }

    /**
     * The client's tasks, by project.
     *
     * Tasks carry no company key - only `workspace_id` and
     * `client_project_id` - so the company is reached through its projects,
     * and the tasks are then bounded by the workspace *and* by that project
     * set. Keying on the project alone would serialize another workspace's
     * task on the strength of a project visible here, which is the chain
     * rather than the key.
     *
     * This screen also applies per-project access, which its siblings on this
     * controller do not. That is deliberate rather than inconsistent: a task
     * carries a title and a description written for whoever is doing the work,
     * and membership of a workspace is not access to every project in it. The
     * time sheet already refuses to show a scoped member other projects' work
     * for the same reason.
     */
    public function tasks(
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

        $viewable = $access->viewableProjectIds($user, $workspace);
        $this->assertReachable($clientCompany, $access->reachableCompanyIds($user, $workspace));

        $projects = $this->viewableProjectsOf($workspace, $clientCompany, $viewable);

        // A stale or unreachable project falls back to every visible one,
        // rather than to an empty list that reads as "this client has no
        // tasks".
        $requested = $request->query('project');
        $selected = is_string($requested) && $requested !== ''
            ? $projects->firstWhere('public_id', $requested)
            : null;

        $scope = $selected === null ? $projects : collect([$selected]);

        $tasks = ClientTask::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_project_id', $scope->pluck('id')->all())
            ->orderBy('status')
            ->orderBy('title')
            ->get();

        $projectNames = $projects->pluck('name', 'id');

        return Inertia::render('clients/tasks', [
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
            // Whether the "client sees" column appears. It is a statement about
            // disclosure, which is meaningless on the copy of this screen the
            // client is reading.
            'audience' => 'operator',
            'filters' => [
                'project_id' => $selected?->public_id,
            ],
            'projects' => $projects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id,
                'name' => $project->name,
            ])->values()->all(),
            'tasks' => $tasks->map(fn (ClientTask $task): array => [
                'id' => $task->public_id,
                'title' => $task->title,
                'status' => $task->status,
                'project' => $projectNames[$task->client_project_id] ?? null,
                'is_visible_to_client' => (bool) $task->is_visible_to_client,
                'completed_at' => $task->completed_at?->toDateString(),
            ])->values()->all(),
        ]);
    }

    /**
     * This company's projects, narrowed to the ones the viewer may see.
     *
     * Takes the already-resolved id set rather than asking `ProjectAccess` per
     * project, for the reason on {@see ProjectAccess::viewableProjectIds()}.
     *
     * @param  list<int>|null  $viewableProjectIds
     * @return EloquentCollection<int, ClientProject>
     */
    private function viewableProjectsOf(
        Workspace $workspace,
        ClientCompany $company,
        ?array $viewableProjectIds,
    ): EloquentCollection {
        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->when($viewableProjectIds !== null, fn (Builder $query): Builder => $query
                ->whereIn('id', $viewableProjectIds ?? []))
            ->orderBy('name')
            ->get();

        return $projects;
    }

    /**
     * Which companies this viewer has any business with.
     *
     * A company they cannot reach is absent rather than empty. Rendering it
     * with nothing in it would still disclose the client's name and existence,
     * which is the disclosure being scoped in the first place.
     *
     * @param  EloquentCollection<int, ClientCompany>  $companies
     * @param  list<int>|null  $reachableCompanyIds
     * @return EloquentCollection<int, ClientCompany>
     */
    private function reachable(
        EloquentCollection $companies,
        ?array $reachableCompanyIds,
    ): EloquentCollection {
        if ($reachableCompanyIds === null) {
            return $companies;
        }

        return new EloquentCollection($companies->filter(
            fn (ClientCompany $company): bool => in_array((int) $company->id, $reachableCompanyIds, true),
        )->values()->all());
    }

    /**
     * Refuse a company this viewer reaches no project of.
     *
     * The list already omits it, so a direct URL has to agree - otherwise the
     * scoping is decorative and the id is the only thing in the way.
     *
     * @param  list<int>|null  $reachableCompanyIds
     */
    private function assertReachable(
        ClientCompany $company,
        ?array $reachableCompanyIds,
    ): void {
        abort_if(
            $reachableCompanyIds !== null
                && ! in_array((int) $company->id, $reachableCompanyIds, true),
            404,
        );
    }

    /**
     * The proposal itself, without the keys the model already hides.
     *
     * Shared with the client's copy of this screen, so the two cannot describe
     * the same document differently - which, on a page whose whole purpose is a
     * signature, would be a signature given to something other than what was
     * shown.
     *
     * @return array{proposal: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function proposalPayload(ClientProposal $proposal): array
    {
        $items = $proposal->items()
            ->where('workspace_id', $proposal->workspace_id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $proposal->setRelation('items', $items);

        return [
            'proposal' => [
                'id' => $proposal->public_id,
                'title' => $proposal->title,
                'summary' => $proposal->summary,
                'terms' => $proposal->terms,
                'status' => $proposal->status,
                'currency' => $proposal->currency,
                'valid_until' => $proposal->valid_until?->toDateString(),
                'sent_at' => $proposal->sent_at?->toISOString(),
                'accepted_at' => $proposal->accepted_at?->toISOString(),
                'total_amount' => $proposal->totalAmount(),
            ],
            'items' => array_values($items->map(fn (ClientProposalItem $item): array => [
                'id' => $item->public_id,
                'description' => $item->description,
                // A string rather than a float: quantities are decimals in the
                // column, and rendering 1.5 through a float is how 0.1 + 0.2
                // reaches an invoice.
                'quantity' => (string) $item->quantity,
                'unit_amount' => (int) $item->unit_amount,
                'cadence' => $item->cadence,
            ])->all()),
        ];
    }

    /**
     * One invoice as both screens send it.
     *
     * Shared so Client Home's latest invoice and the Invoices tab cannot
     * disagree about a field, and so a column added for one appears on the
     * other rather than only where someone remembered.
     *
     * @return array<string, mixed>
     */
    private function invoicePayload(ClientInvoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'total_amount' => (int) $invoice->total_amount,
            'paid_amount' => (int) $invoice->paid_amount,
            'balance_amount' => (int) $invoice->balance_amount,
        ];
    }

    /**
     * @param  Collection<int, string>  $projectNames  project id => name, for this company only
     * @return array<string, mixed>
     */
    private function agreementPayload(ClientAgreement $agreement, Collection $projectNames): array
    {
        return AgreementTermsPayload::for(
            $agreement,
            // Resolved from the projects already read for this company rather
            // than looked up from the id on the row, so an agreement pointing
            // outside what the viewer holds renders as unscoped instead of
            // disclosing a project name they cannot otherwise see.
            $agreement->client_project_id === null
                ? null
                : $projectNames->get((int) $agreement->client_project_id),
        );
    }

    /**
     * Does this agreement grant retainer capacity on a repeating cycle?
     *
     * The same pair of conditions the time sheet's capacity strip applies: an
     * hourly-only agreement has no capacity to report, and a one-time one has
     * no cycle to report it for.
     */
    private function grantsRecurringRetainer(ClientAgreement $agreement): bool
    {
        return $agreement->billsOnARecurringCadence()
            && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null);
    }

    /**
     * This period's retainer draw for every company that has one, keyed by
     * company id.
     *
     * Deliberately not one ledger per company: `InvoiceLedgerBuilder` is a
     * running balance built from an agreement's start, and asking it once per
     * row would make the list's cost grow with the workspace. What this reports
     * is narrower and says so - the hours booked inside the current cycle
     * against the capacity that cycle sells, with no rollover carried in.
     *
     * Approved work only, which is what the ledger counts. Draft hours are not
     * a draw on anything yet, and folding them in here would disagree with
     * every figure on the time sheet.
     *
     * @param  EloquentCollection<int, ClientCompany>  $companies
     * @return array<int, array<string, mixed>>
     */
    private function retainerUsage(Workspace $workspace, EloquentCollection $companies): array
    {
        if ($companies->isEmpty()) {
            return [];
        }

        // The workspace's calendar decides which cycle "now" falls in, not the
        // server's, and a cycle boundary is exactly where the two disagree.
        $today = CarbonImmutable::now($workspace->timezone);

        $companyIds = [];

        foreach ($companies as $company) {
            $companyIds[] = $company->id;
        }

        $agreements = $this->activeAgreements($workspace, $companyIds, $today);

        if ($agreements === []) {
            return [];
        }

        // Project ids, per company, for the companies that have a retainer.
        // Two things need them: an agreement scoped to one project draws only
        // on that project's time, and - for every agreement - a time entry
        // names its company and its project through independent keys, so an
        // entry filed under this company while pointing at another company's
        // project would otherwise add its hours to a total published here.
        $retainerProjects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', array_keys($agreements))
            ->get(['id', 'client_company_id']);

        $projectIdsByCompany = [];

        foreach ($retainerProjects as $project) {
            $projectIdsByCompany[$project->client_company_id][] = $project->id;
        }

        $windows = [];
        $periods = [];

        foreach ($agreements as $companyId => $agreement) {
            $companyProjectIds = $projectIdsByCompany[$companyId] ?? [];

            if ($agreement->client_project_id !== null) {
                // Intersected, not substituted: an agreement naming a project
                // of another company or another workspace scopes to nothing
                // rather than to that project's hours.
                $companyProjectIds = array_values(array_intersect(
                    $companyProjectIds,
                    [(int) $agreement->client_project_id],
                ));
            }

            $period = $this->currentCycle($agreement, $today);

            $periods[$companyId] = $period;

            if ($companyProjectIds === []) {
                continue;
            }

            $windows[$companyId] = [
                'start' => $period['start'],
                'end' => $period['end'],
                'projects' => $companyProjectIds,
            ];
        }

        $usedByCompany = $this->usedMinutes($workspace, $windows);
        $usage = [];

        foreach ($agreements as $companyId => $agreement) {
            $period = $periods[$companyId];
            $capacity = (int) round($agreement->periodRetainerHours() * 60);
            $used = $usedByCompany[$companyId] ?? 0;

            $usage[$companyId] = [
                'agreement' => (string) $agreement->title,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'capacity_minutes' => $capacity,
                'used_minutes' => $used,
                'remaining_minutes' => max(0, $capacity - $used),
                'over_minutes' => max(0, $used - $capacity),
            ];
        }

        return $usage;
    }

    /**
     * The agreement in force today for each company, keyed by company id.
     *
     * {@see ClientCompany::activeAgreement()} answers this one company at a
     * time, which is a query per row. This asks it once for the whole list and
     * repeats that method's ordering exactly, so the list and the company's own
     * screens cannot disagree about which agreement is in force.
     *
     * @param  list<int>  $companyIds
     * @return array<int, ClientAgreement>
     */
    private function activeAgreements(Workspace $workspace, array $companyIds, CarbonImmutable $today): array
    {
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('client_company_id', $companyIds)
            ->where('status', 'active')
            // `starts_on` is `NOT NULL` (#147); no null branch to take.
            ->where('starts_on', '<=', $today->toDateString())
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('ends_on')
                ->orWhere('ends_on', '>=', $today->toDateString()))
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $byCompany = [];

        foreach ($agreements as $agreement) {
            $companyId = (int) $agreement->client_company_id;

            if (isset($byCompany[$companyId]) || ! $this->grantsRecurringRetainer($agreement)) {
                continue;
            }

            $byCompany[$companyId] = $agreement;
        }

        return $byCompany;
    }

    /**
     * The cycle containing today, clipped to the agreement's own term.
     *
     * `BillingCadence` aligns cycles to the calendar, so this is the same
     * window the cadence groups invoices into. Clipping matters at both ends:
     * work booked before the agreement started never drew on it, and a period
     * running past `ends_on` would advertise capacity the term no longer sells.
     *
     * @return array{start: string, end: string}
     */
    private function currentCycle(ClientAgreement $agreement, CarbonImmutable $today): array
    {
        $cadence = $agreement->effectiveBillingCadence();
        $start = CarbonImmutable::instance($cadence->cycleStart($today));
        $end = CarbonImmutable::instance($cadence->cycleEnd($today));

        if ($agreement->starts_on->gt($start)) {
            $start = $agreement->starts_on;
        }

        if ($agreement->ends_on !== null && $agreement->ends_on->lt($end)) {
            $end = $agreement->ends_on;
        }

        return ['start' => $start->toDateString(), 'end' => $end->toDateString()];
    }

    /**
     * Approved retainer-drawing minutes inside each company's window.
     *
     * One query however many companies are on the list. The per-company
     * predicate names the company, its own projects and its own window
     * together, so nothing reaches a total on the strength of a single key.
     *
     * @param  array<int, array{start: string, end: string, projects: list<int>}>  $windows
     * @return array<int, int>
     */
    private function usedMinutes(Workspace $workspace, array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        $rows = ClientTimeEntry::query()
            ->retainerBillable()
            ->deferredOnlyOnceAllocated()
            ->where('client_time_entries.workspace_id', $workspace->id)
            ->where(function (Builder $query) use ($windows): void {
                foreach ($windows as $companyId => $window) {
                    $query->orWhere(fn (Builder $scoped): Builder => $scoped
                        ->where('client_time_entries.client_company_id', $companyId)
                        ->whereIn('client_time_entries.client_project_id', $window['projects'])
                        ->where('client_time_entries.worked_on', '>=', $window['start'])
                        ->where('client_time_entries.worked_on', '<=', $window['end']));
                }
            })
            ->groupBy('client_time_entries.client_company_id')
            ->selectRaw('client_time_entries.client_company_id as company_id, SUM(client_time_entries.minutes) as used_minutes')
            ->get();

        $used = [];

        foreach ($rows as $row) {
            $used[(int) $row->getAttribute('company_id')] = (int) $row->getAttribute('used_minutes');
        }

        return $used;
    }
}
