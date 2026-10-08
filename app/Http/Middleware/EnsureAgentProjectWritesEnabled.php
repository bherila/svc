<?php

namespace App\Http\Middleware;

use App\Support\AgentApi\AgentWriteCutover;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAgentProjectWritesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(AgentWriteCutover::projects(), 404);

        return $next($request);
    }
}
