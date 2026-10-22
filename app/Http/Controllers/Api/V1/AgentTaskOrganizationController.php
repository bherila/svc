<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskOrganizationInput;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentTaskOrganizationMutationAction;
use App\Services\Tasks\Organization\TaskOrganizationAction;
use App\Services\Tasks\Organization\TaskOrganizationGate;
use App\Services\Tasks\TaskMutationOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Additive Agent adapter; registry routes choose the operation server-side. */
final class AgentTaskOrganizationController extends Controller
{
    public function show(Request $request, Workspace $workspace, string $project, TaskOrganizationAction $organization): JsonResponse
    {
        TaskOrganizationGate::assertEnabled();
        $principal = $request->user();
        abort_unless($principal instanceof AgentPrincipal || $principal instanceof User, 401);
        $actor = User::query()->whereKey($principal->id)->firstOrFail();
        $container = TaskOrganizationInput::container($request->query());

        return response()->json(['data' => $organization->snapshot($actor, $workspace, $project, $container, TaskMutationOrigin::Agent)->data]);
    }

    public function mutate(Request $request, Workspace $workspace, string $project,
        AgentMutationContextFactory $contexts, AgentTaskOrganizationMutationAction $writes): JsonResponse
    {
        TaskOrganizationGate::assertEnabled();
        $context = $contexts->from($request);
        $operation = TaskOrganizationInput::operation($request);
        $input = TaskOrganizationInput::body($request->all(), TaskOrganizationInput::routeIdentity($request, 'list'), TaskOrganizationInput::routeIdentity($request, 'task'));
        $ids = $writes->execute($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $project, $operation, $input);

        return response()->json(['data' => ['affected_ids' => $ids]], str_ends_with($operation, '.create') ? 201 : 200);
    }
}
