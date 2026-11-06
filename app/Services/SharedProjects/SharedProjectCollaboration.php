<?php

namespace App\Services\SharedProjects;

use App\Models\ClientComment;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Concurrency\Locks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The two writes a participant's people can make on a host's task: comment on
 * it, and be handed it.
 *
 * Both run under the host workspace's row lock - the row every participation
 * change also takes - and decide again from what they read there, so a revoke
 * that commits first wins: the next write finds no participation and is a 404.
 * Neither can name a workspace or an author: the comment's workspace is the
 * host's, its author workspace comes from the resolved standing, and an
 * assignee must be one of the people {@see SharedProjectReadService::assigneeOptions()}
 * offers.
 */
final class SharedProjectCollaboration
{
    public const int ASSIGNEES = 25;

    public function __construct(
        private readonly SharedProjectAccess $access,
        private readonly SharedProjectReadService $reads,
    ) {}

    /**
     * Add a comment to a task's shared conversation.
     *
     * @return array<string, mixed> the comment as the writer sees it
     */
    public function comment(User $actor, Workspace $viewerWorkspace, string $projectPublicId, string $taskPublicId, string $body): array
    {
        $data = Validator::make(['body' => $body], ['body' => ['required', 'string', 'max:50000']])->validate();
        $context = $this->context($actor, $viewerWorkspace, $projectPublicId);

        return DB::transaction(function () use ($actor, $viewerWorkspace, $projectPublicId, $taskPublicId, $data, $context): array {
            Workspace::query()->whereKey($context->host->id)->tap(Locks::forUpdate())->firstOrFail();
            $locked = $this->context($actor, $viewerWorkspace, $projectPublicId);
            $task = $this->reads->findTask($locked, $actor, $taskPublicId);
            abort_if($task === null, 404);
            $assigned = $this->reads->assignedIds($locked, $actor, [(int) $task->id]);
            abort_unless($locked->viewer->canComment((bool) $task->is_visible_to_client, isset($assigned[(int) $task->id])), 403);

            $comment = new ClientComment;
            $comment->forceFill([
                'workspace_id' => $locked->host->id,
                'client_company_id' => $locked->project->client_company_id,
                'client_project_id' => $locked->project->id,
                'client_task_id' => $task->id,
                'body' => trim($data['body']),
                'author_user_id' => $actor->id,
                'author_name' => $actor->name,
                'is_visible_to_client' => false,
                'shared_with_participants' => true,
                'author_workspace_id' => $locked->isHost() ? null : $locked->viewerWorkspace->id,
            ])->save();

            return $this->reads->commentRow($locked, $comment, [(int) $locked->host->id => $locked->host->name, (int) $locked->viewerWorkspace->id => $locked->viewerWorkspace->name]);
        });
    }

    /**
     * Replace who a task is assigned to, across the host and its participants.
     * People already assigned stay without being offered again; anyone new must
     * be offered.
     *
     * @param  list<array{user_id: string, workspace_id: string}>  $people
     * @return list<array{user_id: string, workspace_id: string}>
     */
    public function assign(User $actor, Workspace $viewerWorkspace, string $projectPublicId, string $taskPublicId, array $people): array
    {
        /** @var array{assignees: list<array{user_id: string, workspace_id: string}>} $data */
        $data = Validator::make(['assignees' => $people], [
            'assignees' => ['present', 'array', 'max:'.self::ASSIGNEES],
            'assignees.*.user_id' => ['required', 'uuid'],
            'assignees.*.workspace_id' => ['required', 'uuid'],
        ])->validate();
        $context = $this->context($actor, $viewerWorkspace, $projectPublicId);
        abort_unless($context->viewer->canAssign(), 403);

        return DB::transaction(function () use ($actor, $viewerWorkspace, $projectPublicId, $taskPublicId, $data, $context): array {
            Workspace::query()->whereKey($context->host->id)->tap(Locks::forUpdate())->firstOrFail();
            $locked = $this->context($actor, $viewerWorkspace, $projectPublicId);
            abort_unless($locked->viewer->canAssign(), 403);
            $task = $this->reads->findTask($locked, $actor, $taskPublicId);
            abort_if($task === null, 404);

            $offered = [];
            foreach ($this->reads->assigneeOptions($locked) as $option) {
                $offered[$option['workspace_id'].'|'.$option['user_id']] = true;
            }
            $current = $this->current($task, $locked->host);
            $users = User::query()->whereIn('public_id', array_column($data['assignees'], 'user_id'))->pluck('id', 'public_id');
            $workspaces = Workspace::query()->whereIn('public_id', array_column($data['assignees'], 'workspace_id'))->pluck('id', 'public_id');

            $desired = [];
            foreach ($data['assignees'] as $person) {
                $key = $person['workspace_id'].'|'.$person['user_id'];
                $userId = isset($users[$person['user_id']]) ? (int) $users[$person['user_id']] : null;
                $workspaceId = isset($workspaces[$person['workspace_id']]) ? (int) $workspaces[$person['workspace_id']] : null;
                if ($userId === null || $workspaceId === null || (! isset($offered[$key]) && ! isset($current[$userId.'|'.$workspaceId]))) {
                    throw ValidationException::withMessages(['assignees' => 'Choose people offered for this project.']);
                }
                if (isset($desired[$userId])) {
                    throw ValidationException::withMessages(['assignees' => 'A person can be assigned a task once.']);
                }
                $desired[$userId] = (int) $workspaceId === (int) $locked->host->id ? null : (int) $workspaceId;
            }

            ClientTaskAssignment::query()->where('workspace_id', $locked->host->id)->where('client_task_id', $task->id)
                ->when($desired !== [], fn ($query) => $query->whereNotIn('user_id', array_keys($desired)))->delete();
            foreach ($desired as $userId => $assigneeWorkspaceId) {
                $assignment = ClientTaskAssignment::query()
                    ->where('workspace_id', $locked->host->id)->where('client_task_id', $task->id)->where('user_id', $userId)->first()
                    ?? new ClientTaskAssignment;
                $assignment->forceFill([
                    'workspace_id' => $locked->host->id, 'client_task_id' => $task->id, 'user_id' => $userId,
                    'assignee_workspace_id' => $assigneeWorkspaceId,
                ])->save();
            }

            return array_map(static fn (array $person): array => ['user_id' => $person['user_id'], 'workspace_id' => $person['workspace_id']], $data['assignees']);
        });
    }

    private function context(User $actor, Workspace $viewerWorkspace, string $projectPublicId): SharedProjectContext
    {
        $context = $this->access->resolve($actor, $viewerWorkspace, $projectPublicId);
        abort_if($context === null || ! $context->viewer->canViewProject(), 404);

        return $context;
    }

    /**
     * The task's current assignments as "user id|workspace id" keys.
     *
     * @return array<string, true>
     */
    private function current(ClientTask $task, Workspace $host): array
    {
        $keys = [];
        foreach (ClientTaskAssignment::query()->where('workspace_id', $host->id)->where('client_task_id', $task->id)->get(['user_id', 'assignee_workspace_id']) as $row) {
            $keys[$row->user_id.'|'.($row->assignee_workspace_id ?? $host->id)] = true;
        }

        return $keys;
    }
}
