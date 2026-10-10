<?php

namespace App\Http\Middleware;

use App\Models\AgentPrincipal;
use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Support\AgentApi\FirstPartySession;
use App\Support\AgentApi\ProviderIdentityWrites;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Passport\AccessToken;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `EnsureOperationDeployed:<operation id>`: a REST operation behind a
 * switched-off write cutover answers 404, before the route gate.
 *
 * 404 rather than the gate's 403, as before the registry: a disabled surface
 * is not a permission the caller might be granted, and naming the switch
 * would disclose the deployment's configuration to an unauthorized client.
 * The switches are the operation's own declaration; the signed-in website
 * passes them (#385).
 *
 * The operation's effect is known here too, so a write made with an OAuth
 * token gets its fresh provider identity check here, before the route gate.
 */
final class EnsureOperationDeployed
{
    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly ProviderIdentityWrites $writes,
    ) {}

    public function handle(Request $request, Closure $next, string $operationId): Response
    {
        $operation = $this->registry->find($operationId) ?? throw new LogicException("Operation [{$operationId}] is not registered.");
        $flags = AgentDeploymentFlags::forRest();
        foreach ($operation->requirement->flags as $flag) {
            abort_unless($flags->enabled($flag), 404);
        }
        if (! $operation->effect->readOnly()) {
            $this->refuseAnEndedIdentity($request);
        }

        return $next($request);
    }

    /**
     * A write made with an OAuth token checks the person's provider identity
     * freshly (see {@see ProviderIdentityWrites}); an ended one is refused
     * with the route's bearer challenge. The website's session is checked by
     * its own middleware, and a request without a stored token has nothing to
     * check here.
     *
     * @throws AuthenticationException
     */
    private function refuseAnEndedIdentity(Request $request): void
    {
        if (FirstPartySession::is($request)) {
            return;
        }
        $user = $request->user();
        $token = $user instanceof AgentPrincipal ? $user->token() : null;
        $tokenId = $token instanceof AccessToken ? $token->oauth_access_token_id : null;
        if (! is_string($tokenId) || $tokenId === '') {
            return;
        }
        if (! $this->writes->allows($user, $tokenId)) {
            throw new AuthenticationException('The sign-in behind this credential has ended.', ['api']);
        }
    }
}
