<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use App\Services\Mcp\Registry\McpCapabilityRegistry;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;

/** Builds the reviewed MCP registry from the compatibility catalog. */
final class AgentMcpCapabilityRegistryFactory
{
    public function __construct(
        private readonly AgentMcpToolCatalog $catalog,
        private readonly AgentMcpInputSchemaFactory $inputs,
        private readonly AgentMcpOutputSchemaFactory $outputs,
    ) {}

    public function make(AgentMcpReadTools $reads, AgentMcpWriteTools $writes): McpCapabilityRegistry
    {
        $registry = new McpCapabilityRegistry;
        foreach ($this->catalog->definitions($reads, $writes) as $tool) {
            $registry->register($this->definition($tool));
        }

        return $registry;
    }

    private function definition(ToolDefinition $tool): McpCapabilityDefinition
    {
        $operationId = $tool->operationId();

        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: $tool->name,
            title: $tool->title,
            description: $tool->description,
            handler: $tool->handler,
            inputSchema: $this->inputs->for($tool),
            outputSchema: $this->outputs->for($tool),
            requiredScopes: AgentApiResponseSchemaCatalog::scopesForOperation($operationId),
            policyAbility: $this->policyAbility($tool->name),
            requiresWorkspace: $tool->name !== 'context.get',
            readOnly: $tool->readOnly,
            idempotent: $tool->idempotent,
            destructive: $tool->destructive,
            rateLimitBucket: $tool->readOnly ? 'mcp-read' : 'mcp-write',
            auditClassification: $tool->readOnly ? 'agent_api.read' : 'agent_api.write',
            featureFlag: $tool->readOnly ? 'mcp.read' : 'mcp.write',
        );
    }

    private function policyAbility(string $name): string
    {
        return match (true) {
            $name === 'context.get', $name === 'operations.summary' => 'AgentAccess::canViewWorkspace',
            str_starts_with($name, 'projects.') => 'AgentAccess::canViewProject',
            str_starts_with($name, 'tasks.') => 'AgentAccess::canViewTask',
            str_starts_with($name, 'time_entries.') => 'AgentAccess::canViewTime',
            str_starts_with($name, 'invoices.') => 'AgentAccess::canViewInvoice',
            default => 'Agent API workflow policy',
        };
    }
}
