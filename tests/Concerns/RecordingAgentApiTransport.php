<?php

namespace Tests\Concerns;

use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use Bherila\McpLaravelBridge\Http\AgentApiTransportResponse;

/** Records each REST request an MCP tool makes and answers it with a canned response. */
final class RecordingAgentApiTransport implements AgentApiTransport
{
    /** @var list<array{method: string, path: string, query: array<string, mixed>, json: array<string, mixed>|null, headers: array<string, string>}> */
    public array $requests = [];

    /** @param array<string, mixed>|null $json */
    public function __construct(private readonly int $status = 200, private readonly ?array $json = ['data' => []]) {}

    public function send(
        string $method,
        string $path,
        array $query = [],
        ?array $json = null,
        ?AgentApiMultipart $multipart = null,
        array $headers = [],
    ): AgentApiTransportResponse {
        $this->requests[] = compact('method', 'path', 'query', 'json', 'headers');

        return new AgentApiTransportResponse($this->status, $this->json);
    }
}
