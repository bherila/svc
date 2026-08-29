<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\Engagement\ApproveTimeEntriesRequest;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentTimeEntryQuery;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\AgreementSelector;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Services\Billing\TimeEntryProjectChainGuard;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;
use App\Support\Engagement\TimeSheetWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator time sheet.
 *
 * The predecessor's time page is the screen this system is mostly used for, and
 * the two things that made it usable were the month grouping and the capacity
 * strip above each month - you log time against a retainer you can see the
 * remainder of. Both are reproduced here from the ported ledger rather than
 * recomputed, so the number above the table is the number the invoice will use.
 */
class TimeSheetController extends Controller
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ProjectAccess $access,
        InvoiceLedgerBuilder $ledgers,
        AgentTimeEntryQuery $visible,
        AgreementSelector $selector,
        TimeEntryProjectChainGuard $projectChainGuard,
    ): Response {
        Gate::authorize('view', $workspace);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $canManage = Gate::forUser($user)->allows('manage', $workspace);

        // Each relation is constrained by the workspace as well as by its
        // parent. The schema carries independent foreign keys rather than
        // composite workspace/parent ones, so a row owned by another
        // workspace but pointing at a visible parent satisfies the join - and
        // a task reaches the payload without passing any access check of its
        // own, on the strength of the project it names.
        $companies = $workspace->clientCompanies()
            ->with([
                'projects' => fn ($query) => $query->where('workspace_id', $workspace->id)->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();

        $isManager = $access->isWorkspaceManager($user, $workspace);

        // Membership of the workspace is not access to every project in it.
        // Without this, a member scoped to one project reads every other
        // project's descriptions, workers, invoice links and capacity.
        //
        // One role lookup per project, resolved here rather than per row:
        // `ProjectAccess` holds no cache, so asking it while mapping cost two
        // membership queries for every line on a sheet that is twelve months
        // long by design.
        $visibleProjectIds = [];

        /** @var array<int, array{log: bool, approve: bool}> $permissions */
        $permissions = [];

        /** @var array<int, bool> $whollyVisible */
        $whollyVisible = [];

        foreach ($companies as $company) {
            foreach ($company->projects as $project) {
                $role = $access->projectRole($user, $project);

                if ($role === null) {
                    continue;
                }

                $visibleProjectIds[] = $project->id;
                $permissions[$project->id] = [
                    'log' => $role->canLogTime(),
                    'approve' => $role->canApproveTime(),
                    // `AgentTimeEntryQuery` gives an owner or manager every
                    // entry on the project; a contributor only their own, and
                    // a viewer none. Reading the project is not reading its
                    // time.
                    'all_time' => $role->canApproveTime(),
                ];
            }

            $whollyVisible[$company->id] = $isManager || $company->projects->every(
                fn (ClientProject $project): bool => $permissions[$project->id]['all_time'] ?? false,
            );
        }

        $companies = $companies
            ->each(function (ClientCompany $company) use ($visibleProjectIds): void {
                $company->setRelation(
                    'projects',
                    $company->projects->filter(
                        fn (ClientProject $project): bool => in_array($project->id, $visibleProjectIds, true),
                    )->values(),
                );
            })
            ->filter(fn (ClientCompany $company): bool => $company->projects->isNotEmpty())
            ->values();

        // Tasks only now, and only for the projects that survived. Loading
        // them alongside the projects read every hidden project's tasks and
        // discarded them after - correct output, paid for at the size of the
        // workspace rather than of what the reader can see.
        //
        // Loaded on the project instances rather than through the companies:
        // a nested `load('projects.tasks')` re-runs the projects query and
        // puts the filtered-out ones back.
        (new EloquentCollection($companies->flatMap(
            fn (ClientCompany $company): EloquentCollection => $company->projects,
        )->all()))->load([
            'tasks' => fn ($query) => $query->where('workspace_id', $workspace->id)->orderBy('title'),
        ]);

        $selectedCompany = $this->selectedCompany($request, $companies);
        $entries = $this->entries($visible, $user, $workspace, $selectedCompany, $visibleProjectIds);
        $invoicesByEntry = $this->invoicesByEntry($workspace, $entries);

        // A retainer is sold to the company, not to a project, and the ledger
        // aggregates every approved hour the company booked - so there is no
        // honest project-scoped version of these figures. A member who reaches
        // only part of the company gets no strip at all, rather than totals
        // computed from work they cannot see: those disclose the agreement
        // titles and the volume of the work behind them just as plainly as the
        // rows would.
        $capacityByMonth = $selectedCompany !== null
            && ($whollyVisible[$selectedCompany->id] ?? false)
            && $this->ledgerInputsAgree($projectChainGuard, $selectedCompany)
                ? $this->capacityByMonth($ledgers, $selector, $selectedCompany, $entries, $workspace->timezone)
                : [];

        return Inertia::render('time', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'default_currency' => $workspace->default_currency,
                'timezone' => $workspace->timezone,
            ],
            // Named by the request that enforces it, so the page cannot offer
            // a selection the write will refuse.
            'approval_limit' => ApproveTimeEntriesRequest::MAX_ENTRIES,
            'filters' => [
                'company_id' => $selectedCompany?->public_id,
            ],
            'companies' => $companies->map(fn (ClientCompany $company): array => [
                'id' => $company->public_id,
                'name' => $company->name,
                'projects' => $company->projects->map(fn (ClientProject $project): array => [
                    'id' => $project->public_id,
                    'name' => $project->name,
                    // Both halves, because the write requires both. Project
                    // access alone would advertise a form whose POST is
                    // refused by the workspace gate on `store()`.
                    'can_log_time' => $canManage && ($permissions[$project->id]['log'] ?? false),
                    'tasks' => $project->tasks->map(fn (ClientTask $task): array => [
                        'id' => $task->public_id,
                        'title' => $task->title,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'months' => $this->months($entries, $invoicesByEntry, $capacityByMonth, $permissions, $user->id, $isManager),
        ]);
    }

    /** @param EloquentCollection<int, ClientCompany> $companies */
    private function selectedCompany(Request $request, EloquentCollection $companies): ?ClientCompany
    {
        $requested = $request->query('company');

        if (is_string($requested) && $requested !== '') {
            // A stale, malformed or no-longer-visible id falls back rather
            // than selecting nothing. The page independently falls back to the
            // first company, so a null selection here rendered that company's
            // name above "no time logged in the last twelve months" - a claim
            // about a company whose entries were never fetched.
            return $companies->firstWhere('public_id', $requested) ?? $companies->first();
        }

        return $companies->first();
    }

    /**
     * Entries for the window, newest first.
     *
     * @param  list<int>  $visibleProjectIds
     * @return EloquentCollection<int, ClientTimeEntry>
     */
    private function entries(
        AgentTimeEntryQuery $visible,
        User $user,
        Workspace $workspace,
        ?ClientCompany $company,
        array $visibleProjectIds,
    ): EloquentCollection {
        if ($company === null || $visibleProjectIds === []) {
            /** @var EloquentCollection<int, ClientTimeEntry> */
            return new EloquentCollection;
        }

        // Membership of a project is not the right to read its time, and the
        // rule for which entries a person may read already exists - a project
        // owner or manager reads all of them, a contributor only their own, a
        // viewer none. Restating it here is how the two drift; the sheet asks
        // the same question the agent API asks.
        // The visible ids span the workspace, so naming them alone admits an
        // entry filed under this company while pointing at another company's
        // project - rendered, totalled and attributed here on the strength of
        // a key that says nothing about which company it belongs to.
        $ofThisCompany = $company->projects
            ->pluck('id')
            ->intersect($visibleProjectIds)
            ->values()
            ->all();

        if ($ofThisCompany === []) {
            /** @var EloquentCollection<int, ClientTimeEntry> */
            return new EloquentCollection;
        }

        return $visible->visibleTo($user, $workspace)
            ->where('client_company_id', $company->id)
            ->whereIn('client_project_id', $ofThisCompany)
            ->where('worked_on', '>=', TimeSheetWindow::start($workspace->timezone)->toDateString())
            // And an upper one. The window is described to the reader as the
            // last twelve months and capacity is built only to the end of the
            // current one, so a mistyped year sorts a month card above every
            // real one and stays there.
            ->where('worked_on', '<=', TimeSheetWindow::end($workspace->timezone)->toDateString())
            // A task reaches the row on the strength of the id the entry
            // holds; it passes no check of its own, exactly as the tasks
            // offered in the log form did.
            ->with([
                'project',
                'task' => fn ($query) => $query->where('workspace_id', $workspace->id),
                // `user_id` is an independent key and proves no membership,
                // so a row could name someone from outside the workspace
                // entirely. A name is the one field here that identifies a
                // person rather than describing work.
                'user' => fn ($query) => $query->whereHas(
                    'workspaces',
                    fn ($workspaces) => $workspaces->whereKey($workspace->id),
                ),
            ])
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Does every hour the ledger will count belong to a project of this
     * company?
     *
     * The ledger gathers a company's work by `client_company_id` alone, and
     * `client_project_id` is an independent key - so an entry naming this
     * company while pointing at a project of another one, or of another
     * workspace, contributes its hours to a total published here even though
     * the row query excludes it. That is hidden volume disclosed as an
     * aggregate.
     *
     * This fails closed rather than filtering: a total assembled from a set
     * the ledger did not agree to is not the number the invoice will use, and
     * a strip that quietly means something else is worse than no strip.
     * Billing now refuses the same broken ownership chain instead of counting
     * or filtering it. The read-only screen remains broader and quieter: any
     * inconsistent entry withholds the aggregate rather than turning a page
     * view into an exception.
     */
    private function ledgerInputsAgree(
        TimeEntryProjectChainGuard $projectChainGuard,
        ClientCompany $company,
    ): bool {
        return $projectChainGuard->companyProjectChainsAgree($company);
    }

    /**
     * The invoice each entry is attached to, if any.
     *
     * A time entry reaches its invoice through the line that billed it, so the
     * badge on the row can say which of "upcoming" and "invoiced" applies -
     * the distinction the predecessor made, and the one that decides whether
     * the row can still be edited.
     *
     * @param  EloquentCollection<int, ClientTimeEntry>  $entries
     * @return array<int, array{id: string, number: string|null, status: string, regenerable: bool}>
     */
    private function invoicesByEntry(Workspace $workspace, EloquentCollection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $links = ClientInvoiceLine::query()
            ->join('client_invoice_line_time_entries as pivot', 'pivot.client_invoice_line_id', '=', 'client_invoice_lines.id')
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
            // Every tenant-owned table in the join, not just the one the
            // query starts from: the schema's foreign keys are independent, so
            // a line owned here can name an invoice owned elsewhere, and the
            // number and status of that invoice are what get serialized.
            ->where('client_invoice_lines.workspace_id', $workspace->id)
            ->where('client_invoices.workspace_id', $workspace->id)
            ->where('pivot.workspace_id', $workspace->id)
            ->whereIn('pivot.client_time_entry_id', $entries->modelKeys())
            ->select([
                'pivot.client_time_entry_id as entry_id',
                'client_invoices.public_id as invoice_id',
                'client_invoices.invoice_number as invoice_number',
                'client_invoices.status as invoice_status',
                'client_invoices.invoice_kind as invoice_kind',
                'client_invoices.client_agreement_id as agreement_id',
                'client_invoices.service_period_start as service_period_start',
                'client_invoices.service_period_end as service_period_end',
                'client_invoices.cycle_start as cycle_start',
                'client_invoices.cycle_end as cycle_end',
            ])
            ->get();

        $byEntry = [];

        foreach ($links as $link) {
            $kind = InvoiceKind::tryFrom((string) $link->getAttribute('invoice_kind')) ?? InvoiceKind::CadencePeriod;
            $hasAgreement = $link->getAttribute('agreement_id') !== null;
            $hasServicePeriod = $link->getAttribute('service_period_start') !== null
                && $link->getAttribute('service_period_end') !== null;
            $hasCycle = $link->getAttribute('cycle_start') !== null
                && $link->getAttribute('cycle_end') !== null;
            $regenerable = match ($kind) {
                InvoiceKind::AdHoc => true,
                InvoiceKind::CadencePeriod => $hasAgreement && ($hasCycle || $hasServicePeriod),
                InvoiceKind::InterimOverage => $hasAgreement && $hasServicePeriod,
                InvoiceKind::Terminal => false,
            };
            $byEntry[(int) $link->getAttribute('entry_id')] = [
                'id' => (string) $link->getAttribute('invoice_id'),
                'number' => $link->getAttribute('invoice_number') === null
                    ? null
                    : (string) $link->getAttribute('invoice_number'),
                'status' => (string) $link->getAttribute('invoice_status'),
                'regenerable' => $regenerable,
            ];
        }

        return $byEntry;
    }

    /**
     * Retainer capacity per calendar month, from the ported ledger.
     *
     * One ledger per active agreement rather than one per month: the ledger is
     * a running balance, so asking it for a single month in isolation would
     * drop the rollover that month inherited.
     *
     * @param  EloquentCollection<int, ClientTimeEntry>  $entries
     * @return array<string, list<array{agreement: string, cycle_start: string, available_hours: float, worked_hours: float, unused_hours: float, over_hours: float, carried_deficit_hours: float, remaining_rollover: float, pending_minutes: int}>>
     */
    private function capacityByMonth(
        InvoiceLedgerBuilder $ledgers,
        AgreementSelector $selector,
        ?ClientCompany $company,
        EloquentCollection $entries,
        string $timezone,
    ): array {
        if ($company === null) {
            return [];
        }

        // `active_date` and `termination_date` are the engine's names for
        // `starts_on` and `ends_on` - accessors, not columns. Naming one in a
        // predicate is invalid SQL, and SQLite hides it: an unresolvable
        // double-quoted identifier degrades to a string literal, so the filter
        // reads as `where 'active_date' is not null` and admits every row.
        $agreements = ClientAgreement::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('client_company_id', $company->id)
            // What the billing engine ignores cannot be capacity here.
            // `AgreementSelector` and `AgreementBillingRateResolver` both
            // exclude drafts; a proposed retainer carrying a start date would
            // otherwise offer hours the client has not agreed to, above a
            // table an operator logs against. Terminated agreements stay -
            // historical months still need theirs.
            ->where('status', '!=', 'draft')
            ->whereNotNull('starts_on')
            // Ordered as the selector expects: it returns the first later
            // candidate in collection order, so `starts_on` has to lead and
            // `id` is only the tie-break. Sorted by id first, a March renewal
            // inserted before a February one is read as January's successor
            // and January keeps running through February beside it.
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        // Succession is decided over every agreement in force, not only the
        // ones with a strip: an hourly renewal still ends the retainer it
        // replaced, and filtering first would hide exactly that successor.
        $displayed = $agreements
            // Only an agreement that actually grants recurring capacity has
            // capacity to report. An hourly-only agreement would render a
            // permanently empty strip beside its hours, and a one-time
            // agreement carrying retainer fields would appear to grant the
            // same hours again every month after the one it was sold for.
            ->filter(fn (ClientAgreement $agreement): bool => $agreement->billsOnARecurringCadence()
                && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null));

        $through = TimeSheetWindow::end($timezone);
        $from = TimeSheetWindow::start($timezone)->format('Y-m');
        $capacity = [];

        foreach ($displayed as $agreement) {
            // A renewal ends its predecessor whether or not anyone wrote an
            // `ends_on` date, and invoice generation stops the old segment at
            // the new one's start. Without the same boundary the two strips
            // both claim the months after the handover, and the figure stops
            // being the one the invoice uses.
            $end = $through;

            // The ledger clips its last row at the termination date, so
            // pending work after it belongs to no segment at all - reporting
            // it against the expired retainer promises capacity that has
            // ended, and an approval that may find no agreement to price it.
            if ($agreement->ends_on !== null) {
                $terminated = CarbonImmutable::parse($agreement->ends_on)->endOfDay();

                if ($terminated->lt($end)) {
                    $end = $terminated;
                }
            }

            // AgreementSelector owns the same-scope rule used by generation,
            // so the screen cannot drift back to a different definition of a
            // successor while still claiming to show the invoice's ledger.
            $successor = $selector->successorAgreementForGeneration($agreements, $agreement);

            if ($successor?->starts_on !== null) {
                $handover = CarbonImmutable::parse($successor->starts_on)->subDay()->endOfDay();

                if ($handover->lt($end)) {
                    $end = $handover;
                }
            }

            // Built from the agreement's start every time, because the ledger
            // is a running balance and the displayed months inherit rollover
            // from the ones before them. Only what is displayed is trimmed:
            // an agreement running since 2019 would otherwise return six years
            // of empty month cards through a screen that offers twelve.
            $ledger = $ledgers->buildAgreementLedgerThrough(
                $company,
                $agreement,
                $end->toMutable(),
            );

            foreach ($ledger as $index => $month) {
                if ($month->yearMonth < $from) {
                    continue;
                }

                // `closing->excessHours` is populated only when the ledger is
                // built to bill excess immediately, which this one is not - it
                // carries the overage forward as a negative balance instead.
                // Reading `excessHours` alone reports every over-capacity
                // month as comfortably inside its retainer.
                $over = max(0.0, $month->hoursWorked - $month->opening->totalAvailable);

                // Pending work belongs to the strip it can actually reach,
                // and to the segment that will hold it. A retainer scoped to
                // one project is never drawn on by work logged against
                // another; and where a renewal or a mid-month cadence puts
                // two rows in one calendar month, an entry lands in whichever
                // of them covers its date. Matching on the month alone gave
                // both rows the whole month's total.
                $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $month->yearMonth.'-01')->startOfDay();
                $segmentStart = $monthStart;

                foreach ([$agreement->starts_on, $month->cycleStart] as $boundary) {
                    if ($boundary === null || $boundary === '') {
                        continue;
                    }

                    $candidate = CarbonImmutable::parse((string) $boundary)->startOfDay();

                    if ($candidate->gt($segmentStart)) {
                        $segmentStart = $candidate;
                    }
                }

                $segmentEnd = $monthStart->endOfMonth()->startOfDay();

                if ($end->lt($segmentEnd)) {
                    $segmentEnd = $end->startOfDay();
                }

                // And where its own cycle ends. A period retainer anchored
                // mid-month puts the tail of one cycle and the head of the
                // next in the same calendar month; bounded by the month
                // alone, the earlier row swallowed the later cycle's work as
                // well as its own.
                $nextCycle = $ledger[$index + 1] ?? null;

                if ($nextCycle !== null
                    && $nextCycle->yearMonth === $month->yearMonth
                    && $nextCycle->cycleStart !== null
                    && $nextCycle->cycleStart !== $month->cycleStart) {
                    $handsOver = CarbonImmutable::parse($nextCycle->cycleStart)->subDay()->startOfDay();

                    if ($handsOver->lt($segmentEnd)) {
                        $segmentEnd = $handsOver;
                    }
                }

                $pending = $entries
                    ->filter(fn (ClientTimeEntry $entry): bool => $entry->willDrawOnRetainerWhenApproved()
                        && $entry->worked_on->betweenIncluded($segmentStart, $segmentEnd)
                        && ($agreement->client_project_id === null
                            || $entry->client_project_id === $agreement->client_project_id))
                    ->sum('minutes');

                $capacity[$month->yearMonth][] = [
                    'agreement' => (string) $agreement->title,
                    // A cadence anchored mid-month puts the tail of one cycle
                    // and the head of the next in the same calendar month, so
                    // `yearMonth` does not identify a row. Two strips under
                    // one agreement name with unrelated balances and no way to
                    // tell them apart is worse than either number alone.
                    'cycle_start' => (string) $month->cycleStart,
                    'available_hours' => round($month->opening->totalAvailable, 2),
                    'worked_hours' => round($month->hoursWorked, 2),
                    'unused_hours' => round($month->closing->unusedHours, 2),
                    'over_hours' => round($over, 2),
                    // The running deficit, which includes what earlier months
                    // carried in - distinct from this month's own overage.
                    'carried_deficit_hours' => round($month->closing->negativeBalance, 2),
                    'remaining_rollover' => round($month->closing->remainingRollover, 2),
                    'pending_minutes' => (int) $pending,
                ];
            }
        }

        return $capacity;
    }

    /**
     * @param  EloquentCollection<int, ClientTimeEntry>  $entries
     * @param  array<int, array{id: string, number: string|null, status: string, regenerable: bool}>  $invoicesByEntry
     * @param  array<string, list<array{agreement: string, cycle_start: string, available_hours: float, worked_hours: float, unused_hours: float, over_hours: float, carried_deficit_hours: float, remaining_rollover: float, pending_minutes: int}>>  $capacityByMonth
     * @param  array<int, array{log: bool, approve: bool}>  $permissions
     * @return list<array<string, mixed>>
     */
    private function months(
        EloquentCollection $entries,
        array $invoicesByEntry,
        array $capacityByMonth,
        array $permissions,
        int $userId,
        bool $isManager,
    ): array {
        $grouped = $entries->groupBy(
            fn (ClientTimeEntry $entry): string => $entry->worked_on->format('Y-m'),
        );

        // A month with capacity and no entries is exactly when an operator
        // most wants the strip - the current month, before the first entry is
        // logged. Grouping entries alone would omit it.
        $keys = array_unique([...array_keys($grouped->all()), ...array_keys($capacityByMonth)]);
        rsort($keys);

        $months = [];

        foreach ($keys as $yearMonth) {
            $yearMonth = (string) $yearMonth;
            /** @var EloquentCollection<int, ClientTimeEntry> $monthEntries */
            $monthEntries = $grouped->get($yearMonth) ?? new EloquentCollection;
            $rows = $monthEntries
                ->map(fn (ClientTimeEntry $entry): array => $this->row($entry, $invoicesByEntry, $permissions, $userId, $isManager))
                ->values()
                ->all();

            $months[] = [
                'key' => (string) $yearMonth,
                'label' => CarbonImmutable::createFromFormat('Y-m-d', $yearMonth.'-01')->format('F Y'),
                'total_minutes' => (int) $monthEntries->sum('minutes'),
                'billable_minutes' => (int) $monthEntries->where('is_billable', true)->sum('minutes'),
                'deferred_minutes' => (int) $monthEntries->where('is_deferred', true)->sum('minutes'),
                // The ledger counts approved work only, so draft hours sit
                // outside the capacity figures beside them. Reporting them
                // separately is what stops "0 of 10 used" reading as "10 left"
                // when half of it is logged and waiting.
                'capacity' => $capacityByMonth[$yearMonth] ?? [],
                'entries' => $rows,
            ];
        }

        return $months;
    }

    /**
     * @param  array<int, array{id: string, number: string|null, status: string, regenerable: bool}>  $invoicesByEntry
     * @param  array<int, array{log: bool, approve: bool}>  $permissions
     * @return array<string, mixed>
     */
    private function row(
        ClientTimeEntry $entry,
        array $invoicesByEntry,
        array $permissions,
        int $userId,
        bool $isManager,
    ): array {
        $invoice = $invoicesByEntry[$entry->id] ?? null;
        $project = $entry->project;

        // Scoping the relation catches another workspace's task; this catches
        // a task of this workspace belonging to a different project, which the
        // reader may not be able to open at all.
        $task = $entry->task;
        $task = $task !== null && $task->client_project_id === $entry->client_project_id
            ? $task
            : null;

        // Approved time is normally immutable, but a draft invoice has not
        // charged anyone yet. Its mutation path now regenerates the invoice in
        // the same transaction, so both a legacy draft-status allocation and
        // the approved time produced by the real generator remain editable.
        // Anything that has left draft still freezes the entry.
        $isDraftInvoice = $invoice !== null
            && $invoice['status'] === 'draft'
            && $invoice['regenerable'];
        $editableStatus = $entry->status === 'draft'
            || ($entry->status === 'approved' && $isDraftInvoice);
        $editableInvoice = $invoice === null || $isDraftInvoice;
        $editable = $editableStatus
            && $editableInvoice
            && ($entry->user_id === $userId || $isManager)
            && ($permissions[$entry->client_project_id]['log'] ?? false);

        return [
            'id' => $entry->public_id,
            'version' => AgentApiVersion::for($entry),
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => $entry->minutes,
            'description' => $entry->description,
            'client_visible_description' => $entry->client_visible_description,
            'is_billable' => $entry->is_billable,
            'is_deferred' => $entry->is_deferred,
            'is_visible_to_client' => $entry->is_visible_to_client,
            'status' => $entry->status,
            'project' => [
                'id' => $project->public_id,
                'name' => $project->name,
            ],
            'task' => $task === null ? null : [
                'id' => $task->public_id,
                'title' => $task->title,
            ],
            'worker' => $entry->user?->name,
            'invoice' => $invoice === null ? null : [
                'id' => $invoice['id'],
                'number' => $invoice['number'],
                'status' => $invoice['status'],
            ],
            'can_edit' => $editable,
            'can_approve' => $entry->status === 'draft'
                && $invoice === null
                && ($permissions[$entry->client_project_id]['approve'] ?? false),
        ];
    }
}
