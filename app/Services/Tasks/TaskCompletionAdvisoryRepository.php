<?php

namespace App\Services\Tasks;

use App\Models\Workspace;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Only direct children in the exact parent workspace/project can inform a warning. */
final class TaskCompletionAdvisoryRepository
{
    public const int BATCH_SIZE = 100;

    /** @param list<int> $parentIds
     * @return list<int> */
    public function unfinishedParentIds(Workspace $workspace, array $parentIds): array
    {
        if (count($parentIds) > self::BATCH_SIZE) {
            throw new LogicException('Completion advisory reads require bounded parent batches.');
        }
        if ($parentIds === []) {
            return [];
        }

        $ids = DB::table('client_tasks as children')->join('client_tasks as parents', function (JoinClause $join): void {
            $join->on('parents.id', '=', 'children.parent_task_id')
                ->on('parents.workspace_id', '=', 'children.workspace_id')
                ->on('parents.client_project_id', '=', 'children.client_project_id');
        })->where('parents.workspace_id', $workspace->id)->whereIn('parents.id', $parentIds)
            ->where('children.status', '!=', 'completed')->distinct()->pluck('parents.id')
            ->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values($ids);
    }

    /** Only direct, same-workspace/project prerequisites can affect a warning.
     * @param list<int> $taskIds
     * @return list<int> */
    public function unfinishedPrerequisiteTaskIds(Workspace $workspace, array $taskIds): array
    {
        if (count($taskIds) > self::BATCH_SIZE) {
            throw new LogicException('Completion advisory reads require bounded task batches.');
        }
        if ($taskIds === []) {
            return [];
        }

        return array_values(DB::table('client_task_dependencies as edges')
            ->join('client_tasks as source', function (JoinClause $join): void {
                $join->on('source.id', '=', 'edges.client_task_id')->on('source.workspace_id', '=', 'edges.workspace_id')
                    ->on('source.client_project_id', '=', 'edges.client_project_id');
            })
            ->join('client_tasks as prerequisite', function (JoinClause $join): void {
                $join->on('prerequisite.id', '=', 'edges.prerequisite_task_id')->on('prerequisite.workspace_id', '=', 'edges.workspace_id')
                    ->on('prerequisite.client_project_id', '=', 'edges.client_project_id');
            })->where('edges.workspace_id', $workspace->id)->whereIn('source.id', $taskIds)
            ->where('prerequisite.status', '!=', 'completed')->distinct()->pluck('source.id')
            ->map(static fn (mixed $id): int => (int) $id)->all());
    }
}
