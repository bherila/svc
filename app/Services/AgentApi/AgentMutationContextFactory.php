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
        $attributes = $token instanceof AccessToken ? $token->toArray() : [];
        $clientId = $attributes['client_id'] ?? null;

        return new AgentMutationContext(
            User::query()->findOrFail($principal->id),
            is_string($clientId) && $clientId !== '' ? $clientId : 'testing-client',
            $key,
        );
    }
}
