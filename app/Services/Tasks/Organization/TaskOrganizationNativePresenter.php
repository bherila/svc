<?php

namespace App\Services\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\ClientTask;
use App\Models\Workspace;
use DomainException;

/** Presentation over already authorized, bounded facts; no queries per row. */
final class TaskOrganizationNativePresenter
{
    public function present(Workspace $workspace, ClientCompany $company, TaskOrganizationRecords $records, TaskGraph $graph, TaskContainer $container, ?string $movingId): TaskOrganizationNativeView
    {
        $snapshot = TaskOrganizationSnapshot::capture($records, $graph, $container);
        $tasks = $records->tasks->keyBy('public_id');
        $lists = $records->lists->keyBy('public_id');
        abort_if($movingId !== null && ! isset($graph->tasks[$movingId]), 404);
        $path = ['workspace' => $workspace, 'project' => $records->project->public_id];
        $browse = fn (TaskContainer $target, ?string $moving = null): string => route('tasks.organization.show',
            $path + ['clientCompany' => $company, 'parent_id' => $target->parentId, 'list_id' => $target->listId, 'moving_task_id' => $moving]);
        $currentHref = $browse($container);
        $createTaskHref = route('agent-api.v1.task-organization.tasks.store', $path);
        $moveTarget = function (TaskContainer $target) use ($graph, $movingId): ?array {
            if ($movingId === null) {
                return null;
            }
            try {
                $source = new TaskContainer($graph->tasks[$movingId]['parent'], $graph->tasks[$movingId]['list']);
                $next = $graph->move($movingId, $target);
                foreach ([$source, $target] as $siblings) {
                    $next = $next->reorder($siblings, $next->siblings($siblings));
                }

                return $this->target($target);
            } catch (DomainException) {
                return null;
            }
        };
        $createHref = function (TaskContainer $target) use ($graph, $createTaskHref): ?string {
            try {
                $graph->assertCanCreate($target);

                return $createTaskHref;
            } catch (DomainException) {
                return null;
            }
        };
        $listRows = [];
        $orderedListIds = [];
        foreach ($snapshot->data['lists'] as $row) {
            $list = $lists[$row['id']];
            $target = new TaskContainer(null, $list->public_id);
            if (! $row['archived']) {
                $orderedListIds[] = $list->public_id;
            }
            $listRows[] = $row + ['browse_href' => $browse($target, $movingId),
                'update_href' => $row['archived'] ? null : route('agent-api.v1.task-organization.lists.update', $path + ['list' => $list->public_id]),
                'archive_href' => $row['archived'] ? null : route('agent-api.v1.task-organization.lists.archive', $path + ['list' => $list->public_id]),
                'restore_href' => $row['archived'] ? route('agent-api.v1.task-organization.lists.restore', $path + ['list' => $list->public_id]) : null,
                'move_target' => $moveTarget($target)];
        }
        $taskRows = [];
        foreach ($graph->siblings($container) as $id) {
            $task = $tasks[$id];
            $child = new TaskContainer($id, null);
            $taskRows[] = ['id' => $id, 'title' => $task->title, 'status_label' => $this->statusLabel($task),
                'version' => $graph->tasks[$id]['version'], 'is_visible_to_client' => $task->is_visible_to_client,
                'effective_visible_to_client' => $this->effectiveVisibility($records, $graph, $id),
                'task_href' => route('tasks.show', [$workspace, $task]), 'browse_href' => $browse($child, $movingId),
                'create_child_href' => $createHref($child), 'child_target' => $this->target($child),
                'move_picker_href' => $browse($container, $id), 'move_target' => $moveTarget($child)];
        }
        $rootList = $container->parentId === null ? $container->listId : $graph->rootList($container->parentId);
        $root = new TaskContainer(null, $rootList);
        $title = $rootList === null ? 'Unlisted tasks' : $lists[$rootList]->name;
        $breadcrumbs = [['label' => $title, 'href' => $browse($root, $movingId)]];
        $ancestors = [];
        $parent = $container->parentId;
        while ($parent !== null) {
            $ancestors[] = $parent;
            $parent = $graph->tasks[$parent]['parent'];
        }
        foreach (array_reverse($ancestors) as $id) {
            $title = $tasks[$id]->title;
            $breadcrumbs[] = ['label' => $title, 'href' => $browse(new TaskContainer($id, null), $movingId)];
        }
        $moving = $movingId === null ? null : ['id' => $movingId, 'title' => $tasks[$movingId]->title,
            'version' => $graph->tasks[$movingId]['version'], 'move_href' => route('agent-api.v1.task-organization.tasks.move', $path + ['task' => $movingId]), 'cancel_href' => $currentHref];

        return new TaskOrganizationNativeView(['company' => ['name' => $company->name, 'href' => route('clients.show', [$workspace, $company])],
            'project' => ['id' => $records->project->public_id, 'name' => $records->project->name, 'href' => route('clients.project', [$workspace, $company, $records->project])],
            'organization' => ['graph_version' => $graph->graphVersion(), 'lists_version' => $graph->graphVersion(),
                'create_list_href' => count($graph->lists) < TaskGraph::MAX_LISTS ? route('agent-api.v1.task-organization.lists.store', $path) : null,
                'reorder_lists_href' => route('agent-api.v1.task-organization.lists.reorder', $path), 'ordered_list_ids' => $orderedListIds,
                'assignees_href' => route('tasks.assignees', [$workspace, $records->project]),
                'unlisted' => ['browse_href' => $browse(new TaskContainer(null, null), $movingId), 'move_target' => $moveTarget(new TaskContainer(null, null))],
                'lists' => $listRows, 'moving' => $moving,
                'container' => ['title' => $title, 'target' => $this->target($container), 'move_target' => $moveTarget($container), 'version' => $graph->containerVersion($container),
                    'create_task_href' => $createHref($container), 'reorder_href' => route('agent-api.v1.task-organization.tasks.reorder', $path),
                    'breadcrumbs' => $breadcrumbs, 'tasks' => $taskRows, 'sharing_note' => $this->sharingNote($records, $graph, $container)]]]);
    }

    /** @return array{parent_id:?string,list_id:?string} */
    private function target(TaskContainer $container): array
    {
        return ['parent_id' => $container->parentId, 'list_id' => $container->listId];
    }

    private function effectiveVisibility(TaskOrganizationRecords $records, TaskGraph $graph, string $id): bool
    {
        if (! $records->project->is_visible_to_client) {
            return false;
        }
        $tasks = $records->tasks->keyBy('public_id');
        $current = $id;
        while ($current !== null) {
            if (! $tasks[$current]->is_visible_to_client) {
                return false;
            }
            $current = $graph->tasks[$current]['parent'];
        }
        $listId = $graph->rootList($id);
        if ($listId === null) {
            return true;
        }
        $list = $records->lists->firstWhere('public_id', $listId);

        return $list !== null && $list->deleted_at === null && $list->is_visible_to_client;
    }

    private function sharingNote(TaskOrganizationRecords $records, TaskGraph $graph, TaskContainer $container): ?string
    {
        if (! $records->project->is_visible_to_client) {
            return 'This project is hidden from clients. Shared tasks remain hidden until the project is shared.';
        }
        $listId = $container->parentId === null ? $container->listId : $graph->rootList($container->parentId);
        if ($listId !== null) {
            $list = $records->lists->firstWhere('public_id', $listId);
            if ($list->deleted_at !== null) {
                return 'This list is archived. Its tasks are hidden from clients.';
            }
            if (! $list->is_visible_to_client) {
                return 'This list is hidden from clients. Shared tasks inside it remain hidden.';
            }
        }
        if ($container->parentId !== null && ! $this->effectiveVisibility($records, $graph, $container->parentId)) {
            return 'A containing task is hidden from clients. Shared subtasks remain hidden.';
        }

        return null;
    }

    private function statusLabel(ClientTask $task): string
    {
        return match ($task->status) {
            'open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', default => 'Unknown',
        };
    }
}
