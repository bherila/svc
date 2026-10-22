<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Services\Authorization\AgentAccess;
use App\Services\Tasks\TaskInputRules;
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
        AgentAccess $access,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(TaskInputRules::create());
        $task = $tasks->create(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $project,
            $data,
        );

        return response()->json(['data' => $presenter->present($workspace, $task, includePlanning: ! $access->isWorkspaceClient($context->user, $workspace), canManage: $access->isWorkspaceManager($context->user, $workspace))], 201);
    }

    public function update(
        Request $request,
        Workspace $workspace,
        string $task,
        AgentTaskMutationAction $tasks,
        AgentTaskPresenter $presenter,
        AgentMutationContextFactory $contexts,
        AgentAccess $access,
    ): JsonResponse {
        $context = $contexts->from($request);
        $data = $request->validate(['expected_version' => ['required', 'string', 'size:64']] + TaskInputRules::patch());
        $record = $tasks->update(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $task,
            $data,
        );

        return response()->json(['data' => $presenter->present($workspace, $record, includePlanning: ! $access->isWorkspaceClient($context->user, $workspace), canManage: $access->isWorkspaceManager($context->user, $workspace))]);
    }
}
