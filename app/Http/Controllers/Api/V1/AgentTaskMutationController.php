<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentTaskMutationController extends Controller
{
    public function store(
        Request $request,
        Workspace $workspace,
        string $project,
        AgentTaskMutationAction $tasks,
        AgentTaskPresenter $presenter,
        AgentMutationContextFactory $contexts,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
        $task = $tasks->create(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $project,
            $data,
        );

        return response()->json(['data' => $presenter->present($workspace, $task)], 201);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        string $task,
        AgentTaskMutationAction $tasks,
        AgentTaskPresenter $presenter,
        AgentMutationContextFactory $contexts,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64'], 'title' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:10000'], 'status' => ['sometimes', 'string', 'in:open,in_progress,completed'], 'is_visible_to_client' => ['sometimes', 'boolean']]);
        $record = $tasks->update(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $task,
            $data,
        );

        return response()->json(['data' => $presenter->present($workspace, $record)]);
    }
}
