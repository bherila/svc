<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientExpense;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Services\Authorization\AgentAccess;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\Presenters\AgentExpensePresenter;
use App\Support\Expenses\ExpenseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AgentExpenseReadService
{
    public function __construct(
        private readonly ProjectAccess $projects,
        private readonly AgentAccess $access,
        private readonly AgentExpensePresenter $presenter,
    ) {}

    /** @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string}} */
    public function list(User|AgentPrincipal $user, Workspace $workspace, ?string $companyId, ?string $projectId, ?string $status, int $limit, ?string $cursor, bool $writeScope = false): array
    {
        Validator::make(compact('companyId', 'projectId', 'status', 'limit', 'cursor'), [
            'companyId' => ['nullable', 'uuid'], 'projectId' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(ExpenseStatus::all())],
            'limit' => ['required', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048'],
        ])->validate();
        $query = $this->visible($user, $workspace);
        if ($companyId !== null) {
            $query->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id)->where('public_id', $companyId));
        }
        if ($projectId !== null) {
            $query->whereHas('project', fn (Builder $project): Builder => $project->where('workspace_id', $workspace->id)->where('public_id', $projectId));
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        $queryKey = 'expenses|'.json_encode([$companyId, $projectId, $status], JSON_THROW_ON_ERROR);
        $after = AgentApiCursor::decode($cursor, $workspace->public_id, $queryKey);
        if ($after !== null) {
            $query->where('id', '>', $after);
        }
        $records = $query->orderBy('id')->limit($limit + 1)->get();
        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records->pop();
        }
        $last = $records->last();
        $canWrite = $writeScope && $this->canWrite($user, $workspace);

        return [
            'data' => array_values($records->map(fn (ClientExpense $record): array => $this->presenter->present($record, $canWrite))->all()),
            'meta' => ['next_cursor' => $hasMore && $last !== null ? AgentApiCursor::encode($last->id, $workspace->public_id, $queryKey) : null],
        ];
    }

    /** @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function results(User $user, Workspace $workspace, array $ids): array
    {
        $records = $this->visible($user, $workspace)->whereIn('public_id', $ids)->get()->keyBy('public_id');
        $canWrite = $this->canWrite($user, $workspace);
        $result = [];
        foreach ($ids as $id) {
            $record = $records->get($id);
            abort_unless($record instanceof ClientExpense, 404);
            $result[] = $this->presenter->present($record, $canWrite);
        }

        return $result;
    }

    public function canWrite(User|AgentPrincipal $user, Workspace $workspace): bool
    {
        return (bool) config('agent_api.writes_enabled')
            && (bool) config('agent_api.expense_writes_enabled')
            && $this->access->isWorkspaceManager($user, $workspace);
    }

    /** @return Builder<ClientExpense> */
    private function visible(User|AgentPrincipal $user, Workspace $workspace): Builder
    {
        abort_unless($this->access->canViewWorkspace($user, $workspace), 404);
        $projects = $this->projects->viewableProjectIds($user, $workspace);
        $query = (new WorkspaceExpenses($workspace))->query();
        if ($projects !== null) {
            $query->whereIn('client_project_id', $projects);
        }

        return $query->with([
            'clientCompany' => fn ($company) => $company->where('workspace_id', $workspace->id),
            'project' => fn ($project) => $project->where('workspace_id', $workspace->id),
        ]);
    }
}
