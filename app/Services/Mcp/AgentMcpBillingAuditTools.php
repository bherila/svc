<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentBillingAuditReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP adapters over the canonical aggregate-only billing audits. */
final class AgentMcpBillingAuditTools
{
    public function __construct(
        private readonly AgentBillingAuditReadService $audits,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array{data: array<string, float|int>} */
    public function unplaceableInvoices(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->audits->unplaceableInvoices($context->principal->subject, $context->workspace)];
    }

    /** @return array{data: array<string, array<string, int>|int>} */
    public function undatedCollectibleInvoices(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->audits->undatedCollectibleInvoices($context->principal->subject, $context->workspace)];
    }

    /** @return array{data: array<string, int>} */
    public function missingBilledOverage(#[Schema(format: 'uuid')] string $workspace_id): array
    {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->audits->missingBilledOverage($context->principal->subject, $context->workspace)];
    }

    private function workspace(string $workspaceId): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP billing audit tools require a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
