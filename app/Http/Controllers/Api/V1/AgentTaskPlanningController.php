<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskPlanningInput;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentTaskPlanningMutationAction;
use App\Services\Tasks\Planning\TaskPlanningDetailsGate;
use App\Services\Tasks\Planning\TaskPlanningTransport;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Route metadata selects the operation; Agent audience is never an input. */
final class AgentTaskPlanningController extends Controller
{
    public function show(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $operation = TaskPlanningInput::operation($request);

        return response()->json($transport->read($actor, $workspace, TaskMutationOrigin::Agent, $operation, TaskPlanningInput::target($request, $operation), $request->query()));
    }

    public function options(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        return $this->show($request, $workspace, $transport);
    }

    public function archiveLabel(Request $request, Workspace $workspace, AgentMutationContextFactory $contexts, AgentTaskPlanningMutationAction $writes): JsonResponse
    {
        return $this->mutate($request, $workspace, $contexts, $writes);
    }

    public function mutate(Request $request, Workspace $workspace, AgentMutationContextFactory $contexts, AgentTaskPlanningMutationAction $writes): JsonResponse
    {
        TaskPlanningDetailsGate::assertEnabled();
        $context = $contexts->from($request);
        $operation = TaskPlanningInput::operation($request);
        abort_unless(in_array($operation, TaskPlanningInput::WRITES, true), 404);
        $id = $writes->execute($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey,
            $operation, TaskPlanningInput::target($request, $operation), $request->all());

        return response()->json(['data' => ['id' => $id]], $operation === 'labels.create' ? 201 : 200);
    }
}
