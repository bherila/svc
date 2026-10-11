<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AccessMutationRefused;
use App\Http\Controllers\Controller;
use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentMutationContextFactory;
use App\Services\AgentApi\AgentPortalAccessMutationAction;
use App\Services\AgentApi\AgentPortalContactReadService;
use App\Support\Access\AccessRefusal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentPortalAccessController extends Controller
{
    public function __construct(
        private readonly AgentPortalContactReadService $contacts,
        private readonly AgentPortalAccessMutationAction $mutations,
        private readonly AgentMutationContextFactory $contexts,
    ) {}

    public function index(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User || $actor instanceof AgentPrincipal, 401);
        $data = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string', 'max:2048']]);

        return response()->json($this->contacts->list($actor, $workspace, $client, (int) ($data['limit'] ?? 25), $data['cursor'] ?? null));
    }

    public function store(Request $request, Workspace $workspace, string $client): JsonResponse
    {
        return $this->write($request, $workspace, $client, null, 'clients.portal_access.grant');
    }

    public function update(Request $request, Workspace $workspace, string $client, string $user): JsonResponse
    {
        return $this->write($request, $workspace, $client, $user, 'clients.portal_access.update');
    }

    public function destroy(Request $request, Workspace $workspace, string $client, string $user): JsonResponse
    {
        return $this->write($request, $workspace, $client, $user, 'clients.portal_access.revoke');
    }

    private function write(Request $request, Workspace $workspace, string $client, ?string $user, string $operation): JsonResponse
    {
        $context = $this->contexts->from($request);
        try {
            $data = $this->mutations->run($context->user, $workspace, $context->oauthClientId, $context->idempotencyKey, $operation, $client, $user, $request->all());
        } catch (AccessMutationRefused $refused) {
            abort_if($refused->reason === AccessRefusal::NotAuthorized, 403, $refused->getMessage());
            abort_if($refused->reason === AccessRefusal::PortalRevisionConflict, 409, $refused->getMessage());
            throw $refused;
        }

        return response()->json(['data' => $data], $operation === 'clients.portal_access.grant' ? 201 : 200);
    }
}
