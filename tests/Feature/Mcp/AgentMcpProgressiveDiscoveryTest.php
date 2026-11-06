<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\AgentMcpToolsets;
use App\Support\AgentApi\AgentApiScopePresentation;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentMcpProgressiveDiscoveryTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.mcp_enabled' => true, 'agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
    }

    public function test_default_connect_exposes_only_authorized_core_and_advertises_notifications(): void
    {
        $this->manager(array_keys(AgentApiScopes::descriptions()));
        $message = $this->initializeMessage();
        $message['params']['capabilities'] = [];
        $response = $this->mcp($message)->assertOk()->assertJsonPath('result.capabilities.tools.listChanged', true);
        $session = $response->headers->get('Mcp-Session-Id');
        $core = $this->toolNames($session);
        sort($core);
        $expected = AgentMcpToolsets::CORE;
        sort($expected);
        $this->assertSame($expected, $core);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'invoices.list', 'arguments' => []]], $session)
            ->assertJsonPath('error.code', -32601);
    }

    public function test_all_toolsets_plus_core_equal_compatibility_discovery_for_every_scope_preset(): void
    {
        $presets = app(AgentApiScopePresentation::class)->presets();
        $scopes = [array_keys(AgentApiScopes::descriptions()), [AgentApiScopes::MCP_USE]];
        foreach ($presets as $preset) {
            $scopes[] = [AgentApiScopes::MCP_USE, ...$preset->scopes];
        }
        foreach ($scopes as $grant) {
            $this->app['cache']->flush();
            $this->app['auth']->forgetGuards();
            $this->manager($grant);
            $full = $this->toolNames($this->initialize());
            $session = $this->progressive();
            $result = $this->callTool($session, 'toolsets.list', []);
            $this->assertArrayHasKey('result', $result, json_encode($result));
            $sets = $result['result']['structuredContent']['data'];
            foreach ($sets as $set) {
                $this->assertGreaterThan(0, $set['tool_count']);
                $this->change($session, 'enable', $set['name']);
            }
            $actual = $this->toolNames($session);
            sort($full);
            sort($actual);
            $this->assertSame($full, $actual);
        }
    }

    public function test_enable_disable_round_trip_notifies_and_sessions_are_isolated(): void
    {
        $this->manager(array_keys(AgentApiScopes::descriptions()));
        $first = $this->progressive();
        $second = $this->progressive();
        $before = $this->toolNames($first);
        $this->change($first, 'enable', 'billing');
        $this->assertContains('invoices.list', $this->toolNames($first));
        $this->assertSame($before, $this->toolNames($second));
        $this->change($first, 'disable', 'billing');
        $this->assertSame($before, $this->toolNames($first));

        // A different bearer credential cannot resume the first credential's session.
        $this->app['auth']->forgetGuards();
        $this->manager([AgentApiScopes::MCP_USE]);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/list'], $first)->assertStatus(404);
        $limited = $this->progressive();
        $this->assertSame([], $this->callTool($limited, 'toolsets.list', [])['result']['structuredContent']['data']);
        $this->assertTrue($this->callTool($limited, 'toolsets.enable', ['name' => 'billing'])['result']['isError']);
        $this->assertNotContains('invoices.list', $this->toolNames($limited));
    }

    public function test_prompts_require_visible_tools_and_fetch_does_not_enable_toolsets(): void
    {
        $this->manager(array_keys(AgentApiScopes::descriptions()));
        $session = $this->progressive();
        $prompts = $this->mcp(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'prompts/list'], $session)->json('result.prompts');
        $this->assertContains('log-time-across-projects', array_column($prompts, 'name'));
        $this->assertNotContains('prepare-invoice-safely', array_column($prompts, 'name'));
        $this->mcp(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'prompts/get', 'params' => ['name' => 'prepare-invoice-safely']], $session)->assertJsonPath('error.code', -32002);
        $this->assertNotContains('invoices.list', $this->toolNames($session));
    }

    private function progressive(): string
    {
        $message = $this->initializeMessage();
        $message['params']['capabilities'] = [];

        return (string) $this->mcp($message)->assertOk()->headers->get('Mcp-Session-Id');
    }

    /** Notifications use the SDK's SSE response path, with the result in the same stream. */
    private function change(string $session, string $method, string $name): void
    {
        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => ['name' => 'toolsets.'.$method, 'arguments' => ['name' => $name]]], $session)->assertOk();
        $body = str_replace('\\/', '/', $response->streamedContent());
        $this->assertStringContainsString('notifications/tools/list_changed', $body);
        $this->assertStringNotContainsString('"isError":true', $body);
    }

    /** @param list<string> $scopes */
    private function manager(array $scopes): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Discovery', 'slug' => (string) Str::uuid()]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $this->actingAsMcp($user, $scopes);
    }
}
