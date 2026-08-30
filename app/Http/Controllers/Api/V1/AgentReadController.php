<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
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
use App\Services\Authorization\AgentTokenScopes;
use App\Services\Authorization\PortalAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\Presenters\AgentInvoicePresenter;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use App\Support\AgentApi\Presenters\AgentTimeEntryPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentReadController extends Controller
{
    public function __construct(
        private readonly AgentProjectPresenter $projects,
        private readonly AgentTaskPresenter $tasks,
        private readonly AgentTimeEntryPresenter $timeEntries,
        private readonly AgentInvoicePresenter $invoices,
    ) {}

    public function context(Request $request, AgentAccess $access, ProjectAccess $projects, AgentCapabilities $capabilities): JsonResponse
    {
        $user = $this->user($request);
        $workspaces = Workspace::query()->orderBy('name')->get()->filter(fn (Workspace $workspace): bool => $access->canViewWorkspace($user, $workspace));

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'workspaces' => $workspaces->map(function (Workspace $workspace) use ($request, $user, $projects, $capabilities): array {
                $authorized = $capabilities->forWorkspace($request, $user, $workspace);

                return [
                    'id' => $workspace->public_id,
                    'name' => $workspace->name,
                    'timezone' => $workspace->timezone,
                    'default_currency' => $workspace->default_currency,
                    'workspace_role' => $projects->workspaceRole($user, $workspace),
                    ...$authorized,
                    'web_url' => route('workspaces.operations', $workspace),
                ];
            })->values(),
        ]]);
    }

    public function summary(Request $request, Workspace $workspace, AgentAccess $access, AgentTimeEntryQuery $timeQueries, AgentTokenScopes $scopes): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $data = ['workspace_id' => $workspace->public_id];

        if ($scopes->allows($request, AgentApiScopes::PROJECTS_READ)) {
            $data['active_projects'] = $this->projectQuery($user, $workspace, $access)->where('status', 'active')->count();
        }
        if ($scopes->allows($request, AgentApiScopes::TIME_READ)) {
            $time = $timeQueries->visibleTo($user, $workspace);
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
        if ($scopes->allows($request, AgentApiScopes::BILLING_READ)) {
            $invoices = $this->invoiceQuery($user, $workspace, $access);
            $collectible = (clone $invoices)->whereIn('status', ['issued', 'partially_paid'])->where('balance_amount', '>', 0);
            $overdue = (clone $collectible)->whereDate('due_date', '<', now()->toDateString());
            $data['invoices'] = [
                'draft_count' => (clone $invoices)->where('status', 'draft')->count(),
                'overdue_count' => (clone $overdue)->count(),
                'draft_amounts' => $this->amountsByCurrency((clone $invoices)->where('status', 'draft'), 'total_amount'),
                'collectible_balances' => $this->amountsByCurrency($collectible, 'balance_amount'),
                'overdue_balances' => $this->amountsByCurrency($overdue, 'balance_amount'),
            ];
        }

        return response()->json(['data' => $data]);
    }

    public function projects(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = $this->projectQuery($user, $workspace, $access)->with('clientCompany')->orderBy('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('query')) {
            $query->where('name', 'like', '%'.$request->string('query')->toString().'%');
        }

        return $this->paginatedProjects($query, $request, $workspace);
    }

    public function project(Request $request, Workspace $workspace, string $project, AgentAccess $access, AgentTokenScopes $scopes): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $project)->with('clientCompany');
        if ($scopes->allows($request, AgentApiScopes::TASKS_READ)) {
            $query->with('tasks');
        }
        $record = $query->firstOrFail();
        abort_unless($access->canViewProject($user, $record), 404);

        $data = $this->projects->present($workspace, $record);
        if ($record->relationLoaded('tasks')) {
            $data['tasks'] = $record->tasks->filter(fn (ClientTask $task): bool => $access->canViewTask($user, $task))
                ->map(fn (ClientTask $task): array => $this->tasks->present($workspace, $task))->values();
        }

        return response()->json(['data' => $data]);
    }

    public function tasks(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = ClientTask::query()->where('workspace_id', $workspace->id)->with('project.clientCompany')->orderBy('id');
        if ($request->filled('project_id')) {
            $query->whereHas('project', fn (Builder $projects) => $projects->where('public_id', $request->string('project_id')->toString()));
        }
        if (! $access->isWorkspaceManager($user, $workspace)) {
            $query->whereHas('project', function (Builder $projects) use ($user): void {
                $projects->where(fn (Builder $assigned) => $assigned->whereHas('members', fn (Builder $members) => $members->whereKey($user->id)))
                    // Portal access can be narrowed to named projects, so this
                    // asks PortalAccess rather than settling for "is a portal
                    // user of the company", which ignored the narrowing.
                    ->orWhere(fn (Builder $clientVisible) => app(PortalAccess::class)
                        ->constrainProjectQuery($clientVisible->where('is_visible_to_client', true), $user));
            });
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        return response()->json(['data' => $records->map(fn (ClientTask $task): array => $this->tasks->present($workspace, $task))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function task(Request $request, Workspace $workspace, string $task, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $task)->with('project.clientCompany')->firstOrFail();
        abort_unless($access->canViewTask($user, $record), 404);

        return response()->json(['data' => $this->tasks->present($workspace, $record)]);
    }

    public function timeEntries(Request $request, Workspace $workspace, AgentAccess $access, AgentTimeEntryQuery $timeQueries): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = $timeQueries->visibleTo($user, $workspace)->with(['project', 'clientCompany', 'task', 'user'])->orderBy('id');
        if ($request->filled('project_id')) {
            $query->whereHas('project', fn (Builder $projects) => $projects->where('public_id', $request->string('project_id')->toString()));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('from')) {
            $query->whereDate('worked_on', '>=', $request->string('from')->toString());
        }
        if ($request->filled('to')) {
            $query->whereDate('worked_on', '<=', $request->string('to')->toString());
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        $includeFinancials = $access->isWorkspaceManager($user, $workspace);

        return response()->json(['data' => $records->map(fn (ClientTimeEntry $entry): array => $this->timeEntries->present($workspace, $entry, $includeFinancials, $entry->is_visible_to_client))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function invoices(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = $this->invoiceQuery($user, $workspace, $access)->with('clientCompany')->orderBy('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        $includeNotes = $access->isWorkspaceManager($user, $workspace);

        return response()->json(['data' => $records->map(fn (ClientInvoice $invoice): array => $this->invoices->present($workspace, $invoice, $includeNotes))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function invoice(Request $request, Workspace $workspace, string $invoice, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $record = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $invoice)->with(['clientCompany', 'lines'])->firstOrFail();
        abort_unless($access->canViewInvoice($user, $record), 404);

        return response()->json(['data' => $this->invoices->present($workspace, $record, $access->isWorkspaceManager($user, $workspace)) + [
            'lines' => $record->lines->map(fn ($line): array => [
                'id' => $line->public_id,
                'project_id' => $line->project?->public_id,
                'type' => $line->type,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_amount' => $line->unit_amount,
                'tax_amount' => $line->tax_amount,
                'total_amount' => $line->total_amount,
            ])->values(),
        ]]);
    }

    /** @return Builder<ClientProject> */
    private function projectQuery(User|AgentPrincipal $user, Workspace $workspace, AgentAccess $access): Builder
    {
        $query = ClientProject::query()->where('workspace_id', $workspace->id);
        if ($access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where(function (Builder $projects) use ($user): void {
            $projects->whereHas('members', fn (Builder $members) => $members->whereKey($user->id))
                ->orWhere(fn (Builder $clients) => app(PortalAccess::class)
                    ->constrainProjectQuery($clients->where('is_visible_to_client', true), $user));
        });
    }

    private function workspace(User|AgentPrincipal $user, Workspace $workspace, AgentAccess $access): void
    {
        abort_unless($access->canViewWorkspace($user, $workspace), 404);
    }

    private function user(Request $request): User|AgentPrincipal
    {
        $user = $request->user();
        abort_unless($user instanceof User || $user instanceof AgentPrincipal, 401);

        return $user;
    }

    /** @param Builder<ClientProject> $query */
    private function paginatedProjects(Builder $query, Request $request, Workspace $workspace): JsonResponse
    {
        $cursor = AgentApiCursor::decode($request->query('cursor'));
        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }
        $limit = $this->limit($request);
        $records = $query->limit($limit + 1)->get();
        $next = $records->count() > $limit ? $records->pop() : null;

        return response()->json([
            'data' => $records->map(fn (ClientProject $project): array => $this->projects->present($workspace, $project))->values(),
            'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())],
        ]);
    }

    private function limit(Request $request): int
    {
        return min(100, max(1, (int) $request->integer('limit', 25)));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function afterCursor(Builder $query, Request $request): Builder
    {
        $cursor = AgentApiCursor::decode($request->query('cursor'));
        if ($cursor !== null) {
            $query->where('id', '>', $cursor);
        }

        return $query;
    }

    /** @return Builder<ClientInvoice> */
    private function invoiceQuery(User|AgentPrincipal $user, Workspace $workspace, AgentAccess $access): Builder
    {
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id);
        if ($access->isWorkspaceManager($user, $workspace)) {
            return $query;
        }

        return $query->where('is_visible_to_client', true)
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->whereHas('clientCompany.portalUsers', fn (Builder $members) => $members->whereKey($user->id));
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
        $rows = $query
            ->groupBy('currency')
            ->orderBy('currency')
            ->get();
        $amounts = [];
        foreach ($rows as $invoice) {
            $amounts[] = [
                'currency' => $invoice->currency,
                'amount' => (int) $invoice->getAttribute('aggregate_amount'),
            ];
        }

        return $amounts;
    }
}
