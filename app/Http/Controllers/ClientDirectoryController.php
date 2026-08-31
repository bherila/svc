<?php

namespace App\Http\Controllers;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceStatus;
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
    /** How many invoices the detail screen shows before it stops. */
    private const RECENT_INVOICES = 20;

    public function index(Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        // The counts are correlated subqueries on the one companies query, so
        // the list costs the same whether the workspace has three companies or
        // three hundred. Each is workspace-scoped as well as company-scoped:
        // `client_company_id` alone would count another workspace's projects
        // and invoices against a company visible here.
        $companies = $workspace->clientCompanies()
            ->withCount([
                'projects as project_count' => fn (Builder $query): Builder => $query
                    ->where('workspace_id', $workspace->id),
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

    public function show(
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): Response {
        Gate::authorize('view', $workspace);
        // The company is bound by its public id, which is unique across every
        // workspace. Without this, a member of one workspace opens another's
        // company by pasting its id into a URL whose workspace segment they
        // legitimately pass the gate for.
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderBy('name')
            ->get();

        $agreements = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->limit(self::RECENT_INVOICES)
            ->get();

        // An agreement may be scoped to a single project, and it names that
        // project by an independent key. Resolving the name from the projects
        // already read for this company - rather than loading it from the id -
        // means a row pointing outside the company renders as unscoped instead
        // of disclosing a project name from somewhere the reader cannot see.
        $projectNames = $projects->pluck('name', 'id');

        return Inertia::render('clients/show', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
            ],
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
                'billing_email' => $clientCompany->billing_email,
                'is_active' => $clientCompany->is_active,
            ],
            'projects' => $projects->map(fn (ClientProject $project): array => [
                'id' => $project->public_id,
                'name' => $project->name,
                'status' => $project->status,
                'is_visible_to_client' => $project->is_visible_to_client,
            ])->values()->all(),
            'agreements' => $agreements->map(
                fn (ClientAgreement $agreement): array => $this->agreementPayload($agreement, $projectNames),
            )->values()->all(),
            'invoice_limit' => self::RECENT_INVOICES,
            'invoices' => $invoices->map(
                fn (ClientInvoice $invoice): array => $this->invoicePayload($invoice),
            )->values()->all(),
        ]);
    }

    /**
     * Every invoice this client has, as a tab of the client.
     *
     * Overview shows the most recent {@see self::RECENT_INVOICES} and links
     * here; this is the unbounded list. Both read the same two keys - the
     * workspace and the company - because `client_company_id` alone would
     * serialize another workspace's invoice on the strength of the company it
     * names, and both render through the same table component so the rows
     * cannot drift apart.
     */
    public function invoices(
        Workspace $workspace,
        ClientCompany $clientCompany,
        WorkspaceAuthorization $authorization,
    ): Response {
        Gate::authorize('view', $workspace);
        // Same reason as `show()`: the company binds by a public id unique
        // across every workspace, so passing the workspace gate is not passing
        // a check on the company reached through it.
        $authorization->assertOwnedBy($workspace, $clientCompany);

        $invoices = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderByDesc('issue_date')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('clients/invoices', [
            'workspace' => ['id' => $workspace->public_id],
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
            ],
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
        Workspace $workspace,
        ClientCompany $clientCompany,
        ClientInvoice $clientInvoice,
        WorkspaceAuthorization $authorization,
    ): Response {
        Gate::authorize('view', $workspace);
        $authorization->assertOwnedBy($workspace, $clientCompany);
        $authorization->assertOwnedBy($workspace, $clientInvoice);

        abort_unless(
            (int) $clientInvoice->client_company_id === (int) $clientCompany->id,
            404,
        );

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

        return Inertia::render('clients/invoice', [
            'workspace' => ['id' => $workspace->public_id],
            'company' => [
                'id' => $clientCompany->public_id,
                'name' => $clientCompany->name,
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

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $clientCompany->id)
            ->orderBy('name')
            ->get()
            ->filter(fn (ClientProject $project): bool => $access->canView($user, $project))
            ->values();

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
     * One invoice as both screens send it.
     *
     * Shared so Overview's recent list and the Invoices tab cannot disagree
     * about a field, and so a column added for one appears on the other rather
     * than only where someone remembered.
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
        $grantsRetainer = $this->grantsRecurringRetainer($agreement);

        return [
            'id' => $agreement->public_id,
            'title' => $agreement->title,
            'status' => $agreement->status,
            'currency' => $agreement->currency,
            'billing_cadence' => $agreement->billing_cadence,
            // A one-time arrangement carrying retainer columns is not a
            // retainer, and reporting its terms as periodic reads as capacity
            // granted again every cycle.
            'is_recurring' => $agreement->billsOnARecurringCadence(),
            'starts_on' => $agreement->starts_on?->toDateString(),
            'ends_on' => $agreement->ends_on?->toDateString(),
            'signed_at' => $agreement->signed_at?->toISOString(),
            'retainer_minutes_per_period' => $grantsRetainer
                ? (int) round($agreement->periodRetainerHours() * 60)
                : null,
            'retainer_amount_per_period' => $grantsRetainer
                ? (int) round($agreement->periodRetainerFee() * 100)
                : null,
            'hourly_rate_amount' => $agreement->hourly_rate_amount === null
                ? null
                : (int) $agreement->hourly_rate_amount,
            'rollover_months' => $agreement->rollover_months === null
                ? null
                : (int) $agreement->rollover_months,
            'project' => $agreement->client_project_id === null
                ? null
                : $projectNames->get((int) $agreement->client_project_id),
        ];
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
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('starts_on')
                ->orWhere('starts_on', '<=', $today->toDateString()))
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

        if ($agreement->starts_on !== null && $agreement->starts_on->gt($start)) {
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
