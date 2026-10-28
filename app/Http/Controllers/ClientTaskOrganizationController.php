<?php

namespace App\Http\Controllers;

use App\Http\Requests\TaskOrganizationInput;
use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
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
}
