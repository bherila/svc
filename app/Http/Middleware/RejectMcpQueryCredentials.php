<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** MCP credentials are accepted only in Authorization headers, never URLs. */
final class RejectMcpQueryCredentials
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('api/v1/mcp')) {
            return $next($request);
        }

        foreach (array_keys($request->query->all()) as $key) {
            if (in_array(strtolower($key), ['access_token', 'token', 'authorization', 'bearer'], true)) {
                return response()->json(['message' => 'MCP credentials must not be sent in the query string.'], 400, [
                    'Cache-Control' => 'private, no-store',
                ]);
            }
        }

        return $next($request);
    }
}
