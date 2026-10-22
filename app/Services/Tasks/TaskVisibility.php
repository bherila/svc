<?php

namespace App\Services\Tasks;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\PortalAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/** Shared task disclosure SQL, applied before pagination, counts and search. */
final class TaskVisibility
{
    public function __construct(private readonly PortalAccess $portal) {}

    /** Exact Portal grants; a dual identity never inherits native staff access.
     * @param  Builder<ClientTask>  $tasks
     * @return Builder<ClientTask>
     */
    public function constrainPortal(Builder $tasks, Workspace $workspace, User|AgentPrincipal $actor, ?ClientCompany $company = null): Builder
    {
        $projects = ClientProject::query()->where('workspace_id', $workspace->id);

        return $this->constrainVisibleStructure($tasks, $workspace, $company)
            ->whereIn('client_tasks.client_project_id', $this->portal->constrainProjectQuery($projects, $actor)->select('client_projects.id'));
    }

    /** DISCLOSURE ONLY, not actor authorization. Native preview callers must
     * first constrain projects through their existing authorized project scope.
     *
     * @param  Builder<ClientTask>  $tasks
     * @return Builder<ClientTask>
     */
    public function constrainVisibleStructure(Builder $tasks, Workspace $workspace, ?ClientCompany $company = null): Builder
    {
        $tasks->where('client_tasks.workspace_id', $workspace->id)
            ->whereHas('project', function (Builder $projects) use ($workspace, $company): void {
                $projects->where('workspace_id', $workspace->id)->where('is_visible_to_client', true)
                    ->whereHas('clientCompany', fn (Builder $companies) => $companies->where('workspace_id', $workspace->id));
                if ($company !== null) {
                    $projects->where('client_company_id', $company->id);
                }
            });
        if ($company !== null && (int) $company->workspace_id !== (int) $workspace->id) {
            return $tasks->whereRaw('1 = 0');
        }

        // A scalar lookup keeps the ancestry probe correlated to this task.
        // MariaDB may materialize an EXISTS semijoin for the entire workspace
        // before applying the outer page limit. The bounded scalar form uses
        // a unique task key, then one key lookup per ancestor on both DBs.
        return $tasks->where(fn (QueryBuilder $probe) => $this->visibleAncestry($workspace, $probe)->limit(1), '=', 1);
    }

    /** @param list<int> $taskIds
     * @return array<int, true>
     */
    public function portalVisibleIds(Workspace $workspace, User|AgentPrincipal $actor, array $taskIds, ?ClientCompany $company = null): array
    {
        $taskIds = TaskAncestryRepository::boundedIds($taskIds);
        if ($taskIds === []) {
            return [];
        }
        $ids = $this->constrainPortal(ClientTask::query(), $workspace, $actor, $company)->whereIn('client_tasks.id', $taskIds)->pluck('client_tasks.id');

        return array_fill_keys($ids->map(fn ($id): int => (int) $id)->all(), true);
    }

    private function visibleAncestry(Workspace $workspace, QueryBuilder $query): QueryBuilder
    {
        $query->from('client_tasks as task_ancestor_0')->selectRaw('1')
            ->where('task_ancestor_0.workspace_id', $workspace->id)
            ->whereColumn('task_ancestor_0.id', 'client_tasks.id')
            ->whereColumn('task_ancestor_0.client_project_id', 'client_tasks.client_project_id')
            ->where('task_ancestor_0.is_visible_to_client', true);
        $listColumns = ['task_ancestor_0.client_task_list_id'];
        for ($level = 0; $level < TaskAncestryFacts::MAX_DEPTH; $level++) {
            $child = 'task_ancestor_'.$level;
            $parent = 'task_ancestor_'.($level + 1);
            $query->leftJoin('client_tasks as '.$parent, function (JoinClause $join) use ($child, $parent): void {
                $join->on($parent.'.id', '=', $child.'.parent_task_id')
                    ->on($parent.'.workspace_id', '=', $child.'.workspace_id')
                    ->on($parent.'.client_project_id', '=', $child.'.client_project_id');
            })->where(fn (QueryBuilder $resolved) => $resolved->whereNull($child.'.parent_task_id')->orWhereNotNull($parent.'.id'))
                ->where(fn (QueryBuilder $rootListOnly) => $rootListOnly->whereNull($child.'.parent_task_id')->orWhereNull($child.'.client_task_list_id'))
                ->where(fn (QueryBuilder $visible) => $visible->whereNull($parent.'.id')->orWhere($parent.'.is_visible_to_client', true));
            $listColumns[] = $parent.'.client_task_list_id';
        }
        $query->whereNull('task_ancestor_'.TaskAncestryFacts::MAX_DEPTH.'.parent_task_id');
        // Only the terminating root may have a list. Every preceding level was
        // validated above, so COALESCE cannot choose a conflicting child list.
        $rootList = 'coalesce('.implode(', ', $listColumns).')';

        return $query->leftJoin('client_task_lists as task_root_list', function (JoinClause $join) use ($rootList): void {
            $join->on('task_root_list.id', '=', DB::raw($rootList))
                ->on('task_root_list.workspace_id', '=', 'task_ancestor_0.workspace_id')
                ->on('task_root_list.client_project_id', '=', 'task_ancestor_0.client_project_id');
        })->where(fn (QueryBuilder $visibleList) => $visibleList->whereRaw($rootList.' is null')
            ->orWhere(fn (QueryBuilder $listed) => $listed->whereNotNull('task_root_list.id')
                ->whereNull('task_root_list.deleted_at')->where('task_root_list.is_visible_to_client', true)));
    }
}
