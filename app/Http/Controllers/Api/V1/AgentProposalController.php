<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentProposalMutationAction;
use App\Services\AgentApi\AgentProposalReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentProposalController extends Controller
{
    public function __construct(private readonly AgentProposalReadService $reads, private readonly AgentProposalMutationAction $writes, private readonly AgentMutationContextFactory $contexts) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $data = $request->validate(['company_id' => ['nullable', 'uuid'], 'status' => ['nullable', 'in:draft,sent,accepted,declined,expired'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->reads->list($actor, $workspace, $data['company_id'] ?? null, $data['status'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, string $proposal): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);

        return response()->json(['data' => $this->reads->get($actor, $workspace, $proposal)]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        return $this->mutate($request, $workspace, 'proposals.create', null, 201);
    }

    public function send(Request $request, Workspace $workspace, string $proposal): JsonResponse
    {
        return $this->mutate($request, $workspace, 'proposals.send', $proposal);
    }

    public function accept(Request $request, Workspace $workspace, string $proposal): JsonResponse
    {
        return $this->mutate($request, $workspace, 'proposals.accept', $proposal);
    }

    private function mutate(Request $request, Workspace $workspace, string $operation, ?string $id, int $status = 200): JsonResponse
    {
        $context = $this->contexts->fromAuthenticatedClient($request);
        $proposal = $this->writes->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $id, $request->all());

        return response()->json(['data' => $this->reads->get($context->user, $workspace, $proposal->public_id)], $status);
    }
}
