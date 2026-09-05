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
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use App\Support\WorkspaceClock;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
    public function __construct(
        private readonly AgentAccess $access,
        private readonly ProjectAccess $projects,
        private readonly PortalAccess $portalAccess,
        private readonly AgentTimeEntryQuery $timeQueries,
        private readonly AgentCapabilities $capabilities,
        private readonly AgentProjectPresenter $projectPresenter,
        private readonly AgentTaskPresenter $taskPresenter,
        private readonly AgentTimeEntryPresenter $timeEntryPresenter,
        private readonly AgentInvoicePresenter $invoicePresenter,
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
            'id' => $user->public_id,
            'name' => $user->name,
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
                'approved_billable_unallocated_minutes' => (int) (clone $time)
                    ->pricedForInvoicing()
                    ->where('is_billable', true)
                    ->where('is_deferred', false)
                    ->whereDoesntHave('invoiceLines')
                    ->sum('minutes'),
                'allocated_to_draft_minutes' => (int) (clone $time)
                    ->where('status', 'approved')
                    ->whereHas('invoiceLines.invoice', fn (Builder $invoices) => $invoices->where('status', 'draft'))
                    ->sum('minutes'),
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

        $page = $this->page($query, $workspace, 'projects|status='.($status ?? '').'|search='.($search ?? ''), $limit, $cursor);
        // Withheld from a portal client, and fail-closed for anyone who is
        // somehow both: a repository mapping names internal infrastructure, and
        // the client half of a dual identity is the half that must not see it.
        $includeRepository = ! $this->access->isWorkspaceClient($user, $workspace);
        $data = [];
        foreach ($page['records'] as $project) {
            if (! $project instanceof ClientProject) {
                continue;
            }
            $data[] = $this->projectPresenter->present($workspace, $project, $includeRepository);
        }

        return ['data' => $data, 'meta' => ['next_cursor' => $page['next_cursor']]];
    }

    /** @return array<string, mixed> */
    public function project(User|AgentPrincipal $user, Workspace $workspace, string $projectId, bool $includeTasks): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->projectQuery($user, $workspace)->where('public_id', $projectId)->with('clientCompany');
        if ($includeTasks) {
            $query->with('tasks');
        }
        $record = $query->firstOrFail();
        $data = $this->projectPresenter->present($workspace, $record, ! $this->access->isWorkspaceClient($user, $workspace));
        if ($record->relationLoaded('tasks')) {
            $data['tasks'] = $record->tasks->filter(fn (ClientTask $task): bool => $this->access->canViewTask($user, $task))
                ->map(fn (ClientTask $task): array => $this->taskPresenter->present($workspace, $task))->values()->all();
        }

        return $data;
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function tasks(User|AgentPrincipal $user, Workspace $workspace, ?string $projectId, int $limit, ?string $cursor): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->taskQuery($user, $workspace)->with('project.clientCompany')->orderBy('id');
        if ($projectId !== null && $projectId !== '') {
            $query->whereHas('project', fn (Builder $projects) => $projects->where('public_id', $projectId));
        }

        $page = $this->page($query, $workspace, 'tasks|project_id='.($projectId ?? ''), $limit, $cursor);
        $data = [];
        foreach ($page['records'] as $task) {
            if (! $task instanceof ClientTask) {
                continue;
            }
            $data[] = $this->taskPresenter->present($workspace, $task);
        }

        return ['data' => $data, 'meta' => ['next_cursor' => $page['next_cursor']]];
    }

    /** @return array<string, mixed> */
    public function task(User|AgentPrincipal $user, Workspace $workspace, string $taskId): array
    {
        $this->requireWorkspace($user, $workspace);
        $record = $this->taskQuery($user, $workspace)->where('public_id', $taskId)->with('project.clientCompany')->firstOrFail();

        return $this->taskPresenter->present($workspace, $record);
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function timeEntries(User|AgentPrincipal $user, Workspace $workspace, ?string $projectId, ?string $status, ?string $from, ?string $to, int $limit, ?string $cursor): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->timeQueries->visibleTo($user, $workspace)->with(['project', 'clientCompany', 'task', 'user'])->orderBy('id');
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
        $includeFinancials = $this->access->isWorkspaceManager($user, $workspace);

        $page = $this->page($query, $workspace, 'time_entries|project_id='.($projectId ?? '').'|status='.($status ?? '').'|from='.($from ?? '').'|to='.($to ?? ''), $limit, $cursor);
        $data = [];
        foreach ($page['records'] as $entry) {
            if (! $entry instanceof ClientTimeEntry) {
                continue;
            }
            $data[] = $this->timeEntryPresenter->present($workspace, $entry, $includeFinancials, $entry->is_visible_to_client);
        }

        return ['data' => $data, 'meta' => ['next_cursor' => $page['next_cursor']]];
    }

    /** @return array{data:list<array<string, mixed>>,meta:array{next_cursor:?string}} */
    public function invoices(User|AgentPrincipal $user, Workspace $workspace, ?string $status, int $limit, ?string $cursor): array
    {
        $this->requireWorkspace($user, $workspace);
        $query = $this->invoiceQuery($user, $workspace)->with('clientCompany')->orderBy('id');
        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        $includeNotes = $this->access->isWorkspaceManager($user, $workspace);

        $page = $this->page($query, $workspace, 'invoices|status='.($status ?? ''), $limit, $cursor);
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
        $record = $this->invoiceQuery($user, $workspace)->where('public_id', $invoiceId)->with(['clientCompany', 'lines'])->firstOrFail();

        return $this->invoicePresenter->present($workspace, $record, $this->access->isWorkspaceManager($user, $workspace)) + [
            'lines' => $record->lines->map(fn ($line): array => [
                'id' => $line->public_id,
                'project_id' => $line->project?->public_id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_amount' => $line->unit_amount,
                'tax_amount' => $line->tax_amount,
                'total_amount' => $line->total_amount,
            ])->values()->all(),
        ];
    }

    /** @return Builder<ClientProject> */
    private function projectQuery(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientProject::query()->where('workspace_id', $workspace->id);
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where(function (Builder $projects) use ($user): void {
            $projects->whereHas('members', fn (Builder $members) => $members->whereKey($user->id))
                ->orWhere(fn (Builder $clients) => $this->portalAccess
                    ->constrainProjectQuery($clients->where('is_visible_to_client', true), $user));
        });
    }

    /** @return Builder<ClientTask> */
    private function taskQuery(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientTask::query()->where('workspace_id', $workspace->id);
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where(function (Builder $tasks) use ($user): void {
            $tasks->where(function (Builder $assigned) use ($user): void {
                $assigned->whereHas('project', fn (Builder $projects) => $projects
                    ->whereHas('members', fn (Builder $members) => $members->whereKey($user->id)))
                    ->where(function (Builder $visibility) use ($user): void {
                        $visibility->whereDoesntHave('project.clientCompany.portalUsers', fn (Builder $users) => $users->whereKey($user->id))
                            ->orWhere('is_visible_to_client', true);
                    });
            })->orWhere(function (Builder $clientVisible) use ($user): void {
                $clientVisible->where('is_visible_to_client', true)
                    ->whereHas('project', fn (Builder $projects) => $this->portalAccess
                        ->constrainProjectQuery($projects->where('is_visible_to_client', true), $user));
            });
        });
    }

    /** @return Builder<ClientInvoice> */
    private function invoiceQuery(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id);
        if ($this->access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereHas('clientCompany.portalUsers', fn (Builder $members) => $members->whereKey($user->id));
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
