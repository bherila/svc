<?php

namespace App\Services\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\AgentMutationContext;
use Illuminate\Http\Request;
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

    /** Retained for callers introduced before the legacy namespace cutover. */
    public function fromAuthenticatedClient(Request $request): AgentMutationContext
    {
        return $this->from($request);
    }
}
