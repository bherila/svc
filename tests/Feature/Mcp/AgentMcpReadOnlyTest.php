<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AgentMcpReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_exposes_only_the_fixed_read_catalog_and_uses_rest_authorization(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'MCP Workspace', 'slug' => 'mcp-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'MCP Client', 'slug' => 'mcp-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'MCP Project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'MCP Agreement',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 120,
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly',
            'next_run_on' => '2026-09-01',
            'due_days' => 30,
            'currency' => 'USD',
            'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'MCP service', 'quantity' => '1', 'unit_amount' => 10000]],
        ]);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::BILLING_READ,
        ]);

        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame(['context.get', 'operations.summary', 'projects.list', 'projects.get', 'tasks.list', 'tasks.get', 'time_entries.list', 'invoices.list', 'invoices.get', 'agreements.list', 'agreements.get', 'billing_schedules.list', 'billing_schedules.get'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['destructiveHint']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
            $this->assertFalse($tool['outputSchema']['additionalProperties']);
        }

        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'projects.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($response['isError']);
        $this->assertSame($project->public_id, $response['structuredContent']['data']['id']);

        $agreementResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'agreements.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($agreementResponse['isError']);
        $this->assertSame('MCP Agreement', $agreementResponse['structuredContent']['data']['title']);

        $scheduleResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'billing_schedules.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'schedule_id' => $schedule->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($scheduleResponse['isError']);
        $this->assertSame($agreement->public_id, $scheduleResponse['structuredContent']['data']['agreement_id']);
    }

    public function test_mcp_initialization_and_prompts_self_document_safe_workflows(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $initialization = $this->mcp($this->initializeMessage())->assertOk();
        $instructions = $initialization->json('result.instructions');
        $this->assertIsString($instructions);
        $this->assertStringContainsString('missing tools are not authorized', $instructions);
        $this->assertStringNotContainsString('time_entries.log', $instructions);
        $limitedSession = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($limitedSession);
        $limitedPrompts = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list', 'params' => [],
        ], $limitedSession)->assertOk()->json('result.prompts');
        $this->assertSame([], $limitedPrompts);

        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::BILLING_READ,
            AgentApiScopes::BILLING_WRITE,
        ]);
        $initialization = $this->mcp($this->initializeMessage())->assertOk();
        $instructions = $initialization->json('result.instructions');
        $this->assertIsString($instructions);
        $firstDecisionWindow = substr($instructions, 0, 512);
        $this->assertStringContainsString('First call context.get', $firstDecisionWindow);
        $this->assertStringContainsString('time_entries.log', $firstDecisionWindow);
        $this->assertStringContainsString('never approve time unless the user asks', $firstDecisionWindow);

        $session = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        $prompts = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'prompts/list',
            'params' => [],
        ], $session)->assertOk()->json('result.prompts');
        $this->assertSame(
            ['log-time-across-projects', 'prepare-invoice-safely'],
            array_column($prompts, 'name'),
        );

        $guide = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'prompts/get',
            'params' => ['name' => 'log-time-across-projects', 'arguments' => []],
        ], $session)->assertOk()->json('result.messages.0.content.text');
        $this->assertIsString($guide);
        $this->assertStringContainsString('context.get', $guide);
        $this->assertStringContainsString('stable, task-specific idempotency_key', $guide);
        $this->assertStringContainsString('Do not approve time', $guide);
    }

    public function test_mcp_requires_connection_scope_but_allows_preflight(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['http://localhost']]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::PROJECTS_READ]);

        $this->mcp($this->initializeMessage())->assertForbidden();
        $this->options('/api/v1/mcp', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type, mcp-protocol-version, mcp-session-id',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost')
            ->assertHeader('Access-Control-Allow-Methods', 'POST, DELETE, OPTIONS');
    }

    public function test_unapproved_browser_origin_gets_no_cors_authorization_and_is_rejected(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['https://approved.example']]);

        $this->options('/api/v1/mcp', [], [
            'Origin' => 'https://unapproved.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');

        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);
        $this->withHeader('Origin', 'https://unapproved.example')
            ->postJson('/api/v1/mcp', $this->initializeMessage(), ['Mcp-Protocol-Version' => '2025-06-18'])
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_approved_browser_origin_can_initialize_and_receives_cors_headers(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['https://approved.example']]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $this->withHeaders([
            'Origin' => 'https://approved.example',
            'Mcp-Protocol-Version' => '2025-06-18',
        ])->postJson('/api/v1/mcp', $this->initializeMessage())
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://approved.example')
            ->assertHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, Mcp-Protocol-Version');
    }

    public function test_originless_native_cli_request_remains_supported(): void
    {
        config(['agent_api.mcp_allowed_origins' => []]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $this->mcp($this->initializeMessage())->assertOk()->assertHeader('Mcp-Session-Id');
    }

    public function test_unauthenticated_mcp_request_returns_an_oauth_resource_challenge(): void
    {
        $this->mcp($this->initializeMessage())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', sprintf(
                'Bearer resource_metadata="%s"',
                url('/.well-known/oauth-protected-resource/api/v1/mcp'),
            ));
    }

    public function test_time_write_tools_are_absent_when_the_time_cutoff_is_disabled(): void
    {
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.time_entry_writes_enabled' => false,
        ]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TIME_WRITE,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->json('result.tools');

        $this->assertNotContains('time_entries.log', array_column($tools, 'name'));
    }

    public function test_time_entry_cutover_exposes_only_draft_time_mutations(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');

        $this->assertSame(
            ['time_entries.log', 'time_entries.update', 'time_entries.delete'],
            array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'time_entries.'))),
        );
        $this->assertNotContains('tasks.create', $names);
        $this->assertNotContains('time_entries.approve', $names);
        $this->assertNotContains('invoices.create_draft', $names);
    }

    public function test_tool_discovery_omits_operations_outside_the_current_token_scopes(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');

        $this->assertSame(['projects.list', 'projects.get'], array_column($tools, 'name'));
    }

    public function test_global_and_per_capability_kill_switches_remove_tools_from_discovery(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);

        config(['agent_api.mcp_feature_flags' => ['projects.list' => false]]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame(['projects.get'], array_column($tools, 'name'));

        config(['agent_api.mcp_enabled' => false]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame([], $tools);
    }

    public function test_write_catalog_is_conditionally_registered_after_cutover(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'MCP write workspace', 'slug' => 'mcp-write-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'MCP write client', 'slug' => 'mcp-write-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'MCP write project']);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
            AgentApiScopes::BILLING_DELIVER,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->assertOk()->json('result.tools');
        $byName = collect($tools)->keyBy('name');

        $this->assertFalse($byName['time_entries.log']['annotations']['readOnlyHint']);
        $this->assertTrue($byName['time_entries.delete']['annotations']['destructiveHint']);
        $this->assertTrue($byName['invoices.discard_draft']['annotations']['destructiveHint']);
        $this->assertFalse($byName['tasks.create']['inputSchema']['additionalProperties']);

        $result = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'time_entries.log', 'arguments' => ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'mcp-log-1', 'entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'MCP work']]]]], $session)->assertOk()->json('result');
        $this->assertFalse($result['isError']);
        $this->assertSame('MCP work', $result['structuredContent']['data'][0]['description']);
    }

    private function initialize(): string
    {
        $response = $this->mcp($this->initializeMessage())->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @return array<string, mixed> */
    private function initializeMessage(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']]];
    }

    /** @param array<string, mixed> $message */
    private function mcp(array $message, ?string $session = null): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }
}
