<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
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

        return $responder->run(
            request: $request,
            server: $servers->make($request),
            middleware: [
                new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts()),
                new ProtocolVersionMiddleware,
            ],
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes'),
        );
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = [];
        $origins = config('agent_api.mcp_allowed_origins', []);
        foreach ([config('app.url'), ...(is_array($origins) ? $origins : [])] as $url) {
            $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }
}
