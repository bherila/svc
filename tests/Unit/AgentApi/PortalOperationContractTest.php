<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\AgentMcpPortalAccessTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;

final class PortalOperationContractTest extends TestCase
{
    public function test_portal_operations_declare_the_public_transport_and_authority_contract(): void
    {
        // Only path helpers need a container; no providers, routes or database boot.
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            foreach ([
                'list' => ['GET', 'index', Effect::Read, [200]],
                'grant' => ['POST', 'store', Effect::LocalWrite, [201]],
                'update' => ['PATCH', 'update', Effect::Destructive, [200]],
                'revoke' => ['DELETE', 'destroy', Effect::Destructive, [200]],
            ] as $kind => [$method, $suffix, $effect, $statuses]) {
                $id = 'clients.portal_access.'.$kind;
                $operation = $registry->find($id);
                $this->assertNotNull($operation);
                $this->assertSame($effect, $operation->effect);
                $this->assertTrue($operation->idempotent);
                $this->assertTrue($catalog->isManagerOnly($id));
                $this->assertSame([AgentMcpPortalAccessTools::class, $kind], $operation->mcp->handler);
                $this->assertSame($method, $operation->rest->method);
                $this->assertSame('agent-api.v1.clients.portal-access.'.$suffix, $operation->rest->routeName);
                $this->assertSame('/workspaces/{workspace}/clients/{client}/portal-access'.(in_array($kind, ['update', 'revoke'], true) ? '/{user}' : ''), $operation->rest->path);
                $this->assertSame($statuses, $operation->rest->successStatuses);
                $this->assertContains('WorkspaceId', $operation->rest->parameters);
                $this->assertSame(['x-mcp-tool' => $id, 'x-svc-flags' => $kind === 'list' ? [] : ['AGENT_API_WRITES_ENABLED', 'AGENT_API_CLIENT_WRITES_ENABLED']], $operation->extensions);
                $this->assertSame($kind === 'list' ? ['clients:read'] : ($kind === 'grant' ? ['clients:write'] : ['clients:write', 'clients:read']), $operation->requirement->scopes);
                $this->assertSame($kind === 'list' ? ['mcp', 'mcp.switch:mcp.read.clients', 'mcp.switch:'.$id] : [AgentDeploymentFlags::CLIENTS, 'mcp', 'mcp.switch:mcp.write.clients', 'mcp.switch:'.$id], $operation->requirement->flags);
                $this->assertSame($kind === 'list' ? IdempotencyKey::None : IdempotencyKey::HeaderAndArgument, $operation->safety->idempotencyKey);
                $this->assertSame(in_array($kind, ['update', 'revoke'], true), $operation->safety->expectedVersion);
                $input = $operation->input;
                $this->assertIsArray($input);
                $this->assertContains('client_id', $input['required']);
                $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties']['client_id']);
                if ($kind === 'list') {
                    $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], $input['properties']['limit']);
                    $this->assertSame(['type' => ['string', 'null'], 'maxLength' => 2048], $input['properties']['cursor']);
                } else {
                    $this->assertContains('idempotency_key', $input['required']);
                    $this->assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], $input['properties']['idempotency_key']);
                    $this->assertContains('confirm', $input['required']);
                }
                if (in_array($kind, ['update', 'revoke'], true)) {
                    $this->assertContains('user_id', $input['required']);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties']['user_id']);
                    $this->assertContains('expected_version', $input['required']);
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_every_portal_operation_documents_its_refusals_with_the_error_schema(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            foreach (['access', 'invitations'] as $family) {
                foreach (['list', $family === 'access' ? 'grant' : 'create', $family === 'access' ? 'update' : 'resend', 'revoke'] as $kind) {
                    $id = "clients.portal_{$family}.{$kind}";
                    $responses = $registry->find($id)?->rest?->responses ?? [];
                    $expected = $kind === 'list' ? [401, 403, 404, 422, 429] : [401, 403, 404, 409, 422, 429];
                    if ($family === 'invitations' && $kind !== 'list') {
                        $expected[] = 503;
                    }
                    $this->assertSame($expected, array_keys($responses), $id);
                    foreach ($responses as $status => $response) {
                        $this->assertNotSame('', $response['description'], "{$id} {$status}");
                        $this->assertSame(['$ref' => '#/components/schemas/Error'], $response['content']['application/json']['schema'], "{$id} {$status}");
                    }
                }
            }
            $this->assertStringContainsString('`invite`', $registry->find('clients.portal_access.grant')->rest->responses[422]['description']);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
