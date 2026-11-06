<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Services\Mcp\Toolsets\McpToolVisibility;
use App\Support\AgentApi\AgentApiScopePresentation;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * Progressive tool discovery (docs/mcp.md, "Progressive tool discovery").
 *
 * A connection starts with the core tools and enables toolsets as it needs
 * them. Enabling reveals tools the connection could already call and grants
 * nothing. A client that cannot handle `list_changed` asks for every tool when
 * it connects. Every other MCP test connects that way (see TestCase::setUp),
 * so what the authorized surface contains stays pinned; these tests pin how
 * the default reveals it.
 */
final class AgentMcpProgressiveDiscoveryTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    private const array TOOLSET_TOOLS = ['toolsets.disable', 'toolsets.enable', 'toolsets.list'];

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $configuration = [
            'agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => [], 'agent_api.writes_enabled' => true,
            'services.square.access_token' => 'synthetic-square-token', 'services.square.workspace' => 'synthetic-square-workspace',
            'svc.portal_invitations.enabled' => true, 'task_organization.enabled' => true, 'task_planning_details.enabled' => true,
        ];
        foreach (['time_entry', 'invoice', 'expense', 'payment', 'client', 'collaboration', 'project', 'proposal', 'workspace', 'file'] as $group) {
            $configuration['agent_api.'.$group.'_writes_enabled'] = true;
        }
        config($configuration);
        $this->owner = User::factory()->create();
        $workspace = $this->workspace = Workspace::query()->create(['name' => 'Synthetic Progressive Workspace', 'slug' => 'synthetic-progressive']);
        $workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Progressive Client', 'slug' => 'synthetic-progressive-client']);
        ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Progressive Project']);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function scopePresets(): iterable
    {
        foreach ((new AgentApiScopePresentation)->presets() as $preset) {
            yield $preset->id => [[AgentApiScopes::MCP_USE, ...$preset->scopes]];
        }
        yield 'every scope' => [array_keys(AgentApiScopes::descriptions())];
        yield 'connection only' => [[AgentApiScopes::MCP_USE]];
    }

    /** @param list<string> $scopes */
    #[DataProvider('scopePresets')]
    public function test_the_core_set_plus_every_toolset_is_the_full_list(array $scopes): void
    {
        $this->actingAsMcp($this->owner, $scopes);
        $full = $this->toolNames($this->initialize());

        $session = $this->initializeProgressive();
        $core = $this->toolNames($session);
        // A connection holding no operation scope has nothing to enable: its core set is the toolset tools alone.
        $toolsets = $this->toolsets($session);

        // Nothing but the core set is listed until something is enabled, and
        // the core set is only ever a subset of what the connection may call.
        $this->assertEmpty(array_diff($core, $full));
        $this->assertEmpty(array_diff($core, McpToolVisibility::core(['projects.list', 'time_entries.log'])));
        // A toolset counts only tools discovery already lists for this
        // connection: together the toolsets hold exactly the full list less
        // the toolset tools themselves.
        $this->assertSame(
            count(array_diff($full, self::TOOLSET_TOOLS)),
            array_sum(array_column($toolsets, 'tool_count')),
        );

        $names = array_column($toolsets, 'name');
        if ($names !== []) {
            $this->enable($session, $names);
        }
        $this->assertEqualsCanonicalizing($full, $this->toolNames($session));
        $this->assertSame($this->listTools($this->initialize()), $this->listTools($session), 'Tools are the same definitions in either mode, in the same order.');
    }

    public function test_the_default_lists_only_the_core_set_and_advertises_list_changed(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));

        $response = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'progressive'])->assertOk();
        $this->assertTrue($response->json('result.capabilities.tools.listChanged'));
        $this->assertStringContainsString('toolsets.list', (string) $response->json('result.instructions'));

        $session = (string) $response->headers->get('Mcp-Session-Id');
        $expected = ['context.get', 'projects.list', 'search', 'time_entries.log', 'toolsets.disable', 'toolsets.enable', 'toolsets.list'];
        $listed = $this->toolNames($session);
        sort($listed);
        $this->assertSame($expected, $listed);
    }

    public function test_a_connection_that_opts_into_the_full_list_gets_it_and_no_list_changed(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));

        // The test case connects this way by default.
        $response = $this->mcp($this->initializeMessage())->assertOk();
        $this->assertNotTrue($response->json('result.capabilities.tools.listChanged'));
        $this->assertStringNotContainsString('toolsets.list', (string) $response->json('result.instructions'));
        $this->assertGreaterThan(190, count($this->toolNames((string) $response->headers->get('Mcp-Session-Id'))));

        // Only the exact value opts out; a mistyped one keeps the default.
        $session = (string) $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'everything'])->headers->get('Mcp-Session-Id');
        $this->assertLessThan(10, count($this->toolNames($session)));
        $session = (string) $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => ' ALL '])->headers->get('Mcp-Session-Id');
        $this->assertGreaterThan(190, count($this->toolNames($session)));
    }

    public function test_the_choice_is_made_when_connecting_and_a_later_request_cannot_change_it(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));
        $session = $this->initializeProgressive();

        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/list', 'params' => []], $session, [McpToolsetSelection::CONNECT_HEADER => 'all'])->assertOk();
        $this->assertCount(7, $response->json('result.tools'));
    }

    public function test_enable_and_disable_round_trip_and_send_list_changed(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));
        $session = $this->initializeProgressive();
        $before = $this->toolNames($session);
        $this->assertNotContains('invoices.list', $before);

        $enable = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['billing']]));
        $this->assertCount(2, $enable);
        $this->assertSame('notifications/tools/list_changed', $enable[0]['method']);
        $this->assertArrayNotHasKey('id', $enable[0]);
        $this->assertTrue($enable[1]['result']['structuredContent']['data']['changed']);
        $enabled = collect($enable[1]['result']['structuredContent']['data']['toolsets'])->keyBy('name');
        $this->assertTrue($enabled['billing']['enabled']);
        $this->assertFalse($enabled['projects']['enabled']);

        $after = $this->toolNames($session);
        $this->assertContains('invoices.list', $after);
        $this->assertContains('agreements.list', $after);
        $this->assertNotContains('clients.list', $after);
        $this->assertEmpty(array_diff($before, $after));

        $disable = $this->messages($this->callToolRaw($session, 'toolsets.disable', ['toolsets' => ['billing']]));
        $this->assertSame('notifications/tools/list_changed', $disable[0]['method']);
        $this->assertTrue($disable[1]['result']['structuredContent']['data']['changed']);
        $this->assertSame($before, $this->toolNames($session));
    }

    public function test_a_change_that_changes_nothing_sends_no_notification(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));
        $session = $this->initializeProgressive();

        $disable = $this->messages($this->callToolRaw($session, 'toolsets.disable', ['toolsets' => ['billing']]));
        $this->assertCount(1, $disable);
        $this->assertFalse($disable[0]['result']['structuredContent']['data']['changed']);

        $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['billing', 'Billing']]));
        $again = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['billing']]));
        $this->assertCount(1, $again);
        $this->assertFalse($again[0]['result']['structuredContent']['data']['changed']);
    }

    public function test_a_tool_outside_the_enabled_toolsets_is_not_found_until_its_toolset_is_enabled(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));
        $session = $this->initializeProgressive();

        $hidden = $this->callToolRaw($session, 'projects.members.list', ['workspace_id' => '00000000-0000-4000-8000-000000000000', 'project_id' => '00000000-0000-4000-8000-000000000000'])->assertOk();
        $this->assertSame(-32601, $hidden->json('error.code'));
        $unknown = $this->callToolRaw($session, 'no.such.tool', [])->assertOk();
        $this->assertSame($unknown->json('error.message'), str_replace('projects.members.list', 'no.such.tool', (string) $hidden->json('error.message')));

        $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['projects']]));
        $visible = $this->callToolRaw($session, 'projects.members.list', ['workspace_id' => '00000000-0000-4000-8000-000000000000', 'project_id' => '00000000-0000-4000-8000-000000000000'])->assertOk();
        // Found now; the call is authorized and validated like any other.
        $this->assertNotSame(-32601, $visible->json('error.code'));
    }

    public function test_a_toolset_the_connection_is_not_authorized_for_cannot_be_enabled(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ, AgentApiScopes::TASKS_READ]);
        $session = $this->initializeProgressive();

        $names = array_column($this->toolsets($session), 'name');
        $this->assertSame(['identity', 'projects', 'tasks'], $names);
        foreach (['billing', 'revenue', 'finance', 'square', 'payments', 'no_such_toolset'] as $toolset) {
            $result = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => [$toolset]]));
            $this->assertCount(1, $result);
            $this->assertTrue($result[0]['result']['isError'], $toolset);
            $this->assertStringContainsString('Unknown toolset: '.$toolset, $result[0]['result']['content'][0]['text']);
        }
        $this->assertNotContains('invoices.list', $this->toolNames($session));

        // One unknown name in a request changes nothing at all.
        $this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['projects', 'billing']])->assertOk();
        $this->assertSame(['identity' => false, 'projects' => false, 'tasks' => false], collect($this->toolsets($session))->pluck('enabled', 'name')->all());

        // And a toolset that is listed is not a grant: its tools are the
        // ones this credential's scopes already allowed, and no more.
        $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['projects', 'tasks']]));
        $this->assertNotContains('projects.create', $this->toolNames($session));
        $this->assertNotContains('tasks.create', $this->toolNames($session));
    }

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function resourceScopedConnections(): iterable
    {
        yield 'finance:read' => [AgentApiScopes::FINANCE_READ, 'finance', 'finance.payouts.list', []];
        yield 'time:read' => [AgentApiScopes::TIME_READ, 'time', 'time_entries.list', []];
    }

    /**
     * A connection holding `mcp:use` and one resource scope, and not
     * `identity:read`, must still reach the tools that scope allows. The
     * toolset tools are the only way to, so they may not require anything
     * beyond the connection scope.
     *
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('resourceScopedConnections')]
    public function test_a_connection_with_one_resource_scope_reaches_its_toolset(string $scope, string $toolset, string $tool, array $arguments): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, $scope]);
        $session = $this->initializeProgressive();

        // The core set is the toolset tools alone: context.get and search
        // require identity:read, the time-logging tools more scopes still.
        $core = $this->toolNames($session);
        sort($core);
        $this->assertSame(self::TOOLSET_TOOLS, $core);

        // Exactly the authorized toolset is listed, and nothing is enabled.
        $this->assertSame([$toolset], array_column($this->toolsets($session), 'name'));
        $this->assertSame([false], array_column($this->toolsets($session), 'enabled'));
        $this->assertNotContains($tool, $core);

        $enable = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => [$toolset]]));
        $this->assertCount(2, $enable);
        $this->assertSame('notifications/tools/list_changed', $enable[0]['method']);
        $this->assertTrue($enable[1]['result']['structuredContent']['data']['changed']);
        $this->assertContains($tool, $this->toolNames($session));

        $call = $this->callToolRaw($session, $tool, ['workspace_id' => $this->workspace->public_id, ...$arguments])->assertOk();
        $this->assertNull($call->json('error'), (string) $call->getContent());
        $this->assertNotTrue($call->json('result.isError'), (string) $call->getContent());

        // A toolset of a scope the connection lacks is refused as unknown.
        $other = $toolset === 'finance' ? 'time' : 'finance';
        $refused = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => [$other]]));
        $this->assertCount(1, $refused);
        $this->assertTrue($refused[0]['result']['isError']);
        $this->assertStringContainsString('Unknown toolset: '.$other, $refused[0]['result']['content'][0]['text']);
    }

    public function test_a_connection_with_two_unrelated_resource_scopes_sees_exactly_those_toolsets(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::FINANCE_READ, AgentApiScopes::TIME_READ]);
        $session = $this->initializeProgressive();

        $this->assertSame(['finance', 'time'], array_column($this->toolsets($session), 'name'));
        $this->enable($session, ['finance', 'time']);
        $listed = $this->toolNames($session);
        $this->assertContains('finance.payouts.list', $listed);
        $this->assertContains('time_entries.list', $listed);
        $this->assertNotContains('invoices.list', $listed);
    }

    public function test_the_toolset_tools_are_the_only_core_tools_every_connection_has(): void
    {
        // `mcp:use` alone: nothing else to enable, but the tools answer.
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE]);
        $session = $this->initializeProgressive();

        $listed = $this->toolNames($session);
        sort($listed);
        $this->assertSame(self::TOOLSET_TOOLS, $listed);
        $this->assertSame([], $this->toolsets($session));
        $result = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => ['finance']]));
        $this->assertTrue($result[0]['result']['isError']);
        $this->assertStringContainsString('Unknown toolset: finance', $result[0]['result']['content'][0]['text']);

        // Without `mcp:use` there is no connection at all.
        $this->actingAsMcp($this->owner, [AgentApiScopes::FINANCE_READ]);
        $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'progressive'])->assertForbidden();
    }

    public function test_sessions_of_two_credentials_are_isolated(): void
    {
        $scopes = array_keys(AgentApiScopes::descriptions());
        $this->actingAsMcp($this->owner, $scopes);
        $first = $this->initializeProgressive();
        $this->messages($this->callToolRaw($first, 'toolsets.enable', ['toolsets' => ['billing']]));
        $this->assertContains('invoices.list', $this->toolNames($first));

        // The same person with a second credential starts from the core set.
        $this->actingAsMcp($this->owner, $scopes);
        $second = $this->initializeProgressive();
        $this->assertNotContains('invoices.list', $this->toolNames($second));

        // A session id is not a credential: presenting the first connection's
        // under the second is a session that does not exist.
        $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $first)->assertNotFound();
        $this->messages($this->callToolRaw($second, 'toolsets.enable', ['toolsets' => ['time']]));
        $this->assertContains('time_entries.list', $this->toolNames($second));
        $this->assertNotContains('invoices.list', $this->toolNames($second));
    }

    public function test_fetching_a_prompt_enables_the_toolsets_of_its_tools(): void
    {
        $this->actingAsMcp($this->owner, array_keys(AgentApiScopes::descriptions()));
        $session = $this->initializeProgressive();
        $prompts = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/list', 'params' => []], $session)->assertOk()->json('result.prompts');
        $this->assertEqualsCanonicalizing(['log-time-across-projects', 'prepare-invoice-safely'], array_column($prompts, 'name'));

        // The time-logging prompt's tools are core: nothing to enable.
        $logTime = $this->messages($this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/get', 'params' => ['name' => 'log-time-across-projects']], $session));
        $this->assertCount(1, $logTime);
        $this->assertArrayHasKey('result', $logTime[0]);
        $this->assertSame([], array_filter(array_column($this->toolsets($session), 'enabled')));

        $invoice = $this->messages($this->mcp(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'prompts/get', 'params' => ['name' => 'prepare-invoice-safely']], $session));
        $this->assertCount(2, $invoice);
        $this->assertSame('notifications/tools/list_changed', $invoice[0]['method']);
        $this->assertArrayHasKey('result', $invoice[1]);
        $enabled = collect($this->toolsets($session))->where('enabled', true)->pluck('name')->sort()->values()->all();
        $this->assertSame(['billing', 'projects', 'time'], $enabled);
        $listed = $this->toolNames($session);
        foreach (['projects.get', 'time_entries.list', 'invoices.get', 'invoices.create_draft', 'invoices.update_draft'] as $tool) {
            $this->assertContains($tool, $listed);
        }

        // Fetching it again changes nothing, so nothing is sent.
        $again = $this->messages($this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'prompts/get', 'params' => ['name' => 'prepare-invoice-safely']], $session));
        $this->assertCount(1, $again);
    }

    public function test_a_prompt_the_connection_cannot_use_enables_nothing(): void
    {
        $this->actingAsMcp($this->owner, [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initializeProgressive();

        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'prompts/get', 'params' => ['name' => 'prepare-invoice-safely']], $session);
        $this->assertCount(1, $this->messages($response));
        $this->assertSame([], array_filter(array_column($this->toolsets($session), 'enabled')));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return TestResponse<Response>
     */
    private function callToolRaw(string $session, string $name, array $arguments): TestResponse
    {
        return $this->mcp(['jsonrpc' => '2.0', 'id' => 100, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => (object) $arguments]], $session);
    }

    /** @param list<string> $toolsets */
    private function enable(string $session, array $toolsets): void
    {
        $messages = $this->messages($this->callToolRaw($session, 'toolsets.enable', ['toolsets' => $toolsets]));
        $this->assertFalse($messages[array_key_last($messages)]['result']['isError'] ?? false);
    }

    /** @return list<array{name: string, description: string, tool_count: int, enabled: bool}> */
    private function toolsets(string $session): array
    {
        $messages = $this->messages($this->callToolRaw($session, 'toolsets.list', []));
        $this->assertCount(1, $messages);
        $this->assertFalse($messages[0]['result']['isError'] ?? false, json_encode($messages[0]));

        return $messages[0]['result']['structuredContent']['data']['toolsets'];
    }
}
