<?php

namespace App\Services\Tasks\Organization;

use DomainException;

/** Public immutable facts exclude internal keys and unbounded task descriptions. */
final readonly class TaskOrganizationSnapshot
{
    /** @param array<string,mixed> $data */
    private function __construct(public array $data) {}

    public static function capture(TaskOrganizationRecords $records, TaskGraph $graph, ?TaskContainer $container = null): self
    {
        $lists = [];
        foreach ($records->lists->sortBy([['organization_position', 'asc'], ['id', 'asc']]) as $list) {
            $lists[] = ['id' => $list->public_id, 'name' => $list->name, 'is_visible_to_client' => $list->is_visible_to_client,
                'archived' => $list->deleted_at !== null, 'version' => $graph->lists[$list->public_id]['version']];
        }
        $tree = [];
        foreach ($graph->tasks as $id => $task) {
            $tree[] = ['id' => $id, 'parent_id' => $task['parent'], 'list_id' => $task['list'], 'position' => $task['position'], 'version' => $task['version']];
        }
        $siblings = null;
        if ($container !== null) {
            $ids = $graph->siblings($container);
            if (count($ids) > TaskGraph::MAX_SIBLINGS) {
                throw new DomainException('Organization management supports at most 100 siblings per container.');
            }
            $models = $records->tasks->keyBy('public_id');
            $rows = [];
            foreach ($ids as $id) {
                $task = $models[$id];
                $rows[] = ['id' => $id, 'title' => $task->title, 'status' => $task->status,
                    'is_visible_to_client' => $task->is_visible_to_client, 'version' => $graph->tasks[$id]['version']];
            }
            $siblings = ['parent_id' => $container->parentId, 'list_id' => $container->listId,
                'version' => $graph->containerVersion($container), 'tasks' => $rows];
        }

        return new self(['project_id' => $records->project->public_id, 'graph_version' => $graph->graphVersion(),
            'lists_version' => $graph->graphVersion(), 'lists' => $lists, 'tree' => $tree, 'container' => $siblings]);
    }
}
