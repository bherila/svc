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

    public function make(AgentMcpReadTools $reads, AgentMcpContextResource $contextResource, AgentMcpAgreementTools $agreements, AgentMcpAgreementResource $agreementResource, AgentMcpBillingScheduleTools $schedules, AgentMcpCapacityLedgerTools $capacityLedger, AgentMcpBillingAuditTools $billingAudits, AgentMcpPrompts $prompts, AgentMcpWriteTools $writes): McpCapabilityRegistry
    {
        $registry = new McpCapabilityRegistry;
        foreach ($this->catalog->definitions($reads, $writes) as $tool) {
            $registry->register($this->definition($tool));
        }
        $registry->register($this->contextResource($contextResource));
        $registry->register($this->agreementList($agreements));
        $registry->register($this->agreementGet($agreements));
        $registry->register($this->agreementResource($agreementResource));
        $registry->register($this->billingScheduleList($schedules));
        $registry->register($this->billingScheduleGet($schedules));
        $registry->register($this->capacityLedgerGet($capacityLedger));
        $registry->register($this->unplaceableInvoicesAudit($billingAudits));
        $registry->register($this->undatedCollectibleInvoicesAudit($billingAudits));
        $registry->register($this->missingBilledOverageAudit($billingAudits));
        $registry->register($this->logTimePrompt($prompts));
        $registry->register($this->prepareInvoicePrompt($prompts));

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

    private function agreementResource(AgentMcpAgreementResource $resource): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::ResourceTemplate,
            name: 'agreement',
            title: 'Agreement',
            description: 'Read one canonical agreement representation visible to a workspace manager.',
            handler: [$resource, 'read'],
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
            uri: 'svc://workspaces/{workspace_id}/agreements/{agreement_id}',
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

    private function contextResource(AgentMcpContextResource $resource): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Resource,
            name: 'current-context',
            title: 'Current SVC context',
            description: 'The authenticated SVC identity and authorized workspaces.',
            handler: [$resource, 'read'],
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            outputSchema: AgentApiResponseSchemaCatalog::forOperation('context.get'),
            requiredScopes: ['identity:read'],
            policyAbility: 'AgentAccess::canViewWorkspace',
            requiresWorkspace: false,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.context',
            uri: 'svc://context',
        );
    }

    private function capacityLedgerGet(AgentMcpCapacityLedgerTools $tools): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'capacity_ledger.get',
            title: 'Get capacity ledger',
            description: 'Get a bounded trailing window of the computed agreement capacity ledger.',
            handler: [$tools, 'get'],
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id', 'agreement_id'],
                'properties' => [
                    'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
                    'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                    'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'default' => 12],
                ],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['agreement_id', 'through', 'months'],
                        'properties' => [
                            'agreement_id' => ['type' => 'string', 'format' => 'uuid'],
                            'through' => ['type' => 'string', 'format' => 'date'],
                            'months' => ['type' => 'array', 'maxItems' => 60, 'items' => $this->capacityLedgerMonth()],
                        ],
                    ],
                ],
            ],
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.capacity_ledger',
        );
    }

    private function logTimePrompt(AgentMcpPrompts $prompts): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Prompt,
            name: 'log-time-across-projects',
            title: 'Log time across projects',
            description: 'Guide an authorized client through bounded, retry-safe time logging.',
            handler: [$prompts, 'logTimeAcrossProjects'],
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            outputSchema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['user'], 'properties' => ['user' => ['type' => 'string', 'maxLength' => 4096]]],
            requiredScopes: ['identity:read', 'projects:read', 'time:write'],
            policyAbility: 'Agent API workflow policies',
            requiresWorkspace: false,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.prompt',
            featureFlag: 'mcp.prompt.log_time_across_projects',
            requiredCapabilities: ['context.get', 'projects.list', 'time_entries.log'],
        );
    }

    private function prepareInvoicePrompt(AgentMcpPrompts $prompts): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Prompt,
            name: 'prepare-invoice-safely',
            title: 'Prepare an invoice safely',
            description: 'Guide an authorized client through reviewing and preparing an invoice draft.',
            handler: [$prompts, 'prepareInvoiceSafely'],
            inputSchema: ['type' => 'object', 'additionalProperties' => false],
            outputSchema: ['type' => 'object', 'additionalProperties' => false, 'required' => ['user'], 'properties' => ['user' => ['type' => 'string', 'maxLength' => 4096]]],
            requiredScopes: ['identity:read', 'projects:read', 'time:read', 'billing:read', 'billing:write'],
            policyAbility: 'Agent API workflow policies',
            requiresWorkspace: false,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.prompt',
            featureFlag: 'mcp.prompt.prepare_invoice_safely',
            requiredCapabilities: ['context.get', 'projects.get', 'time_entries.list', 'invoices.get', 'invoices.create_draft', 'invoices.update_draft'],
        );
    }

    private function unplaceableInvoicesAudit(AgentMcpBillingAuditTools $tools): McpCapabilityDefinition
    {
        return $this->billingAudit(
            name: 'billing.audit_unplaceable_invoices',
            title: 'Audit unplaceable invoices',
            description: 'Get aggregate counts of invoices whose billing period or cycle cannot be placed safely.',
            handler: [$tools, 'unplaceableInvoices'],
            properties: [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'without_a_service_period' => ['type' => 'integer', 'minimum' => 0],
                'charged_of_those' => ['type' => 'integer', 'minimum' => 0],
                'on_an_agreement_of_those' => ['type' => 'integer', 'minimum' => 0],
                'affected' => ['type' => 'integer', 'minimum' => 0],
                'overage_hours_at_stake' => ['type' => 'number', 'minimum' => 0],
                'without_a_cycle' => ['type' => 'integer', 'minimum' => 0],
                'of_a_kind_read_by_cycle' => ['type' => 'integer', 'minimum' => 0],
                'live_without_a_cycle' => ['type' => 'integer', 'minimum' => 0],
                'cycle_affected' => ['type' => 'integer', 'minimum' => 0],
                'cycle_overage_hours_at_stake' => ['type' => 'number', 'minimum' => 0],
            ],
        );
    }

    private function undatedCollectibleInvoicesAudit(AgentMcpBillingAuditTools $tools): McpCapabilityDefinition
    {
        return $this->billingAudit(
            name: 'billing.audit_undated_collectible_invoices',
            title: 'Audit undated collectible invoices',
            description: 'Get aggregate counts and per-currency balances for collectible invoices without due dates.',
            handler: [$tools, 'undatedCollectibleInvoices'],
            properties: [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'collectible' => ['type' => 'integer', 'minimum' => 0],
                'undated' => ['type' => 'integer', 'minimum' => 0],
                'with_an_issue_date' => ['type' => 'integer', 'minimum' => 0],
                'without_an_issue_date' => ['type' => 'integer', 'minimum' => 0],
                'would_become_overdue_if_backfilled' => ['type' => 'integer', 'minimum' => 0],
                'undated_balances' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0], 'maxProperties' => 100],
                'would_become_overdue_balances' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0], 'maxProperties' => 100],
            ],
        );
    }

    private function missingBilledOverageAudit(AgentMcpBillingAuditTools $tools): McpCapabilityDefinition
    {
        return $this->billingAudit(
            name: 'billing.audit_missing_billed_overage',
            title: 'Audit missing billed overage',
            description: 'Get aggregate counts of charged invoices missing billed-overage data.',
            handler: [$tools, 'missingBilledOverage'],
            properties: [
                'invoices' => ['type' => 'integer', 'minimum' => 0],
                'without_a_billed_overage' => ['type' => 'integer', 'minimum' => 0],
                'charged_of_those' => ['type' => 'integer', 'minimum' => 0],
                'on_an_agreement_of_those' => ['type' => 'integer', 'minimum' => 0],
                'agreements_affected' => ['type' => 'integer', 'minimum' => 0],
            ],
        );
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  array{0: object, 1: string}  $handler
     */
    private function billingAudit(string $name, string $title, string $description, array $handler, array $properties): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: $name,
            title: $title,
            description: $description,
            handler: $handler,
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id'],
                'properties' => ['workspace_id' => ['type' => 'string', 'format' => 'uuid']],
            ],
            outputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => array_keys($properties),
                        'properties' => $properties,
                    ],
                ],
            ],
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.'.$name,
        );
    }

    /** @return array<string, mixed> */
    private function capacityLedgerMonth(): array
    {
        $numbers = [
            'retainer_hours', 'hours_worked', 'opening_retainer_hours', 'opening_rollover_hours',
            'opening_expired_hours', 'opening_available_hours', 'hours_used_from_retainer',
            'hours_used_from_rollover', 'unused_hours', 'excess_hours', 'negative_hours',
            'remaining_rollover_hours',
        ];
        $properties = [
            'period' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$'],
            'cycle_start' => ['type' => ['string', 'null'], 'format' => 'date'],
            'bill_excess_immediately' => ['type' => 'boolean'],
        ];
        foreach ($numbers as $name) {
            $properties[$name] = ['type' => 'number'];
        }

        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }
}
