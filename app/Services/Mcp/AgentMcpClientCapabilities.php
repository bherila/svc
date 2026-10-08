<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentWriteCutover;

/** Manager-only metadata; the REST contract supplies mutation bodies and every response. */
final class AgentMcpClientCapabilities
{
    /** @return list<McpCapabilityDefinition> */
    public function definitions(AgentMcpClientTools $clients, AgentMcpClientWriteTools $writes): array
    {
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $definitions = [
            $this->tool('clients.list', 'List clients', 'List client companies visible to a workspace manager, including archived clients unless filtered.', [$clients, 'list'], 'clients:read', properties: [
                'status' => ['type' => ['string', 'null'], 'enum' => ['active', 'archived', null]],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            ]),
            $this->tool('clients.get', 'Get client', 'Get one client company, its current version and invoice-delivery settings.', [$clients, 'get'], 'clients:read', properties: ['client_id' => $uuid], required: ['client_id']),
        ];
        if (! AgentWriteCutover::clients()) {
            return $definitions;
        }

        $key = ['idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255]];
        foreach ([
            ['clients.create', 'Create client', 'Create a client company. Retry only an identical request with the same key.', 'clientsCreate', null, false],
            ['clients.update', 'Update client', 'Change only the fields sent using the current version. Omitted fields are unchanged and nullable fields can be cleared. Enabling automatic delivery requires a non-null delay and configured invoice recipients. Disabling automatic delivery cancels pending deliveries.', 'clientsUpdate', 'client_id', false],
            ['clients.archive', 'Archive client', 'Deactivate a client using the current version. Invoices, time, payments and agreements are retained; restore reverses it.', 'clientsArchive', 'client_id', true],
            ['clients.restore', 'Restore client', 'Reactivate an archived client using the current version.', 'clientsRestore', 'client_id', false],
            ['agreements.create', 'Create agreement', 'Create a client-wide draft agreement using the parent client version. Amounts are minor units and retainer_minutes are whole minutes. It bills nothing until activated.', 'agreementsCreate', 'client_id', false],
            ['agreements.update', 'Update agreement', 'Correct only the terms sent using the current version. Omitted fields are unchanged; nullable fields can be cleared. Status and signature cannot be edited here.', 'agreementsUpdate', 'agreement_id', false],
            ['agreements.activate', 'Activate agreement', 'Activate a draft or paused agreement so it governs billing. Requires explicit user confirmation and the current version. Overlapping active agreements are refused.', 'agreementsActivate', 'agreement_id', false],
            ['agreements.terminate', 'Terminate agreement', 'End an agreement using the current version after explicit user confirmation. Termination is irreversible. ends_on defaults to today in the workspace timezone, never extending an earlier end or ending before the start.', 'agreementsTerminate', 'agreement_id', true],
        ] as [$name, $title, $description, $method, $id, $destructive]) {
            $properties = $id === null ? $key : [$id => $uuid, ...$key];
            $required = $id === null ? ['idempotency_key'] : [$id, 'idempotency_key'];
            $definitions[] = $this->tool($name, $title, $description, [$writes, $method], 'clients:write', true, $properties, $required, $destructive);
        }

        return $definitions;
    }

    /** @param array{object, string} $handler
     * @param array<string, mixed> $properties
     * @param list<string> $required */
    private function tool(string $name, string $title, string $description, array $handler, string $scope, bool $write = false, array $properties = [], array $required = [], bool $destructive = false): McpCapabilityDefinition
    {
        $constraints = [];
        if ($write) {
            $body = AgentApiResponseSchemaCatalog::requestForOperation($name);
            $constraints = array_intersect_key($body, ['allOf' => true]);
            $properties = [...$properties, ...$body['properties']];
            $required = [...$required, ...$body['required']];
        }

        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: $name,
            title: $title,
            description: $description,
            handler: $handler,
            inputSchema: [
                ...$constraints,
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['workspace_id', ...$required],
                'properties' => ['workspace_id' => ['type' => 'string', 'format' => 'uuid'], ...$properties],
            ],
            outputSchema: AgentApiResponseSchemaCatalog::forOperation($name),
            requiredScopes: $write ? AgentApiResponseSchemaCatalog::scopesForOperation($name) : [$scope],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: ! $write,
            idempotent: ! $write,
            destructive: $destructive,
            rateLimitBucket: $write ? 'mcp-write' : 'mcp-read',
            auditClassification: $write ? 'agent_api.write' : 'agent_api.read',
            featureFlag: $write ? 'mcp.write.clients' : 'mcp.read.clients',
        );
    }
}
