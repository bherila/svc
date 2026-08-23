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
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentReadController extends Controller
{
    public function context(Request $request, AgentAccess $access, ProjectAccess $projects): JsonResponse
    {
        $user = $this->user($request);
        $workspaces = Workspace::query()->orderBy('name')->get()->filter(fn (Workspace $workspace): bool => $access->canViewWorkspace($user, $workspace));

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'workspaces' => $workspaces->map(fn (Workspace $workspace): array => [
                'id' => $workspace->public_id,
                'name' => $workspace->name,
                'timezone' => $workspace->timezone,
                'default_currency' => $workspace->default_currency,
                'workspace_role' => $projects->workspaceRole($user, $workspace),
                'capabilities' => $this->capabilities($user, $workspace, $access),
                'web_url' => route('workspaces.operations', $workspace),
            ])->values(),
        ]]);
    }

    public function summary(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $isManager = $access->isWorkspaceManager($user, $workspace);
        $time = ClientTimeEntry::query()->where('workspace_id', $workspace->id);
        if (! $isManager) {
            $time->where('user_id', $user->id);
        }

        $invoices = ClientInvoice::query()->where('workspace_id', $workspace->id);
        if (! $isManager) {
            $invoices->whereHas('clientCompany.portalUsers', fn (Builder $query) => $query->whereKey($user->id))
                ->where('is_visible_to_client', true)
                ->whereIn('status', ['issued', 'partially_paid', 'paid']);
        }

        return response()->json(['data' => [
            'workspace_id' => $workspace->public_id,
            'active_projects' => $this->projectQuery($user, $workspace, $access)->where('status', 'active')->count(),
            'time' => [
                'draft_minutes' => (clone $time)->where('status', 'draft')->sum('minutes'),
                'approved_minutes' => (clone $time)->where('status', 'approved')->sum('minutes'),
                'unbilled_minutes' => (clone $time)->where('status', 'approved')->sum('minutes'),
            ],
            'invoices' => [
                'draft_count' => (clone $invoices)->where('status', 'draft')->count(),
                'overdue_count' => (clone $invoices)->whereIn('status', ['issued', 'partially_paid'])->whereDate('due_date', '<', now()->toDateString())->count(),
                'outstanding_amount' => (int) (clone $invoices)->sum('balance_amount'),
                'currency' => $workspace->default_currency,
            ],
        ]]);
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

    public function project(Request $request, Workspace $workspace, string $project, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $record = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $project)->with(['clientCompany', 'tasks'])->firstOrFail();
        abort_unless($access->canViewProject($user, $record), 404);

        return response()->json(['data' => $this->projectPayload($workspace, $record) + [
            'tasks' => $record->tasks->filter(fn (ClientTask $task): bool => $access->canViewTask($user, $task))
                ->map(fn (ClientTask $task): array => $this->taskPayload($workspace, $task))->values(),
        ]]);
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
                    ->orWhere(fn (Builder $clientVisible) => $clientVisible->where('is_visible_to_client', true)->whereHas('clientCompany.portalUsers', fn (Builder $members) => $members->whereKey($user->id)));
            });
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        return response()->json(['data' => $records->map(fn (ClientTask $task): array => $this->taskPayload($workspace, $task))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function task(Request $request, Workspace $workspace, string $task, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $task)->with('project.clientCompany')->firstOrFail();
        abort_unless($access->canViewTask($user, $record), 404);

        return response()->json(['data' => $this->taskPayload($workspace, $record)]);
    }

    public function timeEntries(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->with(['project', 'clientCompany', 'task', 'user'])->orderBy('id');
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

        if (! $access->isWorkspaceManager($user, $workspace)) {
            $query->where(function (Builder $entries) use ($user): void {
                $entries->where(fn (Builder $own) => $own->where('user_id', $user->id)->whereHas('project.members', fn (Builder $members) => $members->whereKey($user->id)))
                    ->orWhere(fn (Builder $shared) => $shared->where('status', 'approved')->where('is_visible_to_client', true)->whereHas('clientCompany.portalUsers', fn (Builder $members) => $members->whereKey($user->id)));
            });
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        return response()->json(['data' => $records->map(fn (ClientTimeEntry $entry): array => $this->timePayload($workspace, $entry, $access->isWorkspaceManager($user, $workspace)))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function invoices(Request $request, Workspace $workspace, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $query = ClientInvoice::query()->where('workspace_id', $workspace->id)->with('clientCompany')->orderBy('id');
        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }
        if (! $access->isWorkspaceManager($user, $workspace)) {
            $query->where('is_visible_to_client', true)->whereIn('status', ['issued', 'partially_paid', 'paid'])
                ->whereHas('clientCompany.portalUsers', fn (Builder $members) => $members->whereKey($user->id));
        }
        $records = $this->afterCursor($query, $request)->limit($this->limit($request) + 1)->get();
        $next = $records->count() > $this->limit($request) ? $records->pop() : null;

        return response()->json(['data' => $records->map(fn (ClientInvoice $invoice): array => $this->invoicePayload($workspace, $invoice, $access->isWorkspaceManager($user, $workspace)))->values(), 'meta' => ['next_cursor' => $next === null ? null : AgentApiCursor::encode((int) $records->last()->getKey())]]);
    }

    public function invoice(Request $request, Workspace $workspace, string $invoice, AgentAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $this->workspace($user, $workspace, $access);
        $record = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('public_id', $invoice)->with(['clientCompany', 'lines'])->firstOrFail();
        abort_unless($access->canViewInvoice($user, $record), 404);

        return response()->json(['data' => $this->invoicePayload($workspace, $record, $access->isWorkspaceManager($user, $workspace)) + [
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
                ->orWhere(fn (Builder $clients) => $clients->where('is_visible_to_client', true)->whereHas('clientCompany.portalUsers', fn (Builder $users) => $users->whereKey($user->id)));
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
            'data' => $records->map(fn (ClientProject $project): array => $this->projectPayload($workspace, $project))->values(),
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

    /** @return array<string, mixed> */
    private function projectPayload(Workspace $workspace, ClientProject $project): array
    {
        return ['id' => $project->public_id, 'company_id' => $project->clientCompany->public_id, 'name' => $project->name, 'description' => $project->description, 'status' => $project->status, 'is_visible_to_client' => $project->is_visible_to_client, 'version' => AgentApiVersion::for($project), 'web_url' => route('workspaces.operations', $workspace).'?project='.$project->public_id];
    }

    /** @return array<string, mixed> */
    private function taskPayload(Workspace $workspace, ClientTask $task): array
    {
        return ['id' => $task->public_id, 'project_id' => $task->project->public_id, 'title' => $task->title, 'description' => $task->description, 'status' => $task->status, 'is_visible_to_client' => $task->is_visible_to_client, 'completed_at' => $task->completed_at?->toAtomString(), 'version' => AgentApiVersion::for($task), 'web_url' => route('workspaces.operations', $workspace).'?task='.$task->public_id];
    }

    /** @return array<string, mixed> */
    private function timePayload(Workspace $workspace, ClientTimeEntry $entry, bool $includeFinancials): array
    {
        $payload = ['id' => $entry->public_id, 'project_id' => $entry->project->public_id, 'task_id' => $entry->task?->public_id, 'worked_on' => $entry->worked_on->toDateString(), 'minutes' => $entry->minutes, 'description' => $entry->is_visible_to_client ? ($entry->client_visible_description ?? $entry->description) : $entry->description, 'is_billable' => $entry->is_billable, 'is_deferred' => $entry->is_deferred, 'status' => $entry->status, 'version' => AgentApiVersion::for($entry), 'web_url' => route('workspaces.operations', $workspace).'?time_entry='.$entry->public_id];
        if ($includeFinancials) {
            $payload += ['billing_rate_amount' => $entry->billing_rate_amount, 'currency' => $entry->currency];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function invoicePayload(Workspace $workspace, ClientInvoice $invoice, bool $includeNotes): array
    {
        $payload = ['id' => $invoice->public_id, 'company_id' => $invoice->clientCompany->public_id, 'invoice_number' => $invoice->invoice_number, 'status' => $invoice->status, 'currency' => $invoice->currency, 'total_amount' => $invoice->total_amount, 'paid_amount' => $invoice->paid_amount, 'balance_amount' => $invoice->balance_amount, 'issue_date' => $invoice->issue_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(), 'version' => AgentApiVersion::for($invoice), 'web_url' => route('svc.billing.invoices.show', [$workspace, $invoice]), 'pdf_url' => route('svc.billing.invoices.pdf', [$workspace, $invoice])];
        if ($includeNotes) {
            $payload['notes'] = $invoice->notes;
        }

        return $payload;
    }

    /** @return list<string> */
    private function capabilities(User|AgentPrincipal $user, Workspace $workspace, AgentAccess $access): array
    {
        return $access->isWorkspaceManager($user, $workspace)
            ? ['projects:read', 'tasks:read', 'tasks:write', 'time:read', 'time:write', 'time:approve', 'billing:read', 'billing:write', 'billing:deliver']
            : ['projects:read', 'tasks:read', 'time:read', 'time:write'];
    }
}
