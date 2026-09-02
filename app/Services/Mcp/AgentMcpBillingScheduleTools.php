<?php

namespace App\Services\Mcp;

use App\Exceptions\InvalidAgentApiCursor;
use App\Services\AgentApi\AgentBillingScheduleReadService;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpRequestContext;
use LogicException;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;

/** Thin MCP adapter over the bounded billing-schedule read workflow. */
final class AgentMcpBillingScheduleTools
{
    public function __construct(
        private readonly AgentBillingScheduleReadService $schedules,
        private readonly McpAccountContextResolver $accounts,
        private readonly ?McpRequestContext $requestContext = null,
    ) {}

    /** @return array<string, mixed> */
    public function list(
        #[Schema(format: 'uuid')] string $workspace_id,
        ?bool $is_active = null,
        #[Schema(minimum: 1, maximum: 100)] int $limit = 25,
        #[Schema(maxLength: 2048)] ?string $cursor = null,
    ): array {
        $context = $this->workspace($workspace_id);

        try {
            return $this->schedules->list($context->principal->subject, $context->workspace, $is_active, $limit, $cursor);
        } catch (InvalidAgentApiCursor) {
            throw new ToolCallException('The pagination cursor is not valid for this request.');
        }
    }

    /** @return array<string, mixed> */
    public function get(#[Schema(format: 'uuid')] string $workspace_id, #[Schema(format: 'uuid')] string $schedule_id): array
    {
        $context = $this->workspace($workspace_id);

        return ['data' => $this->schedules->get($context->principal->subject, $context->workspace, $schedule_id)];
    }

    private function workspace(string $workspaceId): McpRequestContext
    {
        $context = $this->requestContext ?? throw new LogicException('MCP billing schedule tools require a request context.');
        if (! $context->principal->hasScope('billing:read')) {
            throw new ToolCallException('This connection lacks the required permission.');
        }

        return $this->accounts->resolve($context, $workspaceId);
    }
}
