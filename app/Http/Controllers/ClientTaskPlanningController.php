<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskPlanningInput;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskPlanningMutationAction;
use App\Services\Tasks\Planning\TaskPlanningDetailsGate;
use App\Services\Tasks\Planning\TaskPlanningNativePresenter;
use App\Services\Tasks\Planning\TaskPlanningTransport;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Native policies and receipt identity are selected only by this trusted adapter. */
final class ClientTaskPlanningController extends Controller
{
    public function page(Request $request, Workspace $workspace, TaskPlanningNativePresenter $presenter): Response
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return Inertia::render('clients/task-labels', $presenter->catalog($actor, $workspace, $request->query()));
    }

    public function show(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $operation = TaskPlanningInput::operation($request);

        return response()->json($transport->read($actor, $workspace, TaskMutationOrigin::Internal, $operation, TaskPlanningInput::target($request, $operation), $request->query()));
    }

    public function options(Request $request, Workspace $workspace, TaskPlanningTransport $transport): JsonResponse
    {
        return $this->show($request, $workspace, $transport);
    }

    public function archiveLabel(Request $request, Workspace $workspace, AgentTaskPlanningMutationAction $writes): JsonResponse
    {
        return $this->mutate($request, $workspace, $writes);
    }

    public function mutate(Request $request, Workspace $workspace, AgentTaskPlanningMutationAction $writes): JsonResponse
    {
        TaskPlanningDetailsGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $operation = TaskPlanningInput::operation($request);
        abort_unless(in_array($operation, TaskPlanningInput::WRITES, true), 404);
        $key = validator(['key' => $request->header('Idempotency-Key')], ['key' => ['required', 'string', 'max:255']])->validate();
        $id = $writes->executeInternal($actor, $workspace, 'svc-web-task-planning', $key['key'], $operation,
            TaskPlanningInput::target($request, $operation), $request->except(['_token', '_method']));

        return response()->json(['data' => ['id' => $id]], $operation === 'labels.create' ? 201 : 200);
    }
}
