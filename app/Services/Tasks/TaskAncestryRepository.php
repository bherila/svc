<?php

namespace App\Services\Tasks;

use App\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Bounded read snapshots, NOT authoritative locking facts for future writers. */
final class TaskAncestryRepository
{
    public const int MAX_BATCH = 100;

    /** Callers first authorize the requested task rows. No foreign lineage is returned.
     * @param  list<int>  $taskIds
     * @return array<int, TaskAncestryFacts>
     */
    public function factsBatch(Workspace $workspace, array $taskIds): array
    {
        $taskIds = self::boundedIds($taskIds);
        $pending = $taskIds;
        $tasks = [];
        $listIds = [];
        for ($depth = 0; $depth <= TaskAncestryFacts::MAX_DEPTH && $pending !== []; $depth++) {
            $rows = $this->tasks($workspace)->whereIn('task.id', $pending)->get();
            $pending = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $tasks[$id] = ['id' => $id, 'project_id' => (int) $row->client_project_id,
                    'parent_id' => $row->parent_task_id === null ? null : (int) $row->parent_task_id,
                    'list_id' => $row->client_task_list_id === null ? null : (int) $row->client_task_list_id,
                    'visible' => (bool) $row->is_visible_to_client, 'project_visible' => (bool) $row->project_visible];
                if ($row->parent_task_id !== null && ! isset($tasks[(int) $row->parent_task_id])) {
                    $pending[] = (int) $row->parent_task_id;
                }
                if ($row->client_task_list_id !== null) {
                    $listIds[] = (int) $row->client_task_list_id;
                }
            }
            $pending = array_values(array_unique($pending));
        }
        $lists = [];
        if ($listIds !== []) {
            foreach (DB::table('client_task_lists')->where('workspace_id', $workspace->id)->whereIn('id', array_unique($listIds))->get() as $row) {
                $lists[(int) $row->id] = ['project_id' => (int) $row->client_project_id,
                    'visible' => (bool) $row->is_visible_to_client, 'deleted' => $row->deleted_at !== null];
            }
        }
        $facts = [];
        foreach ($taskIds as $id) {
            $facts[$id] = TaskAncestryFacts::classify($id, $tasks, $lists);
        }

        return $facts;
    }

    /** Runtime validation boundary, including malformed adapter batches.
     * @param  array<array-key, mixed>  $ids
     * @return list<int>
     */
    public static function boundedIds(array $ids): array
    {
        if (! array_is_list($ids) || count($ids) > self::MAX_BATCH) {
            throw new InvalidArgumentException('Task visibility batches must contain at most 100 task IDs.');
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id <= 0) {
                throw new InvalidArgumentException('Task visibility requires positive internal integer IDs.');
            }
        }

        return array_values(array_unique($ids));
    }

    private function tasks(Workspace $workspace): Builder
    {
        return DB::table('client_tasks as task')->where('task.workspace_id', $workspace->id)
            ->join('client_projects as project', function ($join): void {
                $join->on('project.id', '=', 'task.client_project_id')->on('project.workspace_id', '=', 'task.workspace_id');
            })->join('client_companies as company', function ($join): void {
                $join->on('company.id', '=', 'project.client_company_id')->on('company.workspace_id', '=', 'task.workspace_id');
            })->select(['task.*', 'project.is_visible_to_client as project_visible']);
    }
}
