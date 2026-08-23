<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
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
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ]);

        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame(['context.get', 'operations.summary', 'projects.list', 'projects.get', 'tasks.list', 'tasks.get', 'time_entries.list', 'invoices.list', 'invoices.get'], array_column($tools, 'name'));
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
    }

    public function test_mcp_requires_connection_scope_but_allows_preflight(): void
    {
        $user = User::factory()->create();
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), [AgentApiScopes::PROJECTS_READ]);

        $this->mcp($this->initializeMessage())->assertForbidden();
        $this->options('/api/v1/mcp', ['Origin' => 'http://localhost', 'Access-Control-Request-Method' => 'POST'])->assertNoContent();
    }

    public function test_write_tools_are_absent_until_the_explicit_cutover_flag_is_enabled(): void
    {
        config(['agent_api.writes_enabled' => false]);
        $user = User::factory()->create();
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), [AgentApiScopes::MCP_USE]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->json('result.tools');

        $this->assertNotContains('time_entries.log', array_column($tools, 'name'));
    }

    public function test_write_catalog_is_conditionally_registered_after_cutover(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'MCP write workspace', 'slug' => 'mcp-write-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'MCP write client', 'slug' => 'mcp-write-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'MCP write project']);
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_WRITE]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->assertOk()->json('result.tools');
        $byName = collect($tools)->keyBy('name');

        $this->assertFalse($byName['time_entries.log']['annotations']['readOnlyHint']);
        $this->assertTrue($byName['time_entries.delete']['annotations']['destructiveHint']);
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
