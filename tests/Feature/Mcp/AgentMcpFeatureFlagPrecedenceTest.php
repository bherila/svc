<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class AgentMcpFeatureFlagPrecedenceTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_upload_authorizations_obey_the_write_switch_and_are_not_read_only(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic upload workspace', 'slug' => 'synthetic-upload-workspace']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        config(['agent_api.writes_enabled' => true, 'agent_api.file_writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
        $this->actingAsMcp($owner, ['mcp:use', 'identity:read', 'files:write', 'expenses:read', 'expenses:write']);
        $uploads = ['attachments.upload_url', 'expenses.receipts.upload_url'];
        config(['agent_api.mcp_feature_flags' => ['mcp.read' => false, 'mcp.write' => true]]);
        $tools = collect($this->listTools($this->initialize()))->keyBy('name');
        foreach ($uploads as $name) {
            $this->assertArrayHasKey($name, $tools->all());
            $this->assertFalse($tools[$name]['annotations']['readOnlyHint'] ?? false);
            $this->assertNotContains('idempotency_key', $tools[$name]['inputSchema']['required'] ?? []);
        }
        config(['agent_api.mcp_feature_flags' => ['mcp.read' => true, 'mcp.write' => false]]);
        $session = $this->initialize();
        $names = $this->toolNames($session);
        foreach ($uploads as $name) {
            $this->assertNotContains($name, $names);
            $result = $this->callTool($session, $name, ['workspace_id' => $workspace->public_id]);
            $this->assertSame(-32601, $result['error']['code']);
        }
    }

    /** @return iterable<string, array{array<string, bool>, bool}> */
    public static function flags(): iterable
    {
        yield 'group enabled and tool disabled' => [['mcp.read' => true, 'projects.list' => false], false];
        yield 'group disabled and tool enabled' => [['mcp.read' => false, 'projects.list' => true], false];
        yield 'both disabled' => [['mcp.read' => false, 'projects.list' => false], false];
        yield 'both enabled' => [['mcp.read' => true, 'projects.list' => true], true];
        yield 'tool disabled alone' => [['projects.list' => false], false];
        yield 'tool enabled alone' => [['projects.list' => true], true];
        yield 'group disabled alone' => [['mcp.read' => false], false];
        yield 'group enabled alone' => [['mcp.read' => true], true];
        yield 'no explicit switches' => [[], true];
    }

    /** @param array<string, bool> $flags */
    #[DataProvider('flags')]
    public function test_either_kill_switch_withholds_discovery_dispatch_and_context(array $flags, bool $enabled): void
    {
        config(['agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => $flags]);
        $this->actingAsMcp(User::factory()->create(), ['mcp:use', 'identity:read', 'projects:read']);
        $session = $this->initialize();
        $this->assertSame($enabled, in_array('projects.list', $this->toolNames($session), true));
        $withheld = collect($this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools'))->keyBy('name');
        if ($enabled) {
            $this->assertArrayNotHasKey('projects.list', $withheld->all());
        } else {
            $this->assertSame(['name' => 'projects.list', 'reason' => 'deployment_disabled'], $withheld['projects.list']);
            $refused = $this->callTool($session, 'projects.list', ['workspace_id' => '00000000-0000-4000-8000-000000000001']);
            $this->assertSame(-32601, $refused['error']['code']);
            $this->assertArrayNotHasKey('result', $refused);
        }
        // context.get remains callable when its own read group is enabled.
        if (($flags['mcp.read'] ?? true) === true) {
            $context = $this->callTool($session, 'context.get', []);
            $this->assertSame($withheld->values()->all(), $context['result']['structuredContent']['data']['withheld_tools']);
        }
    }
}
