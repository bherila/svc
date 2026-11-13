<?php

namespace App\Services\SharedProjects;

use App\Models\AgentPrincipal;
use App\Models\ClientComment;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientProjectParticipation;
use App\Models\ClientProjectParticipationInvitation;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Authorization\ProjectAccess;
use App\Services\Tasks\TaskVisibility;
use App\Support\Access\StaffRole;
use App\Support\AgentApi\CursorPage;
use App\Support\SharedProjects\AssigneeEligibility;
use App\Support\SharedProjects\SharedTaskScope;
use App\Support\WorkspaceClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a viewer is shown of a shared project.
 *
 * Every read here starts from a {@see SharedProjectContext}, so "the host, or an
 * active participation" was decided before a single row is selected. Rows are
 * then read by an explicit host workspace and project id, and a participant's
 * rows are selected column by column from {@see SharedTaskScope}: the host's
 * milestone prices, invoice links, lists, labels, contacts, rates, time and
 * internal notes are never in a participant's query, so there is nothing for a
 * presenter to strip. All reads are bounded; none runs a query per row.
 */
final class SharedProjectReadService
{
    public const int PAGE = 50;

    public const int PARTICIPANTS = 25;

    public const int OPTIONS = 100;

    public function __construct(
        private readonly SharedProjectParticipations $participations,
        private readonly ProjectAccess $roles,
        private readonly TaskVisibility $visibility,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * The shared projects a workspace hosts or takes part in.
     *
     * @return array{hosted: list<array<string, mixed>>, participating: list<array<string, mixed>>}
     */
    public function index(User|AgentPrincipal $actor, Workspace $workspace): array
    {
        $role = StaffRole::tryFrom((string) $this->roles->workspaceRole($actor, $workspace));
        if ($role === null) {
            return ['hosted' => [], 'participating' => []];
        }

        $viewable = $this->roles->viewableProjectIds($actor, $workspace);
        $hostedQuery = ClientProject::query()->where('workspace_id', $workspace->id)
            ->where(fn (Builder $shared): Builder => $shared
                ->whereIn('id', ClientProjectParticipation::query()->where('host_workspace_id', $workspace->id)->whereNotNull('active_marker')->select('client_project_id'))
                ->orWhereIn('id', ClientProjectParticipationInvitation::query()->where('host_workspace_id', $workspace->id)
                    ->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')
                    ->where('expires_at', '>', $this->clock->instant())->select('client_project_id')));
        if ($viewable !== null) {
            $hostedQuery->whereIn('id', $viewable);
        }
        $hosted = $hostedQuery->orderBy('name')->orderBy('id')->limit(self::OPTIONS)->get();
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->whereIn('id', $hosted->pluck('client_company_id')->all())->pluck('name', 'id');

        $participating = ClientProjectParticipation::query()
            ->where('participant_workspace_id', $workspace->id)->whereNotNull('active_marker')
            ->with(['hostWorkspace', 'project'])->orderBy('id')->limit(self::OPTIONS)->get();

        return [
            'hosted' => array_values($hosted->map(fn (ClientProject $project): array => [
                'id' => $project->public_id,
                'name' => $project->name,
                'client_name' => (string) ($companies[$project->client_company_id] ?? ''),
                'web_url' => route('shared-projects.show', [$workspace, $project->public_id], absolute: false),
            ])->all()),
            'participating' => array_values($participating->filter(fn (ClientProjectParticipation $participation): bool => $participation->project !== null)
                ->map(fn (ClientProjectParticipation $participation): array => [
                    'id' => $participation->project->public_id,
                    'name' => $participation->project->name,
                    'host_workspace_name' => $participation->hostWorkspace->name,
                    'web_url' => route('shared-projects.show', [$workspace, $participation->project->public_id], absolute: false),
                ])->all()),
        ];
    }

    /** @return array<string, mixed> */
    public function project(SharedProjectContext $context, User|AgentPrincipal $actor): array
    {
        $viewer = $context->viewer;
        $project = $context->project;
        $data = [
            'id' => $project->public_id,
            'name' => $project->name,
            'status' => $project->status,
            'side' => $viewer->side->value,
            'host_workspace_name' => $context->host->name,
            'viewer' => [
                'sees_whole_project' => $viewer->seesWholeProject(),
                'can_assign' => $viewer->canAssign(),
                'can_manage_participants' => $viewer->canManageParticipants(),
                'can_act_as_participant' => $viewer->canActForParticipant(),
            ],
            'web_url' => route('shared-projects.show', [$context->viewerWorkspace, $project->public_id], absolute: false),
        ];

        if ($context->isHost()) {
            $company = ClientCompany::query()->where('workspace_id', $context->host->id)->whereKey($project->client_company_id)->first();
            $data['client_name'] = $company?->name;
            $data['description'] = $project->description;
        }
        if ($viewer->canSeeParticipants()) {
            $data['participants'] = $this->participants($context);
            $data['invitations'] = $viewer->canManageParticipants() ? $this->invitations($context) : [];
        }
        if ($context->participation !== null) {
            $data['participation'] = ['accepted_at' => $context->participation->accepted_at->toIso8601String()];
            if ($viewer->canActForParticipant()) {
                $local = $this->participations->localProject($context->participation);
                $data['local_project'] = $local === null ? null : ['id' => $local->public_id, 'name' => $local->name];
            }
        }
        if ($viewer->canAssign()) {
            $data['assignee_options'] = $this->assigneeOptions($context);
        }

        return $data;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string}}
     */
    public function tasks(SharedProjectContext $context, User|AgentPrincipal $actor, int $limit = self::PAGE, ?string $cursor = null): array
    {
        $limit = max(1, min(self::PAGE, $limit));

        $page = CursorPage::run($context->viewerWorkspace, 'shared-tasks:'.$context->project->public_id, $limit, $cursor,
            fn (int $take, ?int $after): Collection => $this->guard($context, $actor, $this->visibleTasks($context, $actor)
                ->when($after !== null, fn (Builder $query): Builder => $query->where('client_tasks.id', '>', $after))
                ->orderBy('client_tasks.id')->limit($take)->get()),
            fn (ClientTask $task): array => $this->task($context, $task),
        );

        $ids = ClientTask::query()->where('workspace_id', $context->host->id)->whereIn('public_id', array_column($page['data'], 'id'))->pluck('id', 'public_id');
        $taskIds = array_values($ids->map(static fn (mixed $id): int => (int) $id)->all());
        $assignees = $this->assignees($context, $taskIds);
        $assigned = $this->assignedIds($context, $actor, $taskIds);
        $page['data'] = array_map(function (array $row) use ($ids, $assignees, $assigned, $context): array {
            $id = (int) ($ids[$row['id']] ?? 0);
            $row['assignees'] = $assignees[$id] ?? [];
            // Every row here passed the shared-task constraint, so it is shared.
            $row['can_comment'] = $context->viewer->canComment(true, isset($assigned[$id]));

            return $row;
        }, $page['data']);

        return $page;
    }

    /** One task the viewer may see, or null. */
    public function findTask(SharedProjectContext $context, User|AgentPrincipal $actor, string $taskPublicId): ?ClientTask
    {
        return $this->guard($context, $actor, $this->visibleTasks($context, $actor)->where('client_tasks.public_id', $taskPublicId)->get())->first();
    }

    /**
     * One comment the viewer may see, as the writer or a replay reads it back.
     *
     * @return array<string, mixed>|null
     */
    public function comment(SharedProjectContext $context, User|AgentPrincipal $actor, string $taskPublicId, string $commentPublicId): ?array
    {
        $task = $this->findTask($context, $actor, $taskPublicId);
        if ($task === null) {
            return null;
        }
        $comment = ClientComment::query()
            ->where('workspace_id', $context->host->id)->where('client_task_id', $task->id)->whereNull('deleted_at')
            ->where('public_id', $commentPublicId)
            ->when(! $context->viewer->canSeeComment(false), fn (Builder $query): Builder => $query->where('shared_with_participants', true))
            ->first();
        if ($comment === null) {
            return null;
        }
        $names = Workspace::query()->whereIn('id', array_filter([$context->host->id, $comment->author_workspace_id]))->pluck('name', 'id')->all();

        return $this->commentRow($context, $comment, $names);
    }

    /**
     * Who one task is assigned to, with the workspace each stands in.
     *
     * @return list<array<string, string>>
     */
    public function taskAssignees(SharedProjectContext $context, ClientTask $task): array
    {
        return $this->assignees($context, [(int) $task->id])[(int) $task->id] ?? [];
    }

    /**
     * Which of these tasks are assigned to the viewer, standing in their own workspace.
     *
     * @param  list<int>  $taskIds
     * @return array<int, true>
     */
    public function assignedIds(SharedProjectContext $context, User|AgentPrincipal $actor, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $assigned = [];
        $rows = ClientTaskAssignment::query()
            ->where('workspace_id', $context->host->id)->where('user_id', $actor->id)
            ->whereIn('client_task_id', $taskIds)
            ->when(! $context->isHost(), fn (Builder $query): Builder => $query->where('assignee_workspace_id', $context->viewerWorkspace->id))
            ->when($context->isHost(), fn (Builder $query): Builder => $query->whereNull('assignee_workspace_id'))
            ->pluck('client_task_id');
        foreach ($rows as $id) {
            $assigned[(int) $id] = true;
        }

        return $assigned;
    }

    /**
     * The same rule as the query, applied again in PHP from the pure viewer
     * rules: a row the rules refuse is dropped even if the query let it by.
     *
     * @param  Collection<int, ClientTask>  $tasks
     * @return Collection<int, ClientTask>
     */
    private function guard(SharedProjectContext $context, User|AgentPrincipal $actor, Collection $tasks): Collection
    {
        $assigned = $this->assignedIds($context, $actor, array_values($tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all()));

        return $tasks->filter(fn (ClientTask $task): bool => $context->viewer->canSeeTask((bool) $task->is_visible_to_client, isset($assigned[(int) $task->id])))->values();
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{next_cursor: ?string}}|null null when the viewer may not see the task
     */
    public function comments(SharedProjectContext $context, User|AgentPrincipal $actor, string $taskPublicId, int $limit = self::PAGE, ?string $cursor = null): ?array
    {
        $task = $this->findTask($context, $actor, $taskPublicId);
        if ($task === null) {
            return null;
        }
        $limit = max(1, min(self::PAGE, $limit));
        // Only the workspaces that wrote this page, never the whole table.
        $names = [];

        return CursorPage::run($context->viewerWorkspace, 'shared-comments:'.$task->public_id, $limit, $cursor,
            function (int $take, ?int $after) use ($context, $task, &$names): Collection {
                $comments = ClientComment::query()
                    ->where('workspace_id', $context->host->id)->where('client_task_id', $task->id)->whereNull('deleted_at')
                    ->when(! $context->viewer->canSeeComment(false), fn (Builder $query): Builder => $query->where('shared_with_participants', true))
                    ->when($after !== null, fn (Builder $query): Builder => $query->where('id', '>', $after))
                    ->orderBy('id')->limit($take)->get();
                $names = Workspace::query()->whereIn('id', [$context->host->id, ...$comments->pluck('author_workspace_id')->filter()->unique()->all()])->pluck('name', 'id')->all();

                return $comments;
            },
            fn (ClientComment $comment): array => $this->commentRow($context, $comment, $names),
        );
    }

    /**
     * @param  array<int, string>  $names  workspace names by id, for the workspaces that wrote
     * @return array<string, mixed>
     */
    public function commentRow(SharedProjectContext $context, ClientComment $comment, array $names): array
    {
        return [
            'id' => $comment->public_id,
            'body' => $comment->body,
            'author_name' => $comment->author_name,
            'author_workspace_name' => (string) ($names[$comment->author_workspace_id ?? $context->host->id] ?? ''),
            'shared_with_participants' => $comment->shared_with_participants,
            'created_at' => $comment->created_at?->toIso8601String(),
        ];
    }

    /**
     * The people a task can be handed to: the host's staff on the project and
     * each participant's eligible people. Only a viewer who may assign is told.
     *
     * @return list<array{user_id: string, name: string, workspace_id: string, workspace_name: string}>
     */
    public function assigneeOptions(SharedProjectContext $context): array
    {
        if (! $context->viewer->canAssign()) {
            return [];
        }
        $host = $context->host;
        $options = [];

        $onProject = DB::table('client_project_memberships')->where('workspace_id', $host->id)
            ->where('client_project_id', $context->project->id)->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all();
        $hostRows = DB::table('workspace_memberships')->where('workspace_memberships.workspace_id', $host->id)
            ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where(fn ($people) => $people->whereIn('workspace_memberships.role', ['owner', 'admin'])->orWhereIn('workspace_memberships.user_id', $onProject))
            ->select('users.public_id as user_public_id', 'users.name', 'workspace_memberships.role', 'workspace_memberships.user_id')
            ->orderBy('users.name')->orderBy('users.id')->limit(self::OPTIONS)->get();
        foreach ($hostRows as $row) {
            if (AssigneeEligibility::eligible(StaffRole::tryFrom((string) $row->role), in_array((int) $row->user_id, $onProject, true))) {
                $options[] = ['user_id' => (string) $row->user_public_id, 'name' => (string) $row->name, 'workspace_id' => $host->public_id, 'workspace_name' => $host->name];
            }
        }

        $participations = ClientProjectParticipation::query()
            ->where('host_workspace_id', $host->id)->where('client_project_id', $context->project->id)->whereNotNull('active_marker')
            ->with('participantWorkspace')->orderBy('id')->limit(self::PARTICIPANTS)->get();
        if ($participations->isEmpty()) {
            return $options;
        }
        $linked = ClientProject::query()->whereIn('workspace_id', $participations->pluck('participant_workspace_id')->all())
            ->whereIn('shared_participation_id', $participations->pluck('id')->all())->pluck('id', 'workspace_id');
        $onLocal = ClientProjectMembership::query()->whereIn('client_project_id', $linked->values()->all())->get(['workspace_id', 'client_project_id', 'user_id']);
        $localMembers = [];
        foreach ($onLocal as $membership) {
            if ((int) ($linked[$membership->workspace_id] ?? 0) === (int) $membership->client_project_id) {
                $localMembers[$membership->workspace_id][$membership->user_id] = true;
            }
        }
        $localUserIds = array_values(array_unique($onLocal->pluck('user_id')->map(static fn (mixed $id): int => (int) $id)->all()));
        $memberRows = DB::table('workspace_memberships')->whereIn('workspace_memberships.workspace_id', $participations->pluck('participant_workspace_id')->all())
            ->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where(fn ($people) => $people->whereIn('workspace_memberships.role', ['owner', 'admin'])->orWhereIn('workspace_memberships.user_id', $localUserIds))
            ->select('workspace_memberships.workspace_id', 'workspace_memberships.user_id', 'workspace_memberships.role', 'users.public_id as user_public_id', 'users.name')
            ->orderBy('workspace_memberships.workspace_id')->orderBy('users.name')->orderBy('users.id')->limit(self::OPTIONS * self::PARTICIPANTS)->get();
        $workspaces = $participations->mapWithKeys(fn (ClientProjectParticipation $participation): array => [$participation->participant_workspace_id => $participation->participantWorkspace]);
        foreach ($memberRows as $row) {
            $workspace = $workspaces[$row->workspace_id] ?? null;
            if ($workspace === null || ! AssigneeEligibility::eligible(StaffRole::tryFrom((string) $row->role), isset($localMembers[$row->workspace_id][$row->user_id]))) {
                continue;
            }
            $options[] = ['user_id' => (string) $row->user_public_id, 'name' => (string) $row->name, 'workspace_id' => $workspace->public_id, 'workspace_name' => $workspace->name];
        }

        return $options;
    }

    /** @return Builder<ClientTask> */
    private function visibleTasks(SharedProjectContext $context, User|AgentPrincipal $actor): Builder
    {
        $query = ClientTask::query()
            ->where('client_tasks.workspace_id', $context->host->id)
            ->where('client_tasks.client_project_id', $context->project->id);
        $viewer = $context->viewer;
        if (! $viewer->canViewProject()) {
            return $query->whereRaw('1 = 0');
        }
        if ($context->isHost()) {
            return $query;
        }

        // A participant: named columns only, and only tasks already shown to
        // the client. A member who does not manage their workspace sees what
        // is assigned to them from that workspace, and nothing else.
        $query->select(array_map(static fn (string $column): string => 'client_tasks.'.$column, SharedTaskScope::COLUMNS));
        $this->visibility->constrainVisibleAncestry($query, $context->host);
        if (! $viewer->seesWholeProject()) {
            $query->whereExists(function ($assignments) use ($context, $actor): void {
                $assignments->selectRaw('1')->from('client_task_assignments')
                    ->whereColumn('client_task_assignments.client_task_id', 'client_tasks.id')
                    ->where('client_task_assignments.workspace_id', $context->host->id)
                    ->where('client_task_assignments.user_id', $actor->id)
                    ->where('client_task_assignments.assignee_workspace_id', $context->viewerWorkspace->id);
            });
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function task(SharedProjectContext $context, ClientTask $task): array
    {
        return [
            'id' => $task->public_id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'shared_with_participants' => (bool) $task->is_visible_to_client,
            'starts_on' => $task->starts_on?->toDateString(),
            'due_on' => $task->due_on?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'web_url' => route('shared-projects.show', [$context->viewerWorkspace, $context->project->public_id], absolute: false).'#task-'.$task->public_id,
        ];
    }

    /**
     * Assignees of a page of tasks, in one query, each with the workspace they
     * stand in. A person's id is told only to a viewer who may assign.
     *
     * @param  list<int>  $taskIds
     * @return array<int, list<array<string, string>>>
     */
    private function assignees(SharedProjectContext $context, array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }
        $rows = DB::table('client_task_assignments')->where('client_task_assignments.workspace_id', $context->host->id)
            ->whereIn('client_task_assignments.client_task_id', $taskIds)
            ->join('users', 'users.id', '=', 'client_task_assignments.user_id')
            ->leftJoin('workspaces', 'workspaces.id', '=', 'client_task_assignments.assignee_workspace_id')
            ->orderBy('client_task_assignments.id')
            ->select(['client_task_assignments.client_task_id', 'users.public_id as user_public_id', 'users.name as user_name', 'workspaces.name as workspace_name', 'workspaces.public_id as workspace_public_id'])->get();
        $showIds = $context->viewer->canAssign();
        $result = [];
        foreach ($rows as $row) {
            $entry = ['name' => (string) $row->user_name, 'workspace_name' => (string) ($row->workspace_name ?? $context->host->name)];
            if ($showIds) {
                $entry = ['user_id' => (string) $row->user_public_id, 'workspace_id' => (string) ($row->workspace_public_id ?? $context->host->public_id)] + $entry;
            }
            $result[(int) $row->client_task_id][] = $entry;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function participants(SharedProjectContext $context): array
    {
        return array_values(ClientProjectParticipation::query()
            ->where('host_workspace_id', $context->host->id)->where('client_project_id', $context->project->id)->whereNotNull('active_marker')
            ->with('participantWorkspace')->orderBy('id')->limit(self::PARTICIPANTS)->get()
            ->map(fn (ClientProjectParticipation $participation): array => [
                'id' => $participation->public_id,
                'workspace_name' => $participation->participantWorkspace->name,
                'accepted_at' => $participation->accepted_at->toIso8601String(),
            ])->all());
    }

    /** @return list<array<string, mixed>> */
    private function invitations(SharedProjectContext $context): array
    {
        return array_values(ClientProjectParticipationInvitation::query()
            ->where('host_workspace_id', $context->host->id)->where('client_project_id', $context->project->id)
            ->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->instant())
            ->orderBy('id')->limit(self::PARTICIPANTS)->get()
            ->map(fn (ClientProjectParticipationInvitation $invitation): array => [
                'id' => $invitation->public_id,
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])->all());
    }
}
