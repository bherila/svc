<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpClientContactTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** These private business records must never inherit portal-contact authority. */
#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentRestDocumentation::class)]
final class BusinessContactOperationContractTest extends TestCase
{
    public function test_contacts_declare_current_manager_scope_read_versions_and_archive_confirmation(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            foreach ([
                'list' => ['listing', 'GET', false, false, Effect::Read],
                'get' => ['get', 'GET', false, false, Effect::Read],
                'create' => ['create', 'POST', true, false, Effect::LocalWrite],
                'update' => ['update', 'PATCH', true, true, Effect::LocalWrite],
                'archive' => ['archive', 'POST', true, true, Effect::Destructive],
                'restore' => ['restore', 'POST', true, true, Effect::LocalWrite],
            ] as $name => [$handler, $method, $write, $versioned, $effect]) {
                $id = 'clients.contacts.'.$name;
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertTrue($catalog->isManagerOnly($id), $id);
                $this->assertSame([AgentMcpClientContactTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertSame($method, $operation->rest->method, $id);
                $this->assertSame($effect, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertSame($write ? ($versioned ? ['clients:write', 'clients:read'] : ['clients:write']) : ['clients:read'], $operation->requirement->scopes, $id);
                $this->assertSame($versioned, $operation->safety->expectedVersion, $id);
                $this->assertSame($name === 'archive', $operation->safety->confirm, $id);
                $this->assertSame($write ? IdempotencyKey::HeaderAndArgument : IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
                $this->assertSame([
                    ...($write ? [AgentDeploymentFlags::CLIENTS] : []), 'mcp',
                    'mcp.switch:mcp.'.($write ? 'write' : 'read').'.clients', 'mcp.switch:'.$id,
                ], $operation->requirement->flags, $id);
                $this->assertSame($write ? ['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED'] : [], $operation->extensions['x-svc-flags'] ?? [], $id);
                $this->assertSame($name === 'create' ? [201] : [200], $operation->rest->successStatuses, $id);
                $path = '/workspaces/{workspace}/clients/{client}/contacts';
                if (! in_array($name, ['list', 'create'], true)) {
                    $path .= '/{contact}';
                }
                if (in_array($name, ['archive', 'restore'], true)) {
                    $path .= '/'.$name;
                }
                $this->assertSame($path, $operation->rest->path, $id);
                $this->assertIsArray($operation->input);
                $this->assertFalse($operation->input['additionalProperties'], $id);
                foreach (['workspace_id', 'client_id'] as $identity) {
                    $this->assertContains($identity, $operation->input['required'], $id);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $operation->input['properties'][$identity], $id);
                }
                if (! in_array($name, ['list', 'create'], true)) {
                    $this->assertContains('contact_id', $operation->input['required'], $id);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $operation->input['properties']['contact_id'], $id);
                }
                if ($write) {
                    $this->assertContains('idempotency_key', $operation->input['required'], $id);
                    $this->assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], $operation->input['properties']['idempotency_key'], $id);
                    $this->assertWriteBody($name, $operation->input);
                }
                if ($name === 'list') {
                    $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], $operation->input['properties']['limit']);
                    $this->assertSame(['type' => ['string', 'null'], 'maxLength' => 2048], $operation->input['properties']['cursor']);
                    $this->assertSame(['type' => ['string', 'null'], 'enum' => ['active', 'archived', null]], $operation->input['properties']['status']);
                }

            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_rest_documentation_keeps_tenant_identity_permissions_and_request_response_bindings(): void
    {
        $client = ['name' => 'client', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $contact = ['name' => 'contact', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        $documents = AgentRestDocumentation::operations();
        foreach ([
            'list' => ['GET', ['clients:read'], ['WorkspaceId', $client, 'Limit', 'Cursor', ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['active', 'archived']]]], null, 'ClientBusinessContactListResponse'],
            'get' => ['GET', ['clients:read'], ['WorkspaceId', $client, $contact], null, 'ClientBusinessContactResponse'],
            'create' => ['POST', ['clients:write'], ['WorkspaceId', $client, 'IdempotencyKey'], 'clients_contacts_createRequest', 'ClientBusinessContactResponse'],
            'update' => ['PATCH', ['clients:write', 'clients:read'], ['WorkspaceId', $client, $contact, 'IdempotencyKey'], 'clients_contacts_updateRequest', 'ClientBusinessContactResponse'],
            'archive' => ['POST', ['clients:write', 'clients:read'], ['WorkspaceId', $client, $contact, 'IdempotencyKey'], 'clients_contacts_archiveRequest', 'ClientBusinessContactResponse'],
            'restore' => ['POST', ['clients:write', 'clients:read'], ['WorkspaceId', $client, $contact, 'IdempotencyKey'], 'clients_contacts_restoreRequest', 'ClientBusinessContactResponse'],
        ] as $name => [$method, $scopes, $parameters, $request, $response]) {
            $id = 'clients.contacts.'.$name;
            $document = $documents[$id];
            $this->assertSame($method, $document['method'], $id);
            $this->assertSame($scopes, $document['scopes'], $id);
            $this->assertSame($parameters, $document['parameters'], $id);
            $this->assertSame($response, $document['response'], $id);
            if ($request === null) {
                $this->assertArrayNotHasKey('request', $document, $id);
            } else {
                $this->assertSame($request, $document['request'], $id);
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function assertWriteBody(string $name, array $input): void
    {
        $properties = [
            'workspace_id' => ['type' => 'string', 'format' => 'uuid'],
            'client_id' => ['type' => 'string', 'format' => 'uuid'],
            'idempotency_key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        ];
        $required = ['workspace_id', 'client_id', 'idempotency_key'];
        if (in_array($name, ['create', 'update'], true)) {
            $properties += [
                'first_name' => ['type' => ['string', 'null'], 'maxLength' => 100],
                'last_name' => ['type' => ['string', 'null'], 'maxLength' => 100],
                'title' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'email' => ['type' => ['string', 'null'], 'maxLength' => 255, 'format' => 'email'],
                'phone' => ['type' => ['string', 'null'], 'maxLength' => 191],
                'notes' => ['type' => ['string', 'null'], 'maxLength' => 20000],
            ];
        }
        if ($name === 'create') {
            $properties['is_archived'] = ['type' => 'boolean', 'default' => false];
            $this->assertSame([['anyOf' => [
                ['required' => ['first_name'], 'properties' => ['first_name' => ['type' => 'string', 'minLength' => 1]]],
                ['required' => ['last_name'], 'properties' => ['last_name' => ['type' => 'string', 'minLength' => 1]]],
            ]]], $input['allOf']);
        } else {
            $properties['contact_id'] = ['type' => 'string', 'format' => 'uuid'];
            $properties['expected_version'] = ['type' => 'string', 'minLength' => 64, 'maxLength' => 64];
            $required = [...$required, 'contact_id', 'expected_version'];
            $this->assertArrayNotHasKey('allOf', $input);
        }
        if ($name === 'archive') {
            $properties['confirm'] = ['type' => 'boolean', 'const' => true];
            $required[] = 'confirm';
        }
        // Property order is presentation; presence, types and required safety
        // fields are the contract that makes a valid call possible.
        $actualProperties = $input['properties'];
        ksort($properties);
        ksort($actualProperties);
        $actualRequired = $input['required'];
        sort($required);
        sort($actualRequired);
        $this->assertSame($properties, $actualProperties, $name);
        $this->assertSame($required, $actualRequired, $name);
    }
}
