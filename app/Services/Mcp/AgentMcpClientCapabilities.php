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
    public function definitions(AgentMcpClientTools $clients): array
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
