<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskOrganizationInput;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentTaskOrganizationMutationAction;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Native website adapter; the caller cannot select Agent/Internal authority. */
final class ClientTaskOrganizationController extends Controller
{
    public function page(Request $request, Workspace $workspace, ClientCompany $clientCompany, string $project, TaskOrganizationAction $organization): Response
    {
        TaskOrganizationGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $query = $request->query();
        $moving = validator(['moving_task_id' => $query['moving_task_id'] ?? null], ['moving_task_id' => ['nullable', 'uuid']])->validate();
        unset($query['moving_task_id']);
        $container = TaskOrganizationInput::container(['container' => true] + $query);
        abort_unless($container !== null, 422);
        $view = $organization->nativeView($actor, $workspace, $clientCompany, $project, $container, $moving['moving_task_id']);

        return Inertia::render('clients/task-organization', $view->data);
    }

    public function show(Request $request, Workspace $workspace, string $project, TaskOrganizationAction $organization): JsonResponse
    {
        TaskOrganizationGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return response()->json(['data' => $organization->snapshot($actor, $workspace, $project, TaskOrganizationInput::container($request->query()))->data]);
    }

    /** Keep PATCH and DELETE on the same URI distinct for generated route clients. */
    public function archiveList(Request $request, Workspace $workspace, string $project,
        AgentTaskOrganizationMutationAction $writes): JsonResponse
    {
        return $this->mutate($request, $workspace, $project, $writes);
    }

    public function mutate(Request $request, Workspace $workspace, string $project,
        AgentTaskOrganizationMutationAction $writes): JsonResponse
    {
        TaskOrganizationGate::assertEnabled();
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $operation = TaskOrganizationInput::operation($request);
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');
        $validated = validator(['idempotency_key' => $key], ['idempotency_key' => ['required', 'string', 'max:255']])->validate();
        $body = TaskOrganizationInput::body($request->except(['_token', '_method', 'idempotency_key']), TaskOrganizationInput::routeIdentity($request, 'list'), TaskOrganizationInput::routeIdentity($request, 'task'));
        $ids = $writes->executeInternal($actor, $workspace, 'svc-web-task-organization', $validated['idempotency_key'], $project, $operation, $body);

        return response()->json(['data' => ['affected_ids' => $ids]], str_ends_with($operation, '.create') ? 201 : 200);
    }
}
