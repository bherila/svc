<?php

namespace App\Services\Tasks\Organization;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskList;
use App\Models\Workspace;
use App\Services\Tasks\TaskGraphRevisionRepository;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\Locks;
use Illuminate\Database\Eloquent\Builder;

/** The only persistence boundary for task organization, always tenant and project scoped. */
final class TaskOrganizationRepository
{
    public function __construct(private readonly TaskGraphRevisionRepository $revisions) {}

    public function load(Workspace $workspace, ClientProject $project, bool $lock, bool $initialize = false): TaskOrganizationRecords
    {
        $state = $lock && $initialize
            ? $this->revisions->lockForMutation($workspace, $project)
            : $this->revisions->read($workspace, $project, current: $lock);
        // Retain the verified scope for revision writes without a lazy lookup.
        $project->setRelation('workspace', $workspace);
        $tasks = ClientTask::query()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
            ->orderBy('id')->limit(TaskGraph::MAX_TASKS + 1)->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))
            ->get(['id', 'public_id', 'workspace_id', 'client_project_id', 'parent_task_id', 'client_task_list_id', 'organization_position', 'lock_version', 'title', 'status', 'is_visible_to_client']);
        $lists = ClientTaskList::withTrashed()->where('workspace_id', $workspace->id)->where('client_project_id', $project->id)
            ->orderBy('id')->limit(TaskGraph::MAX_LISTS + 1)->when($lock, fn (Builder $query) => $query->tap(Locks::forUpdate()))->get();

        return new TaskOrganizationRecords($project, $state, $tasks, $lists);
    }

    /** Current locked facts become immutable, database-free topology. */
    public function graph(TaskOrganizationRecords $records): TaskGraph
    {
        $taskIds = $records->tasks->pluck('public_id', 'id')->all();
        $listIds = $records->lists->pluck('public_id', 'id')->all();
        $tasks = [];
        foreach ($records->tasks as $task) {
            $tasks[$task->public_id] = ['id' => $task->id,
                'parent' => $task->parent_task_id === null ? null : ($taskIds[$task->parent_task_id] ?? 'unavailable'),
                'list' => $task->client_task_list_id === null ? null : ($listIds[$task->client_task_list_id] ?? 'unavailable'),
                'position' => (int) $task->getAttribute('organization_position'), 'version' => AgentApiVersion::for($task)];
        }
        $lists = [];
        foreach ($records->lists as $list) {
            $lists[$list->public_id] = ['id' => $list->id, 'deleted' => $list->deleted_at !== null,
                'position' => (int) $list->getAttribute('organization_position'), 'version' => AgentApiVersion::for($list)];
        }

        return new TaskGraph($tasks, $lists, (int) $records->state->lock_version);
    }

    public function apply(Workspace $workspace, TaskOrganizationRecords $records, TaskGraph $next, ?string $advanceTask = null): void
    {
        abort_unless($records->project->workspace_id === $workspace->id, 404);
        $before = $this->graph($records);
        $tasks = $records->tasks->keyBy('public_id');
        $lists = $records->lists->keyBy('public_id');
        foreach ($next->tasks as $id => $facts) {
            if ($facts === $before->tasks[$id] && $id !== $advanceTask) {
                continue;
            }
            $task = $tasks[$id];
            $task->forceFill(['parent_task_id' => $facts['parent'] === null ? null : $tasks[$facts['parent']]->id,
                'client_task_list_id' => $facts['list'] === null ? null : $lists[$facts['list']]->id,
                'organization_position' => $facts['position'], 'lock_version' => (int) $task->lock_version + 1])->save();
        }
        $this->advance($records);
    }

    public function createList(Workspace $workspace, TaskOrganizationRecords $records, string $name, bool $visible): ClientTaskList
    {
        $list = ClientTaskList::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $records->project->id,
            'name' => $name, 'is_visible_to_client' => $visible]);
        $list->forceFill(['organization_position' => $records->lists->count(), 'lock_version' => 1])->save();
        $records->lists->push($list);
        $this->advance($records);

        return $list;
    }

    /** @param array<string, mixed> $attributes */
    public function updateList(TaskOrganizationRecords $records, ClientTaskList $list, array $attributes): void
    {
        $list->forceFill([...$attributes, 'lock_version' => (int) $list->lock_version + 1])->save();
        $this->advance($records);
    }

    /** @param list<string> $ids */
    public function reorderLists(TaskOrganizationRecords $records, array $ids): void
    {
        $lists = $records->lists->keyBy('public_id');
        foreach ($ids as $position => $id) {
            $list = $lists[$id];
            $list->forceFill(['organization_position' => $position, 'lock_version' => (int) $list->lock_version + 1])->save();
        }
        $this->advance($records);
    }

    private function advance(TaskOrganizationRecords $records): void
    {
        $this->revisions->advance($records->project->workspace, $records->project, $records->state);
    }
}
