<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\InternalApiDispatch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The REST group's throttle, applied once per request a client made (#459).
 *
 * An MCP tool's REST route runs nested inside the MCP request, which has
 * already been counted against the same per-principal bucket; counting the
 * nested request again would halve every routed tool's allowance.
 */
final class ThrottleOuterAgentRequests
{
    public function __construct(
        private readonly InternalApiDispatch $dispatch,
        private readonly ThrottleRequests $throttle,
    ) {}

    public function handle(Request $request, Closure $next, int|string $maxAttempts = 60, float|int|string $decayMinutes = 1, string $prefix = ''): Response
    {
        if ($this->dispatch->active()) {
            return $next($request);
        }

        return $this->throttle->handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
