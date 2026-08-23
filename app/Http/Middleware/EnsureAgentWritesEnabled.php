<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAgentWritesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('agent_api.writes_enabled'), 404);

        return $next($request);
    }
}
