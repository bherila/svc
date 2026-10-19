<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use LogicException;
use Mcp\Exception\ToolCallException;

/**
 * An MCP tool's way into its REST operation (#459).
 *
 * The tool names the registry operation and its path parameters; the method
 * and path come from that operation's REST binding, so a tool cannot reach a
 * route other than the one its operation documents. The route then does all
 * of the work - authorization, validation, versions, receipts, audit - exactly
 * as for a REST client, and only refusals written for the caller cross back
 * ({@see AgentMcpApiFailure}).
 */
final class AgentMcpRestCall
{
    public function __construct(
        private readonly AgentApiTransport $api,
        private readonly OperationRegistry $operations,
        private readonly ?McpRequestContext $context = null,
    ) {}

    public function forContext(McpRequestContext $context): self
    {
        return new self($this->api, $this->operations, $context);
    }

    /**
     * @param  array<string, string>  $path  the binding's path parameters, by name
     * @param  array<string, mixed>|null  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function call(string $operationId, array $path, ?array $body = null, ?string $idempotencyKey = null, array $query = []): array
    {
        $rest = $this->operations->find($operationId)?->rest
            ?? throw new LogicException("Operation [{$operationId}] has no REST binding.");
        $names = $rest->pathParameters;
        $given = array_keys($path);
        sort($names);
        sort($given);
        if ($names !== $given) {
            throw new LogicException("Operation [{$operationId}] takes the path parameters [".implode(', ', $rest->pathParameters).'].');
        }
        $uri = preg_replace_callback('/\{([^}]+)\}/', static fn (array $match): string => rawurlencode($path[$match[1]]), $rest->path)
            ?? throw new LogicException("Operation [{$operationId}] has an unusable path.");

        $headers = $idempotencyKey === null ? [] : ['Idempotency-Key' => $idempotencyKey];
        if ($this->context !== null) {
            $headers['X-Request-Id'] = $this->context->requestId;
        }
        $response = $this->api->send($rest->method, ltrim($uri, '/'), query: $query, json: $body, headers: $headers);
        if ($response->status >= 200 && $response->status < 300 && $response->json !== null) {
            return $response->json;
        }

        throw new ToolCallException(AgentMcpApiFailure::message($response->status, $response->json));
    }
}
