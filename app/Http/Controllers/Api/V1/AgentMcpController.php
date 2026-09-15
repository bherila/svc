<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use Bherila\McpLaravelBridge\Http\SdkMiddlewareProfile;
use Bherila\McpLaravelBridge\Http\StreamableHttpResponder;
use Illuminate\Http\Request;
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
            middleware: SdkMiddlewareProfile::forHardenedLaravelEdge(),
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes'),
        );
    }
}
