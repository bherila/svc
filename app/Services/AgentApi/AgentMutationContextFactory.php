<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\AccessMutationContext;
use App\Support\AgentApi\AgentMutationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;

final class AgentMutationContextFactory
{
    public function from(Request $request): AgentMutationContext
    {
        $principal = $request->user();
        abort_unless($principal instanceof AgentPrincipal, 401);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && trim($key) !== '' && strlen($key) <= 255, 422, 'An Idempotency-Key header is required.');
        $token = $request->user('api')?->token();
        $clientId = $token instanceof AccessToken ? $token->oauth_client_id : null;
        abort_unless(is_string($clientId) && $clientId !== '' && $clientId !== LegacyAgentReceiptNamespace::CLIENT_ID, 401);

        return new AgentMutationContext(
            User::query()->findOrFail($principal->id),
            $clientId,
            $key,
        );
    }

    public function forAccess(Request $request, Workspace $workspace, AgentMutationContext $mutation, string $operation): AccessMutationContext
    {
        $requestId = $request->header('X-Request-Id');
        $requestId = is_string($requestId) && preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $requestId) === 1
            ? $requestId : (string) Str::uuid();

        return AccessMutationContext::user($mutation->user,
            hash('sha256', implode('|', [$workspace->public_id, $mutation->user->public_id, $mutation->oauthClientId, $operation, $mutation->idempotencyKey])),
            $requestId);
    }

    /** Retained for callers introduced before the legacy namespace cutover. */
    public function fromAuthenticatedClient(Request $request): AgentMutationContext
    {
        return $this->from($request);
    }
}
