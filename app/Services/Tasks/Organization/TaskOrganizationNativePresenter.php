<?php

namespace App\Services\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\Workspace;
use App\Support\AgentApi\Presenters\AgentClientPresenter;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use DomainException;

/** Presentation over already authorized, bounded facts; no queries per row. */
final class TaskOrganizationNativePresenter
{
    public function present(Workspace $workspace, ClientCompany $company, TaskOrganizationRecords $records, TaskGraph $graph, TaskContainer $container, ?string $movingId): TaskOrganizationNativeView
    {
        $snapshot = TaskOrganizationSnapshot::capture($records, $graph, $container);
        $containerTasks = array_column($snapshot->data['container']['tasks'], null, 'id');
        $tasks = $records->tasks->keyBy('public_id');
        $lists = $records->lists->keyBy('public_id');
        abort_if($movingId !== null && ! isset($graph->tasks[$movingId]), 404);
        $path = ['workspace' => $workspace, 'project' => $records->project->public_id];
        $browse = fn (TaskContainer $target, ?string $moving = null): string => route('tasks.organization.show',
            $path + ['clientCompany' => $company, 'parent_id' => $target->parentId, 'list_id' => $target->listId, 'moving_task_id' => $moving]);
        $currentHref = $browse($container);
        $createTaskHref = route('tasks.organization.tasks.store', $path);
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
                'update_href' => $row['archived'] ? null : route('tasks.organization.lists.update', $path + ['list' => $list->public_id]),
                'archive_href' => $row['archived'] ? null : route('tasks.organization.lists.archive', $path + ['list' => $list->public_id]),
                'restore_href' => $row['archived'] ? route('tasks.organization.lists.restore', $path + ['list' => $list->public_id]) : null,
                'move_target' => $moveTarget($target)];
        }
        $taskRows = [];
        foreach ($graph->siblings($container) as $id) {
            $task = $tasks[$id];
            $child = new TaskContainer($id, null);
            $core = $containerTasks[$id];
            $taskRows[] = ['id' => $core['id'], 'title' => $core['title'], 'status_label' => $this->statusLabel($core['status']),
                'version' => $core['version'], 'is_visible_to_client' => $core['is_visible_to_client'],
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
        $moving = $movingId === null ? null : AgentTaskPresenter::identity($tasks[$movingId]) + [
            'version' => $graph->tasks[$movingId]['version'], 'move_href' => route('tasks.organization.tasks.move', $path + ['task' => $movingId]), 'cancel_href' => $currentHref];

        return new TaskOrganizationNativeView(['company' => ['name' => AgentClientPresenter::identity($company)['name'], 'href' => route('clients.show', [$workspace, $company])],
            'project' => AgentProjectPresenter::identity($records->project) + ['href' => route('clients.project', [$workspace, $company, $records->project])],
            'organization' => ['graph_version' => $graph->graphVersion(), 'lists_version' => $graph->graphVersion(),
                'create_list_href' => count($graph->lists) < TaskGraph::MAX_LISTS ? route('tasks.organization.lists.store', $path) : null,
                'reorder_lists_href' => route('tasks.organization.lists.reorder', $path), 'ordered_list_ids' => $orderedListIds,
                'assignees_href' => route('tasks.assignees', [$workspace, $records->project]),
                'unlisted' => ['browse_href' => $browse(new TaskContainer(null, null), $movingId), 'move_target' => $moveTarget(new TaskContainer(null, null))],
                'lists' => $listRows, 'moving' => $moving,
                'container' => ['title' => $title, 'target' => $this->target($container), 'move_target' => $moveTarget($container), 'version' => $graph->containerVersion($container),
                    'create_task_href' => $createHref($container), 'reorder_href' => route('tasks.organization.tasks.reorder', $path),
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

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Open', 'in_progress' => 'In progress', 'completed' => 'Completed', default => 'Unknown',
        };
    }
}
