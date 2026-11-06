<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpToolsets;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\TestCase;

final class AgentMcpToolsetsTest extends TestCase
{
    public function test_a_new_catalog_scope_group_becomes_a_toolset_without_registration(): void
    {
        $toolsets = new AgentMcpToolsets([new Operation(
            id: 'synthetic_forecasting.list', title: 'Synthetic forecasts', description: 'Synthetic future scope.',
            effect: Effect::Read, requirement: new Requirement(scopes: ['synthetic_forecasting:read']), mcp: new McpBinding,
        )]);
        $this->assertSame(['synthetic_forecasting' => ['synthetic_forecasting.list']], $toolsets->groups());
        $session = new Session(new InMemorySessionStore);
        $this->assertFalse($toolsets->visible('synthetic_forecasting.list', $session));
        $session->set('svc_toolsets', ['synthetic_forecasting']);
        $this->assertTrue($toolsets->visible('synthetic_forecasting.list', $session));
        $this->assertFalse($toolsets->visible('unregistered.list', $session));
    }
}
