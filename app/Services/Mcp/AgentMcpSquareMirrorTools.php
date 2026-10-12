<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/**
 * svc's Square mirror over MCP (#438): the same REST operations, receipts and
 * refusals, through the internal transport.
 */
final class AgentMcpSquareMirrorTools
{
    public function __construct(private readonly InternalAgentApiTransport $api) {}

    /** @return array<string, mixed> */
    public function sync(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(minLength: 1, maxLength: 255)] string $idempotency_key,
    ): array {
        return $this->send('POST', "workspaces/{$workspace_id}/square/sync", [], $idempotency_key);
    }

    /**
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, ?array $body, ?string $key = null): array
    {
        $response = $this->api->send($method, $path, json: $body, headers: $key === null ? [] : ['Idempotency-Key' => $key]);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }
        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
