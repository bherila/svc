<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpToolsets;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\McpBinding;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Capabilities\Requirement;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
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
        $this->assertSame('Synthetic forecasting tools authorized for this connection.', $toolsets->list($this->context($session))['data'][0]['description']);
    }

    public function test_counts_exclude_core_and_keep_scope_groups_unique_sorted_and_authorized(): void
    {
        $toolsets = new AgentMcpToolsets([
            $this->tool('time_entries.log', ['time:write']),
            $this->tool('tasks.get', ['tasks:read', 'tasks:write']),
            $this->tool('projects.update', ['projects:read', 'projects:write', 'tasks:read']),
            $this->tool('square.invoices.list', ['billing:read'], ['mcp.switch:mcp.read.square']),
            $this->tool('only.connection', ['mcp:use']),
            new Operation(id: 'rest.only', title: 'REST', description: 'No MCP binding.', effect: Effect::Read, requirement: new Requirement(scopes: ['rest:read'])),
            $this->tool('time.update', ['time:write']),
        ]);
        $this->assertSame(['projects' => ['projects.update'], 'square' => ['square.invoices.list'], 'tasks' => ['tasks.get', 'projects.update'], 'time' => ['time.update']], $toolsets->groups());
        $session = new Session(new InMemorySessionStore);
        $context = $this->context($session);
        $this->assertSame(['data' => [
            ['name' => 'projects', 'description' => 'Projects tools authorized for this connection.', 'tool_count' => 1, 'enabled' => false],
            ['name' => 'square', 'description' => 'Square tools authorized for this connection.', 'tool_count' => 1, 'enabled' => false],
            ['name' => 'tasks', 'description' => 'Tasks tools authorized for this connection.', 'tool_count' => 2, 'enabled' => false],
            ['name' => 'time', 'description' => 'Time tools authorized for this connection.', 'tool_count' => 1, 'enabled' => false],
        ]], $toolsets->list($context));
        foreach (AgentMcpToolsets::CORE as $name) {
            $this->assertTrue($toolsets->visible($name, $session));
        }
        $session->set('svc_toolsets', ['projects']);
        $this->assertTrue($toolsets->visible('projects.update', $session));
        $this->assertFalse($toolsets->visible('tasks.get', $session));
        $this->assertFalse($toolsets->visible('projects.unregistered', $session));
        $this->assertFalse($toolsets->visible('only.connection', $session));
    }

    public function test_session_selection_round_trips_saves_and_emits_notifications_without_granting(): void
    {
        $store = new InMemorySessionStore;
        $session = new Session($store);
        $toolsets = new AgentMcpToolsets([$this->tool('billing.get', ['billing:read']), $this->tool('projects.get', ['projects:read'])]);
        $context = $this->context($session);
        $this->change($toolsets, 'enable', 'billing', $context);
        $this->assertTrue($toolsets->visible('billing.get', $session));
        $this->assertFalse($toolsets->visible('projects.get', $session));
        $this->assertSame(['billing'], (new Session($store, $session->getId()))->get('svc_toolsets'));
        $this->change($toolsets, 'enable', 'projects', $context);
        $this->assertSame(['billing', 'projects'], $session->get('svc_toolsets'));
        $this->change($toolsets, 'disable', 'billing', $context);
        $this->assertSame(['projects'], $session->get('svc_toolsets'));
        $this->assertFalse($toolsets->visible('billing.get', $session));
        $this->assertTrue($toolsets->visible('projects.get', $session));
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Toolset not found or not available.');
        $toolsets->enable('finance', $context);
    }

    public function test_compatibility_all_can_be_disabled_and_explicit_selections_override_it(): void
    {
        $session = new Session(new InMemorySessionStore);
        $session->set('client_capabilities', ['experimental' => ['svc/toolsets' => 'all']]);
        $toolsets = new AgentMcpToolsets([$this->tool('billing.get', ['billing:read']), $this->tool('tasks.get', ['tasks:read'])]);
        $this->assertTrue($toolsets->visible('billing.get', $session));
        $this->assertTrue($toolsets->visible('tasks.get', $session));
        $this->assertFalse($toolsets->visible('unregistered.get', $session));
        $this->change($toolsets, 'disable', 'billing', $this->context($session));
        $this->assertFalse($toolsets->visible('billing.get', $session));
        $this->assertTrue($toolsets->visible('tasks.get', $session));
        $session->set('svc_toolsets', []);
        $this->assertFalse($toolsets->visible('tasks.get', $session));
        $session->set('svc_toolsets', 'invalid');
        $this->assertFalse($toolsets->visible('tasks.get', $session));
        $this->assertSame(['data' => []], (new AgentMcpToolsets([]))->list($this->context($session)));
    }

    /** @param list<string> $scopes
     * @param list<string> $flags */
    private function tool(string $name, array $scopes, array $flags = []): Operation
    {
        return new Operation(id: $name, title: $name, description: 'Synthetic tool.', effect: Effect::Read,
            requirement: new Requirement(scopes: $scopes, flags: $flags), mcp: new McpBinding);
    }

    private function context(Session $session): RequestContext
    {
        return new RequestContext($session, CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'toolsets.list', 'arguments' => []],
        ]));
    }

    private function change(AgentMcpToolsets $toolsets, string $method, string $name, RequestContext $context): void
    {
        $fiber = new \Fiber(fn (): array => $toolsets->{$method}($name, $context));
        $notification = $fiber->start();
        $this->assertTrue($fiber->isSuspended());
        $this->assertSame('notification', $notification['type']);
        $this->assertSame('notifications/tools/list_changed', $notification['notification']->getMethod());
        $this->assertSame($context->getSession()->getId()->toRfc4122(), $notification['session_id']);
        $fiber->resume();
        $this->assertSame($toolsets->list($context), $fiber->getReturn());
    }
}
