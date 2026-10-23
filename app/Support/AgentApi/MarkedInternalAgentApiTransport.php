<?php

namespace App\Support\AgentApi;

use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use Bherila\McpLaravelBridge\Http\AgentApiTransportResponse;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;

/** The bridge's transport, with the nested request marked as one ({@see InternalApiDispatch}). */
final class MarkedInternalAgentApiTransport extends InternalAgentApiTransport
{
    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
        array $headers = [],
    ): AgentApiTransportResponse {
        return app(InternalApiDispatch::class)->run(fn (): AgentApiTransportResponse => parent::send($method, $path, $query, $json, $multipart, $headers));
    }
}
