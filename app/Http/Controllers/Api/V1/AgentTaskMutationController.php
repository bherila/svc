<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentTaskMutationAction;
use App\Services\Authorization\AgentAccess;
use App\Services\Tasks\TaskAccess;
use App\Services\Tasks\TaskCompletionAdvisory;
use App\Services\Tasks\TaskInputRules;
use App\Support\AgentApi\FirstPartySession;
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
        $operation = FirstPartySession::is($request) ? 'createInternal' : 'create';
        $task = $tasks->{$operation}(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $project,
            $data,
        );

        $includePlanning = FirstPartySession::is($request) || ! $access->isWorkspaceClient($context->user, $workspace);
        if ($includePlanning) {
            app(TaskCompletionAdvisory::class)->load($workspace, [$task]);
        }

        return response()->json(['data' => $presenter->present($workspace, $task, includePlanning: $includePlanning, canManage: app(TaskAccess::class)->canManage($context->user, $workspace, $task->project))], 201);
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
        $operation = FirstPartySession::is($request) ? 'updateInternal' : 'update';
        $record = $tasks->{$operation}(
            $context->user,
            $workspace,
            $context->oauthClientId,
            $context->idempotencyKey,
            $task,
            $data,
        );

        $includePlanning = FirstPartySession::is($request) || ! $access->isWorkspaceClient($context->user, $workspace);
        if ($includePlanning) {
            app(TaskCompletionAdvisory::class)->load($workspace, [$record]);
        }

        return response()->json(['data' => $presenter->present($workspace, $record, includePlanning: $includePlanning, canManage: app(TaskAccess::class)->canManage($context->user, $workspace, $record->project))]);
    }
}
