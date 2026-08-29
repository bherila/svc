<?php

namespace App\Http\Controllers\Engagement;

use App\Http\Controllers\Controller;
use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\InvoiceLedgerBuilder;
use App\Support\AgentApi\AgentApiVersion;
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
    /** Months of history offered in the period filter. */
    private const MONTH_WINDOW = 12;

    public function __invoke(
        Request $request,
        Workspace $workspace,
        ProjectAccess $access,
        InvoiceLedgerBuilder $ledgers,
    ): Response {
        Gate::authorize('view', $workspace);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $canManage = Gate::forUser($user)->allows('manage', $workspace);

        $companies = $workspace->clientCompanies()
            ->with([
                'projects' => fn ($query) => $query->orderBy('name'),
                'projects.tasks' => fn ($query) => $query->orderBy('title'),
            ])
            ->orderBy('name')
            ->get();

        // Membership of the workspace is not access to every project in it.
        // Without this, a member scoped to one project reads every other
        // project's descriptions, workers, invoice links and capacity.
        $visibleProjectIds = [];

        foreach ($companies as $company) {
            foreach ($company->projects as $project) {
                if ($access->canView($user, $project)) {
                    $visibleProjectIds[] = $project->id;
                }
            }
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

        $selectedCompany = $this->selectedCompany($request, $companies);
        $entries = $this->entries($workspace, $selectedCompany, $visibleProjectIds);
        $invoicesByEntry = $this->invoicesByEntry($workspace, $entries);
        $capacityByMonth = $this->capacityByMonth($ledgers, $selectedCompany);

        return Inertia::render('time', [
            'workspace' => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'default_currency' => $workspace->default_currency,
            ],
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
                    'can_log_time' => $canManage && $access->canLogTime($user, $project),
                    'tasks' => $project->tasks->map(fn (ClientTask $task): array => [
                        'id' => $task->public_id,
                        'title' => $task->title,
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'months' => $this->months($entries, $invoicesByEntry, $capacityByMonth, $access, $user),
        ]);
    }

    /** @param EloquentCollection<int, ClientCompany> $companies */
    private function selectedCompany(Request $request, EloquentCollection $companies): ?ClientCompany
    {
        $requested = $request->query('company');

        if (is_string($requested) && $requested !== '') {
            return $companies->firstWhere('public_id', $requested);
        }

        return $companies->first();
    }

    /**
     * Entries for the window, newest first.
     *
     * @param  list<int>  $visibleProjectIds
     * @return EloquentCollection<int, ClientTimeEntry>
     */
    private function entries(Workspace $workspace, ?ClientCompany $company, array $visibleProjectIds): EloquentCollection
    {
        if ($company === null || $visibleProjectIds === []) {
            /** @var EloquentCollection<int, ClientTimeEntry> */
            return new EloquentCollection;
        }

        $from = CarbonImmutable::now()->startOfMonth()->subMonths(self::MONTH_WINDOW - 1);

        return ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('client_project_id', $visibleProjectIds)
            ->where('worked_on', '>=', $from->toDateString())
            ->with(['project', 'task', 'user'])
            ->orderByDesc('worked_on')
            ->orderByDesc('id')
            ->get();
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
     * @return array<int, array{id: string, number: string|null, status: string}>
     */
    private function invoicesByEntry(Workspace $workspace, EloquentCollection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        $links = ClientInvoiceLine::query()
            ->join('client_invoice_line_time_entries as pivot', 'pivot.client_invoice_line_id', '=', 'client_invoice_lines.id')
            ->join('client_invoices', 'client_invoices.id', '=', 'client_invoice_lines.client_invoice_id')
            ->where('client_invoice_lines.workspace_id', $workspace->id)
            ->whereIn('pivot.client_time_entry_id', $entries->modelKeys())
            ->select([
                'pivot.client_time_entry_id as entry_id',
                'client_invoices.public_id as invoice_id',
                'client_invoices.invoice_number as invoice_number',
                'client_invoices.status as invoice_status',
            ])
            ->get();

        $byEntry = [];

        foreach ($links as $link) {
            $byEntry[(int) $link->getAttribute('entry_id')] = [
                'id' => (string) $link->getAttribute('invoice_id'),
                'number' => $link->getAttribute('invoice_number') === null
                    ? null
                    : (string) $link->getAttribute('invoice_number'),
                'status' => (string) $link->getAttribute('invoice_status'),
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
     * @return array<string, list<array{agreement: string, available_hours: float, worked_hours: float, unused_hours: float, over_hours: float, carried_deficit_hours: float, remaining_rollover: float}>>
     */
    private function capacityByMonth(InvoiceLedgerBuilder $ledgers, ?ClientCompany $company): array
    {
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
            ->whereNotNull('starts_on')
            ->orderBy('starts_on')
            ->get()
            // Only an agreement that actually grants recurring capacity has
            // capacity to report. An hourly-only agreement would render a
            // permanently empty strip beside its hours, and a one-time
            // agreement carrying retainer fields would appear to grant the
            // same hours again every month after the one it was sold for.
            ->filter(fn (ClientAgreement $agreement): bool => $agreement->billsOnARecurringCadence()
                && ($agreement->retainer_minutes !== null || $agreement->period_retainer_minutes !== null));

        $through = CarbonImmutable::now()->endOfMonth();
        $capacity = [];

        foreach ($agreements as $agreement) {
            $ledger = $ledgers->buildAgreementLedgerThrough(
                $company,
                $agreement,
                $through->toMutable(),
            );

            foreach ($ledger as $month) {
                // `closing->excessHours` is populated only when the ledger is
                // built to bill excess immediately, which this one is not - it
                // carries the overage forward as a negative balance instead.
                // Reading `excessHours` alone reports every over-capacity
                // month as comfortably inside its retainer.
                $over = max(0.0, $month->hoursWorked - $month->opening->totalAvailable);

                $capacity[$month->yearMonth][] = [
                    'agreement' => (string) $agreement->title,
                    'available_hours' => round($month->opening->totalAvailable, 2),
                    'worked_hours' => round($month->hoursWorked, 2),
                    'unused_hours' => round($month->closing->unusedHours, 2),
                    'over_hours' => round($over, 2),
                    // The running deficit, which includes what earlier months
                    // carried in - distinct from this month's own overage.
                    'carried_deficit_hours' => round($month->closing->negativeBalance, 2),
                    'remaining_rollover' => round($month->closing->remainingRollover, 2),
                ];
            }
        }

        return $capacity;
    }

    /**
     * @param  EloquentCollection<int, ClientTimeEntry>  $entries
     * @param  array<int, array{id: string, number: string|null, status: string}>  $invoicesByEntry
     * @param  array<string, list<array{agreement: string, available_hours: float, worked_hours: float, unused_hours: float, over_hours: float, carried_deficit_hours: float, remaining_rollover: float}>>  $capacityByMonth
     * @return list<array<string, mixed>>
     */
    private function months(
        EloquentCollection $entries,
        array $invoicesByEntry,
        array $capacityByMonth,
        ProjectAccess $access,
        User $user,
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
                ->map(fn (ClientTimeEntry $entry): array => $this->row($entry, $invoicesByEntry, $access, $user))
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
                'pending_minutes' => (int) $monthEntries
                    ->where('status', 'draft')
                    ->where('is_billable', true)
                    ->sum('minutes'),
                'capacity' => $capacityByMonth[$yearMonth] ?? [],
                'entries' => $rows,
            ];
        }

        return $months;
    }

    /**
     * @param  array<int, array{id: string, number: string|null, status: string}>  $invoicesByEntry
     * @return array<string, mixed>
     */
    private function row(
        ClientTimeEntry $entry,
        array $invoicesByEntry,
        ProjectAccess $access,
        User $user,
    ): array {
        $invoice = $invoicesByEntry[$entry->id] ?? null;
        $project = $entry->project;

        // Any invoice line freezes the entry, and the screen says the same
        // thing the mutation service does. The predecessor unlinked an entry
        // from a draft invoice and regenerated it; reproducing that needs the
        // generator, so until it exists this refuses rather than letting an
        // edit leave a draft charging the old quantity.
        $editable = $entry->status === 'draft'
            && $invoice === null
            && ($entry->user_id === $user->id || $access->isWorkspaceManager($user, $entry->workspace))
            && $access->canLogTime($user, $project);

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
            'task' => $entry->task === null ? null : [
                'id' => $entry->task->public_id,
                'title' => $entry->task->title,
            ],
            'worker' => $entry->user?->name,
            'invoice' => $invoice,
            'can_edit' => $editable,
            'can_approve' => $entry->status === 'draft'
                && $invoice === null
                && $access->canApproveTime($user, $project),
        ];
    }
}
