<?php

namespace App\Queries;

use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\Tasks\TaskAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\ProjectRole;
use App\Support\MyWork\TaskBuckets;
use App\Support\MyWork\TimeRollup;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the "My work" page shows one person (docs/ux-overhaul.md section 5):
 * the tasks assigned to them, a way to log time, and the time they have logged.
 *
 * It is the person's own work and nothing else, by construction rather than by
 * a filter applied late:
 *
 * - Tasks come through the person's assignment rows, in projects they hold.
 * - Time is `user_id = me`, in projects they hold. The rows are read as a
 *   project, a day, whole minutes and a state - the billing rate, currency,
 *   subcontractor cost and billing mode are never selected, so there is no
 *   amount to leak and nothing for a presenter to forget to strip.
 * - Nothing from the revenue read model, an invoice or another person's time is
 *   read at all.
 *
 * Every read is keyed by the workspace and bounded by a constant, so the page
 * costs the same handful of queries whatever the size of the workspace.
 */
final class MyWorkQuery
{
    public const int TASKS = 50;

    public const int PROJECTS = 100;

    /** Entries read for the week and month roll-ups; a person logging more than this a month is not a case the page is for. */
    public const int TIME_ENTRIES = 1000;

    public function __construct(
        private readonly ProjectAccess $access,
        private readonly TaskAccess $taskAccess,
        private readonly WorkspaceClock $clock,
    ) {}

    /**
     * @return array{today: string, tasks: list<array<string, mixed>>, projects: list<array<string, mixed>>, time: array<string, mixed>}
     */
    public function for(Workspace $workspace, User $user): array
    {
        $today = $this->clock->today($workspace)->toDateString();
        // Null is an owner or admin, who reaches every project; an empty list
        // is a member granted nothing, and the two must not collapse.
        $viewable = $this->access->viewableProjectIds($user, $workspace);

        return [
            'today' => $today,
            'tasks' => (new TaskBuckets($today))->group($this->tasks($workspace, $user, $viewable)),
            'projects' => $this->projects($workspace, $user, $viewable),
            'time' => $this->time($workspace, $user, $viewable, new TimeRollup($today)),
        ];
    }

    /**
     * @param  list<int>|null  $viewable
     * @return list<array{id: string, title: string, status: string, due_on: string|null, project: string, client: string, href: string, update_url: string|null, version: string|null}>
     */
    private function tasks(Workspace $workspace, User $user, ?array $viewable): array
    {
        if ($viewable === []) {
            return [];
        }

        $tasks = ClientTask::query()
            ->select('client_tasks.*')
            ->join('client_task_assignments', function ($join) use ($workspace, $user): void {
                $join->on('client_task_assignments.client_task_id', '=', 'client_tasks.id')
                    ->where('client_task_assignments.workspace_id', '=', $workspace->id)
                    ->where('client_task_assignments.user_id', '=', $user->id);
            })
            ->where('client_tasks.workspace_id', $workspace->id)
            ->when($viewable !== null, fn (Builder $query): Builder => $query->whereIn('client_tasks.client_project_id', $viewable ?? []))
            ->where('client_tasks.status', '!=', 'completed')
            ->orderByRaw('client_tasks.due_on is null')->orderBy('client_tasks.due_on')->orderBy('client_tasks.id')
            ->limit(self::TASKS)
            ->get();

        $projects = $this->projectNames($workspace, $tasks->pluck('client_project_id')->all());
        // Changing a status is a task edit, which only a project owner or
        // manager may make, so the URL is sent to them and to nobody else.
        $manageable = $this->taskAccess->manageableProjectIds($user, $workspace);

        // A task whose project or client does not resolve inside this workspace
        // is left out rather than shown nameless.
        return array_values($tasks->filter(fn (ClientTask $task): bool => isset($projects[(int) $task->client_project_id]))->map(function (ClientTask $task) use ($workspace, $projects, $manageable): array {
            $project = $projects[(int) $task->client_project_id];
            $canManage = $manageable === null || in_array((int) $task->client_project_id, $manageable, true);

            return [
                'id' => (string) $task->public_id,
                'title' => (string) $task->title,
                'status' => (string) $task->status,
                'due_on' => $task->due_on?->toDateString(),
                'project' => $project['name'],
                'client' => $project['client'],
                'href' => route('tasks.show', [$workspace, $task], absolute: false),
                'update_url' => $canManage ? route('agent-api.v1.tasks.update', [$workspace, $task->public_id], absolute: false) : null,
                'version' => $canManage ? AgentApiVersion::for($task) : null,
            ];
        })->all());
    }

    /**
     * The projects this person may log time on: those where they are an owner,
     * manager or contributor (a viewer cannot log), or every project for an
     * owner or admin.
     *
     * @param  list<int>|null  $viewable
     * @return list<array{id: string, name: string, client: string, log_time_url: string}>
     */
    private function projects(Workspace $workspace, User $user, ?array $viewable): array
    {
        $loggable = $viewable;
        if ($viewable !== null) {
            if ($viewable === []) {
                return [];
            }
            $loggable = array_values(ClientProjectMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('user_id', $user->id)
                ->whereIn('client_project_id', $viewable)
                ->whereIn('role', [ProjectRole::Owner->value, ProjectRole::Manager->value, ProjectRole::Contributor->value])
                ->pluck('client_project_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all());
            if ($loggable === []) {
                return [];
            }
        }

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'active')
            ->when($loggable !== null, fn (Builder $query): Builder => $query->whereIn('id', $loggable ?? []))
            ->whereHas('clientCompany', fn (Builder $company): Builder => $company->where('workspace_id', $workspace->id))
            ->orderBy('name')->orderBy('id')
            ->limit(self::PROJECTS)
            ->get();
        $clients = $this->projectNames($workspace, $projects->pluck('id')->all());
        $url = route('agent-api.v1.time-entries.store', $workspace, absolute: false);

        return array_values($projects->map(fn (ClientProject $project): array => [
            'id' => (string) $project->public_id,
            'name' => (string) $project->name,
            'client' => $clients[(int) $project->id]['client'] ?? '',
            'log_time_url' => $url,
        ])->all());
    }

    /**
     * @param  list<int>|null  $viewable
     * @return array<string, mixed>
     */
    private function time(Workspace $workspace, User $user, ?array $viewable, TimeRollup $rollup): array
    {
        if ($viewable === []) {
            return $rollup->summarize([]);
        }

        // Named columns only: no rate, currency, cost or billing mode is ever
        // read, so none can be sent.
        $entries = ClientTimeEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->when($viewable !== null, fn (Builder $query): Builder => $query->whereIn('client_project_id', $viewable ?? []))
            ->where('worked_on', '>=', $rollup->earliest())
            ->orderByDesc('worked_on')->orderByDesc('id')
            ->limit(self::TIME_ENTRIES)
            ->get(['id', 'client_project_id', 'worked_on', 'minutes', 'status']);

        $projects = $this->projectNames($workspace, $entries->pluck('client_project_id')->all());

        return $rollup->summarize(array_values($entries->filter(fn (ClientTimeEntry $entry): bool => isset($projects[(int) $entry->client_project_id]))->map(fn (ClientTimeEntry $entry): array => [
            'project_id' => $projects[(int) $entry->client_project_id]['public_id'],
            'project' => $projects[(int) $entry->client_project_id]['name'],
            'worked_on' => $entry->worked_on->toDateString(),
            'minutes' => (int) $entry->minutes,
            'status' => (string) $entry->status,
        ])->all()));
    }

    /**
     * Each project's name and its client's, in one read scoped to the
     * workspace on both tables.
     *
     * @param  array<mixed>  $ids
     * @return array<int, array{name: string, client: string, public_id: string}>
     */
    private function projectNames(Workspace $workspace, array $ids): array
    {
        $ids = array_values(array_unique(array_map(fn (mixed $id): int => (int) $id, $ids)));
        if ($ids === []) {
            return [];
        }

        $names = [];
        $rows = ClientProject::query()
            ->toBase()
            ->join('client_companies', 'client_companies.id', '=', 'client_projects.client_company_id')
            ->where('client_projects.workspace_id', $workspace->id)
            ->where('client_companies.workspace_id', $workspace->id)
            ->whereIn('client_projects.id', $ids)
            ->get(['client_projects.id', 'client_projects.name as project', 'client_projects.public_id', 'client_companies.name as client']);
        foreach ($rows as $row) {
            $names[(int) $row->id] = ['name' => (string) $row->project, 'client' => (string) $row->client, 'public_id' => (string) $row->public_id];
        }

        return $names;
    }
}
