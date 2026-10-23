<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

/**
 * An MCP tool runs its REST route as an internal request. That request is
 * addressed to the host the caller reached, so a link the route returns is
 * the same link a REST client gets - not one on a bare `localhost`.
 */
final class AgentMcpInternalRequestHostTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_a_link_returned_over_mcp_is_the_one_rest_returns(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.project_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic host workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic host client', 'slug' => 'synthetic-'.Str::uuid()]);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::CLIENTS_READ, AgentApiScopes::PROJECTS_READ, AgentApiScopes::PROJECTS_WRITE]);

        $created = $this->callTool($this->initialize(), 'projects.create', [
            'workspace_id' => $workspace->public_id, 'company_id' => $company->public_id, 'name' => 'Synthetic host project',
            'expected_version' => AgentApiVersion::for($company), 'confirm' => true, 'idempotency_key' => 'synthetic-host-project',
        ]);
        $this->assertFalse($created['result']['isError'] ?? true, json_encode($created, JSON_THROW_ON_ERROR));
        $project = $created['result']['structuredContent']['data'];
        $read = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/projects/{$project['id']}")->assertOk()->json('data');

        $this->assertSame($read['web_url'], $project['web_url']);
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/workspaces/', $project['web_url']);
    }
}
