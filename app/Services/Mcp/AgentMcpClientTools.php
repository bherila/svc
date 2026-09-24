<?php

namespace App\Services\Mcp;

use App\Exceptions\InvalidAgentApiCursor;
use App\Services\AgentApi\AgentClientReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP adapter over the manager-scoped client company workflows. */
final class AgentMcpClientTools
{
    public function __construct(
        private readonly AgentClientReadService $clients,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function list(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['active', 'archived'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id, 'clients:read');

        try {
            return $this->clients->list($context->principal->subject, $context->workspace, $status, $limit, $cursor);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $client_id): array
    {
        $context = $this->workspace($workspace_id, 'clients:read');

        return ['data' => $this->clients->get($context->principal->subject, $context->workspace, $client_id)];
    }

    private function workspace(string $workspaceId, string $scope): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP client tools require a request context.');
        if (! $context->principal->hasScope($scope)) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
