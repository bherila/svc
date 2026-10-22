<?php

namespace App\Services\Tasks\Planning;

use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskContactResponsibility;
use App\Models\ClientTaskDependency;
use App\Models\ClientTaskLabel;
use App\Models\ClientTaskLabelAssignment;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskGraph;
use App\Services\Tasks\Organization\TaskOrganizationRecords;
use App\Services\Tasks\TaskAncestryFacts;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\AgentPageBudget;
use App\Support\AgentApi\CursorPage;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Scoped, bounded read facts. Actor authorization is supplied by the shared read service. */
final class TaskPlanningReadRepository
{
    public const int MAX_BATCH = 100;

    /** @param list<ClientTask> $tasks
     * @return array<int,TaskPlanningDetailsSnapshot> */
    public function summaries(Workspace $workspace, array $tasks): array
    {
        if (count($tasks) > self::MAX_BATCH) {
            throw new \LogicException('Task planning batches support at most 100 authorized tasks.');
        }
        if ($tasks === []) {
            return [];
        }
        foreach ($tasks as $task) {
            abort_unless($task->workspace_id === $workspace->id, 404);
        }
        $this->assertOrganization($workspace, $tasks);
        $ids = array_map(static fn (ClientTask $task): int => $task->id, $tasks);
        $labels = $this->attachedLabels($workspace, $ids);
        $outgoing = $this->dependencyCounts($workspace, $ids, false);
        $incoming = $this->dependencyCounts($workspace, $ids, true);
        $snapshots = [];
        foreach ($tasks as $task) {
            $unfinished = $outgoing[$task->id]['unfinished'] ?? 0;
            $snapshots[$task->id] = new TaskPlanningDetailsSnapshot($task->public_id, AgentApiVersion::for($task), $labels[$task->id] ?? [],
                ['blocked' => $unfinished > 0, 'incomplete_prerequisite_count' => $unfinished, 'dependent_count' => $incoming[$task->id]['count'] ?? 0]);
        }

        return $snapshots;
    }

    /** @return array{id:string,name:string,is_archived:bool,version:string} */
    public function label(Workspace $workspace, string $id): array
    {
        return $this->labelFacts(ClientTaskLabel::query()->where('workspace_id', $workspace->id)->where('public_id', $id)->firstOrFail());
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string}} */
    public function labels(Workspace $workspace, string $key, string $status, string $search, int $limit, ?string $cursor): array
    {
        $query = ClientTaskLabel::query()->where('workspace_id', $workspace->id)
            ->when($status !== 'all', fn ($query) => $query->where('is_archived', $status === 'archived'))
            ->when($search !== '', fn ($query) => $query->whereLike('name', '%'.$search.'%'));

        return CursorPage::run($workspace, $key, $limit, $cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit($take)->get(),
            fn (ClientTaskLabel $label): array => $this->labelFacts($label), AgentPageBudget::dataBytes(), allowLegacyCursor: false);
    }

    /** @return list<array{id:string,display_name:string,is_archived:bool,version:string}> */
    public function contacts(Workspace $workspace, ClientTask $task, ClientProject $project): array
    {
        abort_unless($task->workspace_id === $workspace->id && $task->client_project_id === $project->id, 404);
        $rows = ClientTaskContactResponsibility::query()->where('workspace_id', $workspace->id)->where('client_task_id', $task->id)->orderBy('id')->limit(11)->get();
        if ($rows->count() > 10) {
            $this->invalid();
        }
        foreach ($rows as $row) {
            if ($row->client_project_id !== $project->id || $row->client_company_id !== $project->client_company_id) {
                $this->invalid();
            }
        }
        $contacts = $this->contactQuery($workspace, $project)->whereIn('id', $rows->pluck('client_contact_id'))->get()->keyBy('id');
        $data = [];
        foreach ($rows as $row) {
            $contact = $contacts->get($row->client_contact_id);
            if ($contact === null) {
                $this->invalid();
            }
            $data[] = $this->contactFacts($contact);
        }

        return $data;
    }

    /** @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string}} */
    public function contactOptions(Workspace $workspace, ClientProject $project, string $key, string $search, int $limit, ?string $cursor): array
    {
        $query = $this->contactQuery($workspace, $project)->where('is_archived', false)
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->whereLike('first_name', '%'.$search.'%')->orWhereLike('last_name', '%'.$search.'%')));

        return CursorPage::run($workspace, $key, $limit, $cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn ($query) => $query->where('id', '>', $after))->orderBy('id')->limit($take)->get(),
            fn (ClientContact $contact): array => $this->contactFacts($contact), AgentPageBudget::dataBytes(), allowLegacyCursor: false);
    }

    /** @param Builder<ClientTask> $authorizedTasks
     * @param list<string> $ids
     * @return array{data:list<array<string,mixed>>,meta:array{next_cursor:?string}} */
    public function references(Workspace $workspace, Builder $authorizedTasks, array $ids, string $key, string $search, int $limit, ?string $cursor, bool $canManage, ?int $budget = null): array
    {
        $query = (clone $authorizedTasks)->where('client_tasks.workspace_id', $workspace->id)->whereIn('client_tasks.public_id', $ids)->with('project.clientCompany')
            ->when($search !== '', fn ($query) => $query->whereLike('client_tasks.title', '%'.$search.'%'));

        return CursorPage::run($workspace, $key, $limit, $cursor,
            fn (int $take, ?int $after) => (clone $query)->when($after !== null, fn ($query) => $query->where('client_tasks.id', '>', $after))->orderBy('client_tasks.id')->limit($take)->get(),
            fn (ClientTask $task): array => $this->referenceFacts($workspace, $task, $canManage), $budget ?? AgentPageBudget::dataBytes(), allowLegacyCursor: false);
    }

    /** Complete outgoing set, independent of incoming pagination.
     * @param Builder<ClientTask> $authorizedTasks
     * @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function prerequisites(Workspace $workspace, Builder $authorizedTasks, array $ids, bool $canManage): array
    {
        $tasks = (clone $authorizedTasks)->where('client_tasks.workspace_id', $workspace->id)->whereIn('client_tasks.public_id', $ids)->with('project.clientCompany')->orderBy('client_tasks.id')->limit(26)->get();
        if ($tasks->count() !== count($ids) || $tasks->count() > 25) {
            $this->invalid();
        }

        return array_values($tasks->map(fn (ClientTask $task): array => $this->referenceFacts($workspace, $task, $canManage))->all());
    }

    public function dependencyGraph(Workspace $workspace, TaskOrganizationRecords $records): TaskDependencyGraph
    {
        abort_unless($records->project->workspace_id === $workspace->id, 404);
        $rows = ClientTaskDependency::query()->where('workspace_id', $workspace->id)->where('client_project_id', $records->project->id)
            ->orderBy('id')->limit(TaskDependencyGraph::MAX_EDGES + 1)->get();
        $ids = $records->tasks->pluck('public_id', 'id')->all();
        $edges = [];
        foreach ($rows as $row) {
            $edges[] = new TaskDependencyEdge($ids[$row->client_task_id] ?? 'unavailable', $ids[$row->prerequisite_task_id] ?? 'unavailable');
        }

        return TaskDependencyGraph::fromFacts(array_values($ids), $edges);
    }

    /** Internal structural validation accepts retained archived lists; it is NEVER a disclosure predicate.
     * @param list<ClientTask> $tasks */
    public function assertOrganization(Workspace $workspace, array $tasks): void
    {
        if (count($tasks) > self::MAX_BATCH) {
            throw new \LogicException('Task planning batches support at most 100 authorized tasks.');
        }
        $pending = array_map(static fn (ClientTask $task): int => $task->id, $tasks);
        $projects = array_values(array_unique(array_map(static fn (ClientTask $task): int => $task->client_project_id, $tasks)));
        $rows = [];
        for ($depth = 0; $depth <= TaskAncestryFacts::MAX_DEPTH && $pending !== []; $depth++) {
            $next = [];
            foreach (DB::table('client_tasks')->where('workspace_id', $workspace->id)->whereIn('client_project_id', $projects)->whereIn('id', $pending)
                ->get(['id', 'client_project_id', 'parent_task_id', 'client_task_list_id']) as $row) {
                $rows[(int) $row->id] = $row;
                if ($row->parent_task_id !== null && ! isset($rows[(int) $row->parent_task_id])) {
                    $next[] = (int) $row->parent_task_id;
                }
            }
            $pending = array_values(array_unique($next));
        }
        $listIds = [];
        foreach ($rows as $row) {
            if ($row->client_task_list_id !== null) {
                if ($row->parent_task_id !== null) {
                    $this->invalid();
                }
                $listIds[] = (int) $row->client_task_list_id;
            }
        }
        $lists = DB::table('client_task_lists')->where('workspace_id', $workspace->id)->whereIn('client_project_id', $projects)->whereIn('id', $listIds)->get()->keyBy('id');
        $facts = [];
        $listFacts = [];
        foreach ($rows as $id => $row) {
            $parent = $row->parent_task_id === null ? null : ($rows[(int) $row->parent_task_id] ?? null);
            $list = $row->client_task_list_id === null ? null : $lists->get((int) $row->client_task_list_id);
            $facts['task:'.$id] = ['id' => $id, 'parent' => $row->parent_task_id === null ? null
                : ($parent !== null && $parent->client_project_id === $row->client_project_id ? 'task:'.$parent->id : 'unavailable'),
                'list' => $row->client_task_list_id === null ? null
                    : ($list !== null && $list->client_project_id === $row->client_project_id ? 'list:'.$list->id : 'unavailable'),
                'position' => 0, 'version' => ''];
            if ($list !== null) {
                $listFacts['list:'.$list->id] = ['id' => (int) $list->id, 'deleted' => $list->deleted_at !== null, 'position' => 0, 'version' => ''];
            }
        }
        try {
            $graph = new TaskGraph($facts, $listFacts, 0);
            foreach ($tasks as $task) {
                $graph->rootList('task:'.$task->id);
            }
        } catch (DomainException) {
            $this->invalid();
        }
    }

    /** @param list<int> $ids
     * @return array<int,list<array{id:string,name:string,is_archived:bool,version:string}>> */
    private function attachedLabels(Workspace $workspace, array $ids): array
    {
        $rows = ClientTaskLabelAssignment::query()->where('workspace_id', $workspace->id)->whereIn('client_task_id', $ids)->orderBy('id')->limit(count($ids) * 20 + 1)->get();
        $labels = ClientTaskLabel::query()->where('workspace_id', $workspace->id)->whereIn('id', $rows->pluck('client_task_label_id'))->get()->keyBy('id');
        $data = [];
        foreach ($rows as $row) {
            $label = $labels->get($row->client_task_label_id);
            if ($label === null || count($data[$row->client_task_id] ?? []) >= 20) {
                $this->invalid();
            }
            $data[$row->client_task_id][] = $this->labelFacts($label);
        }

        return $data;
    }

    /** @param list<int> $ids
     * @return array<int,array{count:int,unfinished:int}> */
    private function dependencyCounts(Workspace $workspace, array $ids, bool $incoming): array
    {
        $column = $incoming ? 'prerequisite_task_id' : 'client_task_id';
        $rows = DB::table('client_task_dependencies as dependencies')
            ->leftJoin('client_tasks as sources', fn (JoinClause $join) => $join->on('sources.id', '=', 'dependencies.client_task_id')->on('sources.workspace_id', '=', 'dependencies.workspace_id')->on('sources.client_project_id', '=', 'dependencies.client_project_id'))
            ->leftJoin('client_tasks as prerequisites', fn (JoinClause $join) => $join->on('prerequisites.id', '=', 'dependencies.prerequisite_task_id')->on('prerequisites.workspace_id', '=', 'dependencies.workspace_id')->on('prerequisites.client_project_id', '=', 'dependencies.client_project_id'))
            ->where('dependencies.workspace_id', $workspace->id)->whereIn('dependencies.'.$column, $ids)->groupBy('dependencies.'.$column)
            ->selectRaw('dependencies.'.$column.' as task_id, COUNT(*) as total, SUM(CASE WHEN sources.id IS NULL OR prerequisites.id IS NULL THEN 1 ELSE 0 END) as invalid_count, SUM(CASE WHEN prerequisites.status <> ? THEN 1 ELSE 0 END) as unfinished', ['completed'])->get();
        $data = [];
        foreach ($rows as $row) {
            if ((int) $row->invalid_count > 0 || (! $incoming && (int) $row->total > 25)) {
                $this->invalid();
            }
            $data[(int) $row->task_id] = ['count' => (int) $row->total, 'unfinished' => (int) $row->unfinished];
        }

        return $data;
    }

    /** @return Builder<ClientContact> */
    private function contactQuery(Workspace $workspace, ClientProject $project): Builder
    {
        abort_unless($project->workspace_id === $workspace->id, 404);

        return ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $project->client_company_id)
            ->select(['id', 'public_id', 'first_name', 'last_name', 'is_archived', 'lock_version']);
    }

    /** @return array{id:string,name:string,is_archived:bool,version:string} */
    private function labelFacts(ClientTaskLabel $label): array
    {
        return ['id' => $label->public_id, 'name' => $label->name, 'is_archived' => $label->is_archived, 'version' => AgentApiVersion::for($label)];
    }

    /** @return array{id:string,display_name:string,is_archived:bool,version:string} */
    private function contactFacts(ClientContact $contact): array
    {
        $name = mb_trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''), encoding: 'UTF-8');

        return ['id' => $contact->public_id, 'display_name' => $name === '' ? 'Unnamed contact' : $name,
            'is_archived' => $contact->is_archived, 'version' => AgentApiVersion::for($contact)];
    }

    /** @return array<string,mixed> */
    private function referenceFacts(Workspace $workspace, ClientTask $task, bool $canManage): array
    {
        return ['id' => $task->public_id, 'title' => $task->title, 'status' => $task->status, 'version' => AgentApiVersion::for($task),
            'web_url' => $canManage ? route('tasks.show', [$workspace, $task]) : route('clients.tasks', [$workspace, $task->project->clientCompany, 'project' => $task->project->public_id])];
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['planning' => 'The task organization is invalid.']);
    }
}
