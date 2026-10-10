<?php

namespace App\Services\AgentApi\Operations;

use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Illuminate\Http\Request;
use Laravel\Passport\Contracts\ScopeAuthorizable;

/**
 * The REST caller for the route gate: the access token the API guard
 * authenticated - an OAuth or personal token, or the signed-in website's
 * synthetic one, which holds every API scope but `mcp:use`.
 */
final class AgentPrincipalResolver implements PrincipalResolver
{
    public function principal(Request $request): Principal
    {
        $token = $request->user()?->currentAccessToken();
        if (! $token instanceof ScopeAuthorizable) {
            return AgentOperationPrincipal::anonymous();
        }

        return new AgentOperationPrincipal(
            static fn (string $scope): bool => $token->can($scope),
            static fn (): bool => false,
        );
    }
}
