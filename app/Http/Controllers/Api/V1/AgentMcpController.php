<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Symfony\Component\HttpFoundation\Response;

final class AgentMcpController extends Controller
{
    public function __invoke(
        Request $request,
        AgentMcpServerFactory $servers,
        StreamableHttpResponder $responder,
    ): Response {
        if (! (bool) config('agent_api.mcp_enabled', true)) {
            return response()->json(
                ['message' => 'The SVC MCP service is temporarily unavailable.'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                ['Retry-After' => '60'],
            );
        }

        if (! in_array($this->normalizedHost($request->getHost()), $this->allowedHosts(), true)) {
            return response('Forbidden: Invalid Host header.', Response::HTTP_FORBIDDEN, [
                'Content-Type' => 'text/plain',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        return $responder->run(
            request: $request,
            server: $servers->make($request),
            middleware: [
                new ProtocolVersionMiddleware,
            ],
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes'),
        );
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = [];
        foreach ([config('app.url'), config('bherila-auth.oauth_server.resource')] as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = $this->normalizedHost($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function normalizedHost(string $host): string
    {
        return strtolower(rtrim(trim($host, '[]'), '.'));
    }
}
