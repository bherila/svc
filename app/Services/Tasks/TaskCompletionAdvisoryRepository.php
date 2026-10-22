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
}
