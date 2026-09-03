<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceAgentMcpOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return $next($request);
        }
        if (! in_array($origin, $this->allowedOrigins(), true)) {
            if ($request->isMethod('OPTIONS')) {
                return response()->noContent(headers: ['Vary' => 'Origin, Access-Control-Request-Method']);
            }

            return response()->json(['message' => 'Origin is not allowed.'], 403, [
                'Cache-Control' => 'private, no-store',
                'Vary' => 'Origin',
            ]);
        }

        if ($request->isMethod('OPTIONS')) {
            $requestedMethod = strtoupper((string) $request->header('Access-Control-Request-Method'));
            $requestedHeaders = array_values(array_filter(array_map(
                static fn (string $header): string => strtolower(trim($header)),
                explode(',', (string) $request->header('Access-Control-Request-Headers', '')),
            )));
            $allowedHeaders = array_map('strtolower', $this->allowedHeaders());
            if (! in_array($requestedMethod, ['POST', 'DELETE'], true)
                || array_diff($requestedHeaders, $allowedHeaders) !== []) {
                return response()->noContent(headers: ['Vary' => 'Origin, Access-Control-Request-Method']);
            }

            return response()->noContent(headers: [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Methods' => 'POST, DELETE, OPTIONS',
                'Access-Control-Allow-Headers' => implode(', ', $this->allowedHeaders()),
                'Access-Control-Max-Age' => '600',
                'Vary' => 'Origin, Access-Control-Request-Method',
            ]);
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->setVary('Origin', false);
        $response->headers->set('Access-Control-Expose-Headers', 'Mcp-Session-Id, Mcp-Protocol-Version, WWW-Authenticate');

        return $response;
    }

    /** @return list<string> */
    private function allowedOrigins(): array
    {
        $origins = config('agent_api.mcp_allowed_origins', []);

        return is_array($origins) ? array_values(array_filter($origins, 'is_string')) : [];
    }

    /** @return list<string> */
    private function allowedHeaders(): array
    {
        return ['Authorization', 'Content-Type', 'Mcp-Protocol-Version', 'Mcp-Session-Id', 'Last-Event-ID'];
    }
}
