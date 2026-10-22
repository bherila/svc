<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
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
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
