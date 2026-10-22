<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentClientContactMutationAction;
use App\Services\AgentApi\AgentClientContactReadService;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\Authorization\AgentAccess;
use App\Services\Clients\ContactMutationOrigin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentClientContactController extends Controller
{
    public function __construct(private readonly AgentClientContactReadService $contacts, private readonly AgentClientContactMutationAction $mutations, private readonly AgentMutationContextFactory $contexts, private readonly AgentAccess $access) {}

    public function index(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:active,archived'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->contacts->listing($this->principal($request, $workspace), $workspace, $client, $data['status'] ?? null, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function show(Request $request, Workspace $workspace, string $client, string $contact): JsonResponse
    {
        return response()->json(['data' => $this->contacts->get($this->principal($request, $workspace), $workspace, $client, $contact)]);
    }

    public function store(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->change($request, $workspace, $client, 'create');
    }

    public function update(Request $request, Workspace $workspace, string $client, string $contact): JsonResponse
    {
        return $this->change($request, $workspace, $client, 'update', $contact);
    }

    public function archive(Request $request, Workspace $workspace, string $client, string $contact): JsonResponse
    {
        return $this->change($request, $workspace, $client, 'archive', $contact);
    }

    public function restore(Request $request, Workspace $workspace, string $client, string $contact): JsonResponse
    {
        return $this->change($request, $workspace, $client, 'restore', $contact);
    }

    private function change(Request $request, Workspace $workspace, string $client, string $operation, ?string $contact = null): JsonResponse
    {
        $context = $this->contexts->from($request);
        abort_if($this->access->isWorkspaceClient($context->user, $workspace), 403);
        $id = $this->mutations->perform($context->user, $workspace, $client, $operation, $contact, $request->all(), $context->oauthClientId, $context->idempotencyKey, ContactMutationOrigin::Agent);

        return response()->json(['data' => $this->contacts->get($this->principal($request, $workspace), $workspace, $client, $id)], $operation === 'create' ? 201 : 200);
    }

    private function principal(Request $request, Workspace $workspace): User|AgentPrincipal
    {
        $principal = $request->user();
        abort_unless($principal instanceof User || $principal instanceof AgentPrincipal, 401);

        abort_if($this->access->isWorkspaceClient($principal, $workspace), 404);

        return $principal;
    }
}
