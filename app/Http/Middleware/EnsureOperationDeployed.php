<?php

namespace App\Http\Middleware;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Closure;
use Illuminate\Http\Request;
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
 */
final class EnsureOperationDeployed
{
    public function __construct(private readonly OperationRegistry $registry) {}

    public function handle(Request $request, Closure $next, string $operationId): Response
    {
        $operation = $this->registry->find($operationId) ?? throw new LogicException("Operation [{$operationId}] is not registered.");
        $flags = AgentDeploymentFlags::forRest();
        foreach ($operation->requirement->flags as $flag) {
            abort_unless($flags->enabled($flag), 404);
        }

        return $next($request);
    }
}
