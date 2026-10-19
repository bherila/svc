<?php

namespace App\Services\AgentApi\Operations;

use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Capabilities\Principal;
use Bherila\McpLaravelBridge\Capabilities\PrincipalResolver;
use Illuminate\Http\Request;
use Laravel\Passport\Contracts\ScopeAuthorizable;
use Laravel\Sanctum\Contracts\HasAbilities;

/**
 * The REST caller for the route gate: the access token the API guard
 * authenticated - an OAuth or personal token, or the signed-in website's
 * synthetic one, which holds every API scope but `mcp:use`.
 *
 * The finance reconciliation routes also admit the Sanctum token
 * `svc:auth:issue-token` issues (#457). Its `finance.read` and
 * `finance.reconcile` abilities are read as the `finance:read` and
 * `finance:reconcile` scopes and it holds no other scope, so it opens those
 * operations and nothing else, whichever route it is presented to.
 */
final class AgentPrincipalResolver implements PrincipalResolver
{
    /** Sanctum finance abilities, by the operation scope each stands for. */
    public const array FINANCE_ABILITIES = [
        AgentApiScopes::FINANCE_READ => 'finance.read',
        AgentApiScopes::FINANCE_RECONCILE => 'finance.reconcile',
    ];

    public function principal(Request $request): Principal
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof ScopeAuthorizable) {
            return new AgentOperationPrincipal(
                static fn (string $scope): bool => $token->can($scope),
                static fn (): bool => false,
            );
        }
        if ($token instanceof HasAbilities) {
            return new AgentOperationPrincipal(
                static fn (string $scope): bool => isset(self::FINANCE_ABILITIES[$scope]) && $token->can(self::FINANCE_ABILITIES[$scope]),
                static fn (): bool => false,
            );
        }

        return AgentOperationPrincipal::anonymous();
    }
}
