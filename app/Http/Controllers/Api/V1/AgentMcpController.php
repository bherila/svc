<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Mcp\AgentMcpServerFactory;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Http\Request;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

final class AgentMcpController extends Controller
{
    public function __invoke(Request $request, AgentMcpServerFactory $servers): Response
    {
        $factory = new HttpFactory;
        $psrRequest = (new PsrHttpFactory($factory, $factory, $factory, $factory))->createRequest($request);
        $transport = new StreamableHttpTransport(
            request: $psrRequest,
            responseFactory: $factory,
            streamFactory: $factory,
            middleware: [
                new DnsRebindingProtectionMiddleware(allowedHosts: $this->allowedHosts()),
                new ProtocolVersionMiddleware,
            ],
            maxBodyBytes: (int) config('agent_api.mcp_max_body_bytes'),
        );
        $response = $servers->make($request)->run($transport);

        return (new HttpFoundationFactory)->createResponse(
            $response,
            str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream'),
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
