<?php

namespace App\Services\Mcp;

use App\Services\AgentApi\AgentCapacityLedgerReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP adapter over the canonical capacity ledger read workflow. */
final class AgentMcpCapacityLedgerTools
{
    public function __construct(
        private readonly AgentCapacityLedgerReadService $ledger,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function get(
        #[Schema(format: 'uuid')] string $workspace_id,
        #[Schema(format: 'uuid')] string $agreement_id,
        #[Schema(minimum: 1, maximum: 60)] int $months = 12,
    ): array {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->ledger->get($context->principal->subject, $context->workspace, $agreement_id, $months)];
    }

    private function workspace(string $workspaceId): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP capacity ledger tools require a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
