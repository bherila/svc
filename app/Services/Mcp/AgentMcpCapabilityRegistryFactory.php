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

    public function make(AgentMcpReadTools $reads, AgentMcpAgreementTools $agreements, AgentMcpBillingScheduleTools $schedules, AgentMcpWriteTools $writes): McpCapabilityRegistry
    {
        $registry = new McpCapabilityRegistry;
        foreach ($this->catalog->definitions($reads, $writes) as $tool) {
            $registry->register($this->definition($tool));
        }
        $registry->register($this->agreementList($agreements));
        $registry->register($this->agreementGet($agreements));
        $registry->register($this->billingScheduleList($schedules));
        $registry->register($this->billingScheduleGet($schedules));

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

    private function agreementList(AgentMcpAgreementTools $tools): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'agreements.list',
            title: 'List agreements',
            description: 'List active or historical agreement terms visible to a workspace manager.',
            handler: [$tools, 'list'],
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id'],
                'properties' => [
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'status' => ['type' => ['string', 'null'], 'enum' => ['draft', 'active', 'terminated', 'expired', null]],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                ],
            ],
            outputSchema: $this->agreementListOutput(),
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.agreements',
        );
    }

    private function agreementGet(AgentMcpAgreementTools $tools): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'agreements.get',
            title: 'Get agreement',
            description: 'Get one agreement and its derived recurring billing terms.',
            handler: [$tools, 'get'],
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id', 'agreement_id'],
                'properties' => [
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => ['data' => $this->agreementDto()],
            ],
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.agreements',
        );
    }

    /** @return array<string, mixed> */
    private function agreementListOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'maxItems' => 100, 'items' => $this->agreementDto()],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['next_cursor'],
                    'properties' => ['next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function agreementDto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'title', 'status', 'currency', 'billing_cadence', 'is_recurring', 'starts_on', 'ends_on', 'signed_at', 'retainer_minutes_per_period', 'retainer_amount_per_period', 'hourly_rate_amount', 'rollover_months', 'project'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'title' => ['type' => 'string', 'maxLength' => 255],
                'status' => ['type' => 'string', 'maxLength' => 64],
                'currency' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 3],
                'billing_cadence' => ['type' => ['string', 'null'], 'maxLength' => 32],
                'is_recurring' => ['type' => 'boolean'],
                'starts_on' => ['type' => 'string', 'format' => 'date'],
                'ends_on' => ['type' => ['string', 'null'], 'format' => 'date'],
                'signed_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'retainer_minutes_per_period' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'retainer_amount_per_period' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'hourly_rate_amount' => ['type' => ['integer', 'null'], 'minimum' => 0],
                'rollover_months' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120],
                'project' => ['type' => ['string', 'null'], 'maxLength' => 255],
            ],
        ];
    }

    private function billingScheduleList(AgentMcpBillingScheduleTools $tools): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'billing_schedules.list',
            title: 'List billing schedules',
            description: 'List bounded recurring billing schedules visible to a workspace manager.',
            handler: [$tools, 'list'],
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id'],
                'properties' => [
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'is_active' => ['type' => ['boolean', 'null']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                ],
            ],
            outputSchema: $this->billingScheduleListOutput(),
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.billing_schedules',
        );
    }

    private function billingScheduleGet(AgentMcpBillingScheduleTools $tools): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'billing_schedules.get',
            title: 'Get billing schedule',
            description: 'Get one bounded recurring billing schedule.',
            handler: [$tools, 'get'],
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id', 'schedule_id'],
                'properties' => [
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'schedule_id' => ['type' => 'string', 'format' => 'uuid'],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => ['data' => $this->billingScheduleDto()],
            ],
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.billing_schedules',
        );
    }

    /** @return array<string, mixed> */
    private function billingScheduleListOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'maxItems' => 100, 'items' => $this->billingScheduleDto()],
                'meta' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['next_cursor'], 'properties' => ['next_cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function billingScheduleDto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'agreement_id', 'cadence', 'next_run_on', 'is_active'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                'cadence' => ['type' => 'string', 'maxLength' => 32],
                'next_run_on' => ['type' => 'string', 'format' => 'date'],
                'is_active' => ['type' => 'boolean'],
            ],
        ];
    }
}
