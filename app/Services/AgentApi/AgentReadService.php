<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\AgentCapabilities;
use App\Services\Authorization\AgentTimeEntryQuery;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\PortalInvoiceQuery;
use App\Services\Authorization\ProjectAccess;
use App\Services\Billing\DeferredBacklogReadService;
use App\Services\Billing\InvoiceCorrectionReadService;
use App\Services\Mcp\AgentWithheldTools;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskMutationOrigin;
use App\Services\Tasks\TaskPlanningPresenter;
use App\Services\Tasks\TaskVisibility;
use App\Support\AgentApi\AgentApiContract;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\AgentWriteCutover;
use App\Support\AgentApi\ChangeFeedPage;
use App\Support\AgentApi\ChangeWindow;
use App\Support\AgentApi\CursorPage;
use App\Support\AgentApi\InvoiceListFilters;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use App\Support\AgentApi\Presenters\SquareLinkPresenter;
use App\Support\Billing\InvoiceKind;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use BWH\Auth\OAuth\Session\ProviderSessionExpired;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Tenant-scoped, allowlisted read workflows shared by Agent REST and MCP.
 *
 * This is deliberately the only place where the Agent API's read visibility,
 * loading, pagination, and presentation are composed. Adapters supply the
 * authenticated subject and already-authenticated scope predicate; they do
 * not dispatch one transport through another or recreate access rules.
 */
final class AgentReadService
{
    /**
     * The invoices.list filters a change feed takes: those on what an invoice
     * never changes. Every other one can stop (or start) matching an invoice
     * when it changes - its status, its balance, its dates - or, for overdue,
     * as the date passes without any change at all; the feed would then never
     * deliver the change that took it out of the filter.
     */
    private const array INVOICE_FEED_FILTERS = ['company_id', 'invoice_kind'];

    private const int PROJECT_TASK_LINK_RESERVE = 8192;

    public function __construct(
        private readonly AgentAccess $access,
        private readonly ProjectAccess $projects,
        private readonly PortalAccess $portalAccess,
        private readonly PortalInvoiceQuery $portalInvoices,
        private readonly AgentTimeEntryQuery $timeQueries,
        private readonly AgentTimeEntryAllocationQuery $allocations,
        private readonly AgentCapabilities $capabilities,
        private readonly AgentProjectPresenter $projectPresenter,
        private readonly AgentTaskPresenter $taskPresenter,
        private readonly AgentTimeEntryPresenter $timeEntryPresenter,
        private readonly AgentInvoicePresenter $invoicePresenter,
        private readonly DeferredBacklogReadService $deferredBacklogs,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  Closure(string): bool  $allowsScope
     * @return array<string, mixed>
     */
    public function context(User|AgentPrincipal $user, Closure $allowsScope): array
    {
        // Scope the tenant query before materializing it. The final policy
        // check protects legacy/orphaned relationship rows as well.
        $workspaces = Workspace::query()
            ->where(function (Builder $workspaces) use ($user): void {
                $workspaces
                    ->whereHas('memberships', fn (Builder $memberships): Builder => $memberships->where('user_id', $user->id))
                    ->orWhereHas('clientCompanies.portalUsers', fn (Builder $users): Builder => $users->whereKey($user->id));
            })
            ->orderBy('name')
            ->get()
            ->filter(fn (Workspace $workspace): bool => $this->access->canViewWorkspace($user, $workspace));

        return [
            'contract_version' => AgentApiContract::VERSION,
            'id' => $user->public_id,
            'name' => $user->name,
            'provider_subject' => $this->providerSubject($user),
            'capabilities' => AgentWriteCutover::workspaces() && $allowsScope('workspaces:create') ? ['workspaces:create'] : [],
            'withheld_tools' => app(AgentWithheldTools::class)->for($allowsScope,
                $workspaces->contains(fn (Workspace $workspace): bool => $this->access->isWorkspaceManager($user, $workspace))),
            'workspaces' => $workspaces->map(function (Workspace $workspace) use ($user, $allowsScope): array {
                return [
                    'id' => $workspace->public_id,
                    'name' => $workspace->name,
                    'timezone' => $workspace->timezone,
                    'default_currency' => $workspace->default_currency,
                    'workspace_role' => $this->projects->workspaceRole($user, $workspace),
                    ...$this->capabilities->forWorkspace($user, $workspace, $allowsScope),
                    'web_url' => route('workspaces.operations', $workspace),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The caller's subject at the trusted identity provider, so a connecting
     * application can check it reached the account of the person who
     * connected. A stable cross-application identifier rather than a secret,
     * but kept to this authorized self-description: never in a public
     * response or a log. Null for an account with no provider binding, or a
     * binding the trusted provider does not vouch for.
     */
    private function providerSubject(User|AgentPrincipal $user): ?string
    {
        try {
            return app(ProviderBindingResolver::class)->binding($user)?->subject;
        } catch (ProviderSessionExpired) {
            return null;
        }
    }

    /**
     * @param  Closure(string): bool  $allowsScope
     * @return array<string, mixed>
     */
    public function summary(User|AgentPrincipal $user, Workspace $workspace, Closure $allowsScope): array
    {
        $this->requireWorkspace($user, $workspace);
        $data = ['workspace_id' => $workspace->public_id];

        if ($allowsScope(AgentApiScopes::PROJECTS_READ)) {
            $data['active_projects'] = $this->projectQuery($user, $workspace)->where('status', 'active')->count();
        }
        if ($allowsScope(AgentApiScopes::TIME_READ)) {
            $time = $this->timeQueries->visibleTo($user, $workspace);
            $data['time'] = [
                'draft_minutes' => (int) (clone $time)->where('status', 'draft')->sum('minutes'),
                'approved_billable_unallocated_minutes' => (int) $this->allocations->invoiceReady(clone $time)->sum('minutes'),
                'allocated_to_draft_minutes' => (int) $this->allocations->filter((clone $time)->where('status', 'approved'), 'reserved')->sum('minutes'),
            ];
        }
        if ($allowsScope(AgentApiScopes::BILLING_READ)) {
            $invoices = $this->invoiceQuery($user, $workspace);
            $collectible = (clone $invoices)->whereIn('status', ['issued', 'partially_paid'])->where('balance_amount', '>', 0);
            $overdue = (clone $collectible)->whereDate('due_date', '<', $this->clock->today($workspace)->toDateString());
            $data['invoices'] = [
                'draft_count' => (clone $invoices)->where('status', 'draft')->count(),
                'overdue_count' => (clone $overdue)->count(),
                'draft_amounts' => $this->amountsByCurrency((clone $invoices)->where('status', 'draft'), 'total_amount'),
                'collectible_balances' => $this->amountsByCurrency($collectible, 'balance_amount'),
                'overdue_balances' => $this->amountsByCurrency($overdue, 'balance_amount'),
            ];
            // Agreement-rate prices are a manager's to read, as the capacity
            // ledger they sit beside is.
            if ($this->access->isWorkspaceManager($user, $workspace)) {
                $data['deferred_backlog'] = $this->deferredBacklogs->summary($workspace);
            }
        }

        return $data;
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function projects(User|AgentPrincipal $user, Workspace $workspace, int $limit, ?string $cursor, ?string $status, ?string $search): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->projectQuery($user, $workspace)->with('clientCompany')->orderBy('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($search !== null && $search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        // Withheld from a portal client, and fail-closed for anyone who is
        // somehow both: a repository mapping names internal infrastructure, and
        // the client half of a dual identity is the half that must not see it.
        $includeRepository = ! $this->access->isWorkspaceClient($user, $workspace);

        return CursorPage::run($workspace, 'projects|status='.($status ?? '').'|search='.($search ?? ''), $limit, $cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $page) => $page->where('client_projects.id', '>', $after))->limit($take)->get(),
            fn (ClientProject $project): array => $this->projectPresenter->present($workspace, $project, $includeRepository),
            maxDataBytes: AgentPageBudget::dataBytes(),
        );
    }

    /** @return array<string, mixed> */
    public function project(User|AgentPrincipal $user, Workspace $workspace, string $projectId, bool $includeTasks, ?string $tasksCursor = null): array
    {
        $this->requireWorkspace($user, $workspace);
        $record = $this->projectQuery($user, $workspace)->where('public_id', $projectId)->with('clientCompany')->firstOrFail();
        $data = $this->projectPresenter->present($workspace, $record, ! $this->access->isWorkspaceClient($user, $workspace));
        if ($includeTasks) {
            // The base project's description consumes the same response budget.
            // Reserve 8 KiB for property names and an encoded continuation URL
            // (request cursors themselves are bounded to 2 KiB).
            $budget = max(1, AgentPageBudget::dataBytes() - strlen(json_encode($data, JSON_THROW_ON_ERROR)) - self::PROJECT_TASK_LINK_RESERVE);
            $page = $this->taskPage($user, $workspace, $projectId, 25, $tasksCursor, TaskMutationOrigin::Agent, $budget);
            $data['tasks'] = $page['data'];
            $data['tasks_next_href'] = $page['meta']['next_cursor'] === null ? null
                : route('agent-api.v1.projects.show', ['workspace' => $workspace, 'project' => $projectId, 'tasks_cursor' => $page['meta']['next_cursor']]);
        }

        return $data;
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function tasks(User|AgentPrincipal $user, Workspace $workspace, ?string $projectId, int $limit, ?string $cursor, TaskMutationOrigin $origin = TaskMutationOrigin::Agent): array
    {
        $this->requireWorkspace($user, $workspace);

        return $this->taskPage($user, $workspace, $projectId, $limit, $cursor, $origin, AgentPageBudget::dataBytes());
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    private function taskPage(User|AgentPrincipal $user, Workspace $workspace, ?string $projectId, int $limit, ?string $cursor, TaskMutationOrigin $origin, int $maxDataBytes): array
    {
        $includePlanning = $origin === TaskMutationOrigin::Internal || ! $this->access->isWorkspaceClient($user, $workspace);
        $manageable = $includePlanning ? app(TaskAccess::class)->manageableProjectIds($user, $workspace) : [];
        $query = $this->taskQuery($user, $workspace, $origin)->with('project.clientCompany')->when($includePlanning, fn (Builder $planning): Builder => $planning->with(TaskPlanningPresenter::relations($workspace)))->orderBy('id');
        if ($projectId !== null && $projectId !== '') {
            $query->whereHas('project', fn (Builder $projects) => $projects->where('public_id', $projectId));
        }

        return CursorPage::run($workspace, 'tasks|project_id='.($projectId ?? ''), $limit, $cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn (Builder $page) => $page->where('client_tasks.id', '>', $after))->limit($take)->get(),
            fn (ClientTask $task): array => $this->taskPresenter->present($workspace, $task, includePlanning: $includePlanning,
                canManage: $manageable === null || in_array((int) $task->client_project_id, $manageable, true)),
            maxDataBytes: $maxDataBytes,
        );
    }

    /** @return array<string, mixed> */
    public function task(User|AgentPrincipal $user, Workspace $workspace, string $taskId, TaskMutationOrigin $origin = TaskMutationOrigin::Agent): array
    {
        $this->requireWorkspace($user, $workspace);
        $includePlanning = $origin === TaskMutationOrigin::Internal || ! $this->access->isWorkspaceClient($user, $workspace);
        $manageable = $includePlanning ? app(TaskAccess::class)->manageableProjectIds($user, $workspace) : [];
        $record = $this->taskQuery($user, $workspace, $origin)->where('public_id', $taskId)->with('project.clientCompany')->when($includePlanning, fn (Builder $planning): Builder => $planning->with(TaskPlanningPresenter::relations($workspace)))->firstOrFail();

        return $this->taskPresenter->present($workspace, $record, includePlanning: $includePlanning, canManage: $manageable === null || in_array((int) $record->client_project_id, $manageable, true));
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function timeEntries(User|AgentPrincipal $user, Workspace $workspace, ?string $projectId, ?string $status, ?string $from, ?string $to, int $limit, ?string $cursor, ?string $allocationState = null, ?bool $isBillable = null, bool $unallocated = false, bool $compact = false): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->timeQueries->visibleTo($user, $workspace)->with(['project', 'clientCompany'])
            ->with($this->taskReferenceRelations($user, $workspace))->with(AgentTimeEntryPresenter::authorRelations($workspace))
            ->withCapacityPlacement($workspace->id)->orderBy('id');
        if ($projectId !== null && $projectId !== '') {
            $query->whereHas('project', fn (Builder $projects) => $projects->where('public_id', $projectId));
        }
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($from !== null && $from !== '') {
            $query->whereDate('worked_on', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $query->whereDate('worked_on', '<=', $to);
        }
        if ($allocationState !== null && $allocationState !== '') {
            $this->allocations->filter($query, $allocationState);
        }
        if ($isBillable !== null) {
            $query->where('is_billable', $isBillable);
        }
        if ($unallocated || ($allocationState === 'unallocated' && $isBillable === true)) {
            $this->allocations->invoiceReady($query);
        }
        $includeFinancials = $this->access->isWorkspaceManager($user, $workspace);

        $page = $this->page($query, $workspace, 'time_entries|project_id='.($projectId ?? '').'|status='.($status ?? '').'|from='.($from ?? '').'|to='.($to ?? '').'|allocation_state='.($allocationState ?? '').'|is_billable='.($isBillable === null ? '' : (int) $isBillable).'|unallocated='.(int) $unallocated, $limit, $cursor);
        $entries = array_values(array_filter($page['records'], static fn (Model $record): bool => $record instanceof ClientTimeEntry));
        $canReadInternalNotes = $this->access->canReadInternalTimeNotes($user, $workspace, $entries, $includeFinancials);
        $data = [];
        foreach ($entries as $entry) {
            $data[] = $this->timeEntryPresenter->present(
                $workspace,
                $entry,
                $includeFinancials,
                $entry->is_visible_to_client && ! ($canReadInternalNotes[$entry->id] ?? false),
                $compact,
            );
        }

        return ['data' => $data, 'meta' => ['next_cursor' => $page['next_cursor']]];
    }

    /**
     * The given entries, in the given order, exactly as `timeEntries` lists them.
     *
     * A write that returns its rows this way saves the caller the list call it
     * would otherwise make to learn the rate and status the write settled on.
     *
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    public function timeEntriesByIds(User|AgentPrincipal $user, Workspace $workspace, array $ids): array
    {
        $this->requireWorkspace($user, $workspace);
        $entries = $this->timeQueries->visibleTo($user, $workspace)
            ->with(['project', 'clientCompany'])->with($this->taskReferenceRelations($user, $workspace))
            ->with(AgentTimeEntryPresenter::authorRelations($workspace))
            ->withCapacityPlacement($workspace->id)
            ->whereIn('public_id', $ids)
            ->get()
            ->keyBy('public_id');
        $includeFinancials = $this->access->isWorkspaceManager($user, $workspace);
        $canReadInternalNotes = $this->access->canReadInternalTimeNotes($user, $workspace, $entries->values(), $includeFinancials);

        return array_map(function (string $id) use ($entries, $workspace, $includeFinancials, $canReadInternalNotes): array {
            $entry = $entries->get($id);
            abort_unless($entry instanceof ClientTimeEntry, 404);

            return $this->timeEntryPresenter->present(
                $workspace,
                $entry,
                $includeFinancials,
                $entry->is_visible_to_client && ! ($canReadInternalNotes[$entry->id] ?? false),
            );
        }, $ids);
    }

    /**
     * Visible invoices by id, or - when the window names `updated_since` or a
     * watermark - newest change first inside a snapshot watermark
     * ({@see ChangeFeedPage}). Change feeds require a workspace owner/admin;
     * portal visibility can change without a removal record in the feed.
     *
     * @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string, watermark?: string}}
     */
    public function invoices(User|AgentPrincipal $user, Workspace $workspace, ?string $status, int $limit, ?string $cursor, ?InvoiceListFilters $filters = null, ?ChangeWindow $window = null): array
    {
        $this->requireWorkspace($user, $workspace);
        if ($window !== null && $window->requested()) {
            if (! $this->access->isWorkspaceManager($user, $workspace)) {
                throw ValidationException::withMessages([
                    'change_feed' => 'Invoice change-feed mode (updated_since or watermark) is only supported for workspace owners and admins; request invoice snapshots without these parameters.',
                ]);
            }
            ChangeWindow::refuseMutableFilters([
                'status' => $status !== null && $status !== '',
                ...array_fill_keys(array_keys($filters === null ? [] : $filters->values), true),
            ], self::INVOICE_FEED_FILTERS);
        }
        $query = $this->invoiceQuery($user, $workspace)->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id)]);
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        $values = $filters === null ? [] : $filters->values;
        if (isset($values['company_id'])) {
            $query->whereHas('clientCompany', fn (Builder $companies) => $companies->where('workspace_id', $workspace->id)->where('public_id', $values['company_id']));
        }
        if (isset($values['invoice_kind'])) {
            $query->where(fn (Builder $kind) => $kind->where('invoice_kind', $values['invoice_kind'])
                ->when($values['invoice_kind'] === InvoiceKind::CadencePeriod->value, fn (Builder $legacy) => $legacy->orWhereNull('invoice_kind')));
        }
        foreach (['issue_date', 'due_date'] as $field) {
            if (isset($values[$field.'_from'])) {
                $query->whereDate($field, '>=', $values[$field.'_from']);
            }
            if (isset($values[$field.'_to'])) {
                $query->whereDate($field, '<=', $values[$field.'_to']);
            }
        }
        if (isset($values['service_period_overlaps'])) {
            $query->whereDate('service_period_start', '<=', $values['service_period_overlaps']['to'])
                ->whereDate('service_period_end', '>=', $values['service_period_overlaps']['from']);
        }
        foreach (['collectible', 'overdue'] as $filter) {
            if (! array_key_exists($filter, $values)) {
                continue;
            }
            $query->where(function (Builder $condition) use ($workspace, $filter, $values): void {
                if ((bool) $values[$filter]) {
                    $condition->whereIn('status', ['issued', 'partially_paid'])->where('balance_amount', '>', 0);
                    if ($filter === 'overdue') {
                        $condition->whereDate('due_date', '<', $this->clock->today($workspace)->toDateString());
                    }
                } else {
                    $condition->whereNotIn('status', ['issued', 'partially_paid'])->orWhere('balance_amount', '<=', 0);
                    if ($filter === 'overdue') {
                        $condition->orWhereNull('due_date')->orWhereDate('due_date', '>=', $this->clock->today($workspace)->toDateString());
                    }
                }
            });
        }
        $includeNotes = $this->access->isWorkspaceManager($user, $workspace);
        $queryKey = 'invoices|status='.($status ?? '');
        if ($values !== []) {
            ksort($values);
            $queryKey .= '|filters='.json_encode($values, JSON_THROW_ON_ERROR);
        }

        if ($window !== null && $window->requested()) {
            return ChangeFeedPage::run($workspace, $queryKey, $query, $limit, $cursor, $window,
                static function (): void {},
                fn (ClientInvoice $invoice): array => $this->invoicePresenter->present($workspace, $invoice, $includeNotes));
        }

        $page = $this->page($query->orderBy('id'), $workspace, $queryKey, $limit, $cursor);
        $data = [];
        foreach ($page['records'] as $invoice) {
            if (! $invoice instanceof ClientInvoice) {
                continue;
            }
            $data[] = $this->invoicePresenter->present($workspace, $invoice, $includeNotes);
        }

        return ['data' => $data, 'meta' => ['next_cursor' => $page['next_cursor']]];
    }

    /** @return array<string, mixed> */
    public function invoice(User|AgentPrincipal $user, Workspace $workspace, string $invoiceId): array
    {
        $this->requireWorkspace($user, $workspace);
        $record = $this->invoiceQuery($user, $workspace)->where('public_id', $invoiceId)->with(['clientCompany' => fn ($companies) => $companies->where('workspace_id', $workspace->id), 'lines' => fn ($lines) => $lines->where('workspace_id', $workspace->id), 'lines.project' => fn ($projects) => $projects->where('workspace_id', $workspace->id)])->firstOrFail();

        $manager = $this->access->isWorkspaceManager($user, $workspace);
        // Managers only, like the delivery state: why payments.record would
        // refuse this invoice (G6), asked the way its guard asks.
        $collection = $manager ? app(PaymentCollectionGuard::class)->summary($record) : ['pending_payments' => null, 'collection_owner' => null];

        return $this->invoicePresenter->present($workspace, $record, $manager) + [
            // Managers only, like the delivery state: who collects this invoice (#438).
            'square' => $manager ? app(SquareLinkPresenter::class)->invoice($record) : null,
            'pending_payments' => $collection['pending_payments'],
            'collection_owner' => $collection['collection_owner'],
            'corrections' => $manager
                ? app(InvoiceCorrectionReadService::class)->agentHistory($workspace, $record) : [],
            'lines' => $record->lines->map(fn ($line): array => ['id' => $line->public_id] + $this->invoicePresenter->line($line))->values()->all(),
        ];
    }

    /** @return Builder<ClientProject> */
    private function projectQuery(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientProject::query()->where('workspace_id', $workspace->id)
            ->whereHas('clientCompany', fn (Builder $companies) => $companies->where('workspace_id', $workspace->id));
        if ($this->access->isWorkspaceClient($user, $workspace)) {
            return $this->portalAccess->constrainProjectQuery($query->where('is_visible_to_client', true), $user);
        }
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->whereHas('members', fn (Builder $members) => $members->whereKey($user->id)
            ->where('client_project_memberships.workspace_id', $workspace->id));
    }

    /** @return Builder<ClientTask> */
    private function taskQuery(User|AgentPrincipal $user, Workspace $workspace, TaskMutationOrigin $origin = TaskMutationOrigin::Agent): Builder
    {
        $query = ClientTask::query()->where('workspace_id', $workspace->id)
            ->whereHas('project', fn (Builder $projects): Builder => $projects->where('workspace_id', $workspace->id)
                ->whereHas('clientCompany', fn (Builder $companies): Builder => $companies->where('workspace_id', $workspace->id)));
        if ($origin === TaskMutationOrigin::Internal && $this->projects->workspaceRole($user, $workspace) === null) {
            return $query->whereRaw('1 = 0');
        }
        if ($origin === TaskMutationOrigin::Agent && $this->access->isWorkspaceClient($user, $workspace)) {
            return app(TaskVisibility::class)->constrainPortal($query, $workspace, $user);
        }
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->whereHas('project', fn (Builder $projects) => $projects
            ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id)
                ->where('client_project_memberships.workspace_id', $workspace->id)));
    }

    /** Time visibility stands alone. Only its optional task reference is narrowed.
     * @return array<string, Closure>
     */
    private function taskReferenceRelations(User|AgentPrincipal $actor, Workspace $workspace): array
    {
        $visible = $this->taskQuery($actor, $workspace)->select('client_tasks.id');

        return ['task' => fn ($tasks) => $tasks->where('workspace_id', $workspace->id)->whereIn('client_tasks.id', $visible)];
    }

    /** @return Builder<ClientInvoice> */
    private function invoiceQuery(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id));
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $this->portalInvoices->visibleInWorkspace($workspace, $user)->whereHas('clientCompany', fn ($companies) => $companies->where('workspace_id', $workspace->id));
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{records:list<Model>,next_cursor:?string}
     */
    private function page(Builder $query, Workspace $workspace, string $queryKey, int $limit, ?string $cursor): array
    {
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $models = $query->limit($limit + 1)->get();
        $next = $models->count() > $limit ? $models->pop() : null;
        $nextCursor = null;
        if ($next !== null) {
            $last = $models->last();
            if ($last !== null) {
                $nextCursor = AgentApiCursor::encode((int) $last->getKey(), $workspace->public_id, $queryKey);
            }
        }

        $records = [];
        foreach ($models as $record) {
            $records[] = $record;
        }

        return ['records' => $records, 'next_cursor' => $nextCursor];
    }

    private function requireWorkspace(User|AgentPrincipal $user, Workspace $workspace): void
    {
        if (! $this->access->canViewWorkspace($user, $workspace)) {
            throw (new ModelNotFoundException)->setModel(Workspace::class);
        }
    }

    /** @param Builder<ClientInvoice> $query
     * @return list<array{currency:string,amount:int}> */
    private function amountsByCurrency(Builder $query, string $column): array
    {
        $query->select('currency');
        if ($column === 'total_amount') {
            $query->selectRaw('SUM(total_amount) AS aggregate_amount');
        } elseif ($column === 'balance_amount') {
            $query->selectRaw('SUM(balance_amount) AS aggregate_amount');
        } else {
            throw new \InvalidArgumentException('Unsupported invoice amount column.');
        }

        $amounts = [];
        foreach ($query->groupBy('currency')->orderBy('currency')->get() as $invoice) {
            $amounts[] = [
                'currency' => $invoice->currency,
                'amount' => (int) $invoice->getAttribute('aggregate_amount'),
            ];
        }

        return $amounts;
    }
}
