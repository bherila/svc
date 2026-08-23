<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentMutationExecutor;
use App\Services\Authorization\ProjectAccess;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AgentTaskMutationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        string $project,
        ProjectAccess $access,
        AgentTaskPresenter $presenter,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'tasks.create',
            $context->idempotencyKey,
            ['project_id' => $project, 'body' => $request->all()],
            function () use ($request, $workspace, $project, $access, $context): array {
                $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
                $record = ClientProject::query()->where('workspace_id', $workspace->id)->where('public_id', $project)->firstOrFail();
                abort_unless($access->canManageTasks($context->user, $record), 403);
                $task = $record->tasks()->create(['workspace_id' => $workspace->id] + $data + ['is_visible_to_client' => $data['is_visible_to_client'] ?? false]);

                return [$task->public_id];
            },
            function (array $ids) use ($workspace, $access, $context): void {
                $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->with('project')->firstOrFail();
                abort_unless($access->canManageTasks($context->user, $task->project), 403);
            },
        );
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->with('project')->firstOrFail();

        return response()->json(['data' => $presenter->present($workspace, $task)], 201);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        string $task,
        ProjectAccess $access,
        AgentTaskPresenter $presenter,
        AgentMutationContextFactory $contexts,
        AgentMutationExecutor $mutations,
    ): JsonResponse {
        $context = $contexts->from($request);
        $ids = $mutations->run(
            $context->user,
            $workspace,
            $context->oauthClientId,
            'tasks.update',
            $context->idempotencyKey,
            ['task_id' => $task, 'body' => $request->all()],
            function () use ($request, $workspace, $task, $access, $context): array {
                $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'title' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'in:open,in_progress,completed'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
                $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $task)->with('project')->firstOrFail();
                abort_unless($access->canManageTasks($context->user, $record->project), 403);
                $attributes = $data;
                if (($attributes['status'] ?? null) === 'completed') {
                    $attributes['completed_at'] = now();
                } elseif (array_key_exists('status', $attributes)) {
                    $attributes['completed_at'] = null;
                }
                unset($attributes['expected_version']);
                abort_unless(AgentApiVersion::matches($record, $data['expected_version']), 409, 'The task has changed; read it and retry.');
                $updated = ClientTask::query()->whereKey($record->id)->where('lock_version', $record->lock_version)->update($attributes + ['lock_version' => DB::raw('lock_version + 1')]);
                abort_unless($updated === 1, 409, 'The task has changed; read it and retry.');

                return [$record->public_id];
            },
            function (array $ids) use ($workspace, $access, $context): void {
                $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->with('project')->firstOrFail();
                abort_unless($access->canManageTasks($context->user, $record->project), 403);
            },
        );
        $record = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $ids[0] ?? null)->with('project')->firstOrFail();

        return response()->json(['data' => $presenter->present($workspace, $record)]);
    }
}
