<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Mcp\Registry\McpCapabilityDefinition;
use App\Services\Mcp\Registry\McpCapabilityKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requires_every_declared_scope_from_authenticated_context(): void
    {
        $user = User::factory()->create();
        $context = new McpRequestContext(new McpPrincipal(
            AgentPrincipal::query()->findOrFail($user->id),
            'credential',
            'client',
            ['mcp:use', 'projects:read'],
        ), 'request-id');

        $authorizer = app(McpAuthorizer::class);
        $this->assertTrue($authorizer->allowsScopes($context, ['mcp:use', 'projects:read']));
        $this->assertFalse($authorizer->allowsScopes($context, ['mcp:use', 'billing:read']));
    }

    public function test_it_hides_manager_only_capabilities_when_no_workspace_policy_can_succeed(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Contributor workspace', 'slug' => 'contributor-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
        $context = new McpRequestContext(new McpPrincipal(
            AgentPrincipal::query()->findOrFail($user->id),
            'credential',
            'client',
            ['billing:read'],
        ), 'request-id');

        $this->assertFalse(app(McpAuthorizer::class)->allowsDiscovery($context, $this->managerCapability()));

        WorkspaceMembership::query()->where('workspace_id', $workspace->id)->update(['role' => 'admin']);

        $this->assertTrue(app(McpAuthorizer::class)->allowsDiscovery($context, $this->managerCapability()));
    }

    private function managerCapability(): McpCapabilityDefinition
    {
        return new McpCapabilityDefinition(
            kind: McpCapabilityKind::Tool,
            name: 'test.manager_read',
            title: 'Manager read',
            description: 'Test-only manager capability.',
            handler: static fn (): array => [],
            inputSchema: ['type' => 'object'],
            outputSchema: ['type' => 'object'],
            requiredScopes: ['billing:read'],
            policyAbility: 'AgentAccess::isWorkspaceManager',
            requiresWorkspace: true,
            readOnly: true,
            idempotent: true,
            destructive: false,
            rateLimitBucket: 'mcp-read',
            auditClassification: 'agent_api.read',
            featureFlag: 'mcp.read.test',
        );
    }
}
