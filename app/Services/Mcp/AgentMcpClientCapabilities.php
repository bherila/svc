<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;

/**
 * The MCP contracts for client companies, kept beside each other and out of the
 * general registry factory.
 *
 * Every tool here is workspace-manager-only, so the fields that follow from
 * that are stated once in `tool()` rather than beside each definition.
 */
final class AgentMcpClientCapabilities
{
    /** @return list<McpCapabilityDefinition> */
    public function definitions(AgentMcpClientTools $clients, AgentMcpClientWriteTools $writes): array
    {
        return [...$this->reads($clients), ...($this->writesEnabled() ? $this->writes($writes) : [])];
    }

    /**
     * Nested inside the workflow cutover rather than beside it: turning
     * `AGENT_API_WRITES_ENABLED` off withdraws these along with everything else,
     * and this switch only ever narrows further.
     */
    private function writesEnabled(): bool
    {
        return (bool) config('agent_api.writes_enabled') && (bool) config('agent_api.client_writes_enabled');
    }

    /** @return list<McpCapabilityDefinition> */
    private function reads(AgentMcpClientTools $clients): array
    {
        return [
            $this->tool(
                name: 'clients.list',
                title: 'List clients',
                description: 'List client companies visible to a workspace manager. Archived clients are included unless a status is given.',
                handler: [$clients, 'list'],
                properties: [
                    'status' => ['type' => ['string', 'null'], 'enum' => ['active', 'archived', null]],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                    'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
                ],
                output: $this->listOutput(),
                scope: 'clients:read',
            ),
            $this->tool(
                name: 'clients.get',
                title: 'Get client',
                description: 'Get one client company and its invoice-delivery settings.',
                handler: [$clients, 'get'],
                properties: ['client_id' => ['type' => 'string', 'format' => 'uuid']],
                required: ['client_id'],
                output: $this->itemOutput(),
                scope: 'clients:read',
            ),
        ];
    }

    /** @return list<McpCapabilityDefinition> */
    private function writes(AgentMcpClientWriteTools $writes): array
    {
        $key = ['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]];
        $clientId = ['client_id' => ['type' => 'string', 'format' => 'uuid']];
        $agreementId = ['agreement_id' => ['type' => 'string', 'format' => 'uuid']];

        return [
            $this->tool(
                name: 'clients.create',
                title: 'Create client',
                description: 'Create a client company. Idempotency key required; a retry with the same key returns the first result.',
                handler: [$writes, 'clientsCreate'],
                properties: [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                    'billing_email' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 255],
                    ...$key,
                ],
                required: ['name', 'idempotency_key'],
                output: $this->itemOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'clients.update',
                title: 'Update client',
                description: 'Change only the client fields you send; omitted fields are left alone and billing_email may be null to clear it. Enabling automatic invoice delivery requires automatic_invoice_email_delay_days and a billing email or portal recipient; disabling it cancels deliveries still scheduled.',
                handler: [$writes, 'clientsUpdate'],
                properties: [
                    ...$clientId,
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 160],
                    'billing_email' => ['type' => ['string', 'null'], 'format' => 'email', 'maxLength' => 255],
                    'automatic_invoice_email_enabled' => ['type' => 'boolean'],
                    'automatic_invoice_email_delay_days' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 365],
                    'is_active' => ['type' => 'boolean'],
                    ...$key,
                ],
                required: ['client_id', 'idempotency_key'],
                output: $this->itemOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'clients.archive',
                title: 'Archive client',
                description: 'Deactivate a client company. Nothing is deleted: its invoices, time, payments and agreements are kept, and clients.restore reverses it.',
                handler: [$writes, 'clientsArchive'],
                properties: [...$clientId, ...$key],
                required: ['client_id', 'idempotency_key'],
                output: $this->itemOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
                destructive: true,
            ),
            $this->tool(
                name: 'clients.restore',
                title: 'Restore client',
                description: 'Reactivate an archived client company.',
                handler: [$writes, 'clientsRestore'],
                properties: [...$clientId, ...$key],
                required: ['client_id', 'idempotency_key'],
                output: $this->itemOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'agreements.create',
                title: 'Create agreement',
                description: 'Create a draft agreement for a client, covering the whole client. Amounts are minor currency units and retainer_minutes are whole minutes. It bills nothing until activated with agreements.activate.',
                handler: [$writes, 'agreementsCreate'],
                properties: [
                    ...$clientId,
                    ...$this->agreementTerms(),
                    ...$key,
                ],
                required: ['client_id', 'title', 'starts_on', 'currency', 'idempotency_key'],
                output: $this->agreementOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'agreements.update',
                title: 'Update agreement',
                description: 'Correct only the agreement terms you send; omitted terms are left alone and a nullable term sent as null is cleared. Status and signature are not editable here. Changing dates on an active agreement is refused if it would overlap another active one.',
                handler: [$writes, 'agreementsUpdate'],
                properties: [
                    ...$agreementId,
                    ...$this->agreementTerms(),
                    'period_retainer_amount' => $this->minorUnits(),
                    'period_retainer_minutes' => $this->minorUnits(),
                    'catch_up_threshold_minutes' => $this->minorUnits(),
                    'rollover_months' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120],
                    'rollover_policy' => ['type' => ['string', 'null'], 'maxLength' => 40],
                    'first_cycle_proration' => ['type' => ['string', 'null'], 'enum' => ['prorate_hours', 'full_period', 'align_next_cycle', null]],
                    'bill_overage_interim' => ['type' => ['boolean', 'null']],
                    ...$key,
                ],
                required: ['agreement_id', 'idempotency_key'],
                output: $this->agreementOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'agreements.activate',
                title: 'Activate agreement',
                description: 'Activate a draft or paused agreement so it governs billing. Refused if it would overlap another active agreement for the same client.',
                handler: [$writes, 'agreementsActivate'],
                properties: [...$agreementId, ...$key],
                required: ['agreement_id', 'idempotency_key'],
                output: $this->agreementOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
            ),
            $this->tool(
                name: 'agreements.terminate',
                title: 'Terminate agreement',
                description: 'Retire an agreement without deleting it: its invoices and capacity history are kept and ends_on stops it governing later periods (default today). Irreversible: a terminated agreement cannot be reactivated, so correct a mistake with a new agreement.',
                handler: [$writes, 'agreementsTerminate'],
                properties: [
                    ...$agreementId,
                    'ends_on' => ['type' => ['string', 'null'], 'format' => 'date'],
                    ...$key,
                ],
                required: ['agreement_id', 'idempotency_key'],
                output: $this->agreementOutput(),
                scope: 'clients:write',
                write: true,
                idempotent: false,
                destructive: true,
            ),
        ];
    }

    /**
     * The terms create and update share; create requires the first three, update none.
     *
     * @return array<string, mixed>
     */
    private function agreementTerms(): array
    {
        return [
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'starts_on' => ['type' => 'string', 'format' => 'date'],
            'ends_on' => ['type' => ['string', 'null'], 'format' => 'date'],
            'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
            'billing_cadence' => ['type' => 'string', 'enum' => ['one_time', 'monthly', 'quarterly', 'semi_annual', 'annual']],
            'agreement_text' => ['type' => ['string', 'null'], 'maxLength' => 30000],
            'is_visible_to_client' => ['type' => 'boolean'],
            'hourly_rate_amount' => $this->minorUnits(),
            'retainer_amount' => $this->minorUnits(),
            'retainer_minutes' => $this->minorUnits(),
        ];
    }

    /** @return array<string, mixed> */
    private function minorUnits(): array
    {
        return ['type' => ['integer', 'null'], 'minimum' => 0];
    }

    /**
     * @param  array{0: object, 1: string}  $handler
     * @param  array<string, mixed>  $properties  beyond workspace_id, which every tool takes
     * @param  list<string>  $required  beyond workspace_id
     * @param  array<string, mixed>  $output
     */
    private function tool(
        string $name,
        string $title,
        string $description,
        array $handler,
        array $properties,
        array $output,
        string $scope,
        array $required = [],
        bool $write = false,
        bool $idempotent = true,
        bool $destructive = false,
    ): McpCapabilityDefinition {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: $name,
            title: $title,
            description: $description,
            handler: $handler,
            inputSchema: [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id', ...$required],
                'properties' => ['workspace_id' => ['type' => 'string', 'format' => 'uuid'], ...$properties],
            ],
            outputSchema: $output,
            requiredScopes: [$scope],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: ! $write,
            idempotent: $idempotent,
            destructive: $destructive,
            rateLimitBucket: $write ? 'mcp-write' : 'mcp-read',
            auditClassification: $write ? 'agent_api.write' : 'agent_api.read',
            featureFlag: $write ? 'mcp.write.clients' : 'mcp.read.clients',
        );
    }

    /** @return array<string, mixed> */
    private function itemOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => ['data' => $this->clientDto()],
        ];
    }

    /** @return array<string, mixed> */
    private function agreementOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data'],
            'properties' => ['data' => AgentMcpAgreementSchema::dto()],
        ];
    }

    /** @return array<string, mixed> */
    private function listOutput(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['data', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => $this->clientDto()],
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
    private function clientDto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['id', 'name', 'billing_email', 'is_active', 'automatic_invoice_email_enabled', 'automatic_invoice_email_delay_days'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string', 'maxLength' => 160],
                'billing_email' => ['type' => ['string', 'null'], 'maxLength' => 255],
                'is_active' => ['type' => 'boolean'],
                'automatic_invoice_email_enabled' => ['type' => 'boolean'],
                'automatic_invoice_email_delay_days' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 365],
            ],
        ];
    }
}
