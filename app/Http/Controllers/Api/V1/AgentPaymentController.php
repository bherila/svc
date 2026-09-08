<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPaymentReadService;
use App\Services\AgentApi\RecordPaymentAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentPaymentController extends Controller
{
    public function index(Request $request, Workspace $workspace, AgentPaymentReadService $reads): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof AgentPrincipal, 401);
        $reads->requireWorkspace($user, $workspace);
        $data = $request->validate([
            'invoice_id' => ['nullable', 'uuid'], 'company_id' => ['nullable', 'uuid'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048'],
        ]);

        return response()->json($reads->listing($user, $workspace, $data['invoice_id'] ?? null, $data['company_id'] ?? null,
            (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function store(Request $request, Workspace $workspace, AgentMutationContextFactory $contexts, RecordPaymentAction $action, AgentPaymentReadService $reads): JsonResponse
    {
        $context = $contexts->fromAuthenticatedClient($request);
        $ids = $action->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $request->all());

        return response()->json($reads->result($context->user, $workspace, $ids), 201);
    }
}
