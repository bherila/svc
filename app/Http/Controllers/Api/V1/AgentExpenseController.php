<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseMutationAction;
use App\Services\AgentApi\AgentExpenseReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Authorization\AgentTokenScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentExpenseController extends Controller
{
    public function __construct(private readonly AgentExpenseReadService $reads, private readonly AgentExpenseMutationAction $writes, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace, AgentTokenScopes $scopes): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($this->reads->list($user, $workspace, $data['company_id'] ?? null, $data['project_id'] ?? null, $data['status'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null, $scopes->allows($request, 'expenses:write')));
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $context = $this->contexts->from($request);
        $ids = $this->writes->log($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $request->all());

        return response()->json(['data' => $this->reads->results($context->user, $workspace, $ids)], 201);
    }

    public function update(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        $context = $this->contexts->from($request);
        $ids = $this->writes->update($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $expense, $request->all());

        return response()->json(['data' => $this->reads->results($context->user, $workspace, $ids)[0]]);
    }

    public function destroy(Request $request, Workspace $workspace, string $expense): JsonResponse
    {
        $context = $this->contexts->from($request);
        $id = $this->writes->delete($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $expense, $request->all());

        return response()->json(['data' => ['deleted_id' => $id]]);
    }
}
