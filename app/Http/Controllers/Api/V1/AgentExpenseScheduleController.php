<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\Workspace;
use App\Services\AgentApi\AgentExpenseScheduleService;
use App\Services\AgentApi\AgentMutationContextFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentExpenseScheduleController extends Controller
{
    public function __construct(private readonly AgentExpenseScheduleService $schedules, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof AgentPrincipal, 401);
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'limit' => ['integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->schedules->listing($actor, $workspace, $data['company_id'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->mutate($request, $workspace, 'create', null, 201);
    }

    public function update(Request $request, Workspace $workspace, string $schedule): JsonResponse
    {
        return $this->mutate($request, $workspace, 'update', $schedule);
    }

    public function generate(Request $request, Workspace $workspace, string $schedule): JsonResponse
    {
        return $this->mutate($request, $workspace, 'generate', $schedule);
    }

    /** @param 'create'|'update'|'generate' $operation */
    private function mutate(Request $request, Workspace $workspace, string $operation, ?string $id, int $status = 200): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);

        return response()->json($this->schedules->mutate($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $id, $request->all()), $status);
    }
}
