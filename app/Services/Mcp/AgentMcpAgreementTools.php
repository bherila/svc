<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentAgreementReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP adapter over the manager-scoped agreement read workflow. */
final class AgentMcpAgreementTools
{
    public function __construct(
        private readonly AgentAgreementReadService $agreements,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function list(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(enum: ['draft', 'active', 'terminated', 'expired'])] ?string $status = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id);

        return $this->agreements->list($context->principal->subject, $context->workspace, $status, $limit, $cursor);
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $agreement_id): array
    {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->agreements->get($context->principal->subject, $context->workspace, $agreement_id)];
    }

    private function workspace(string $workspaceId): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP agreement tools require a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
