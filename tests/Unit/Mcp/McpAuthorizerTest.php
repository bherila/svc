<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentOperationPolicy;
use App\Services\AgentApi\Operations\AgentOperationPrincipal;
use App\Services\AgentApi\Operations\AgentPrincipalResolver;
use App\Services\Mcp\Context\McpAuthorizer;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

    public function test_manager_only_operations_are_withheld_while_no_workspace_policy_can_succeed(): void
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
        $policy = app(AgentOperationPolicy::class);
        $manager = app(AgentOperationCatalog::class)->registry()->find('agreements.list');
        $ordinary = app(AgentOperationCatalog::class)->registry()->find('invoices.list');
        $this->assertNotNull($manager);
        $this->assertNotNull($ordinary);
        $principal = fn (): AgentOperationPrincipal => new AgentOperationPrincipal(
            static fn (string $scope): bool => $context->principal->hasScope($scope),
            fn (): bool => app(McpAuthorizer::class)->hasManagedWorkspace($context),
        );

        $this->assertSame(AgentOperationPolicy::WORKSPACE_MANAGER, $policy->withhold($principal(), $manager));
        $this->assertNull($policy->withhold($principal(), $ordinary));

        WorkspaceMembership::query()->where('workspace_id', $workspace->id)->update(['role' => 'admin']);

        $this->assertNull($policy->withhold($principal(), $manager));
    }

    /** The manager lookup is only paid for when an operation asks for it, and at most once. */
    public function test_the_manager_fact_is_evaluated_lazily_and_once(): void
    {
        $calls = 0;
        $principal = new AgentOperationPrincipal(static fn (string $scope): bool => true, function () use (&$calls): bool {
            $calls++;

            return true;
        });
        $this->assertSame(0, $calls);
        $this->assertTrue($principal->managesWorkspace());
        $this->assertTrue($principal->managesWorkspace());
        $this->assertSame(1, $calls);
        $this->assertTrue($principal->isAuthenticated());
        $this->assertFalse(AgentOperationPrincipal::anonymous()->managesWorkspace());
        $this->assertFalse(AgentOperationPrincipal::anonymous()->hasScope('identity:read'));
        $this->assertFalse(AgentOperationPrincipal::anonymous()->isAuthenticated());
        $this->assertFalse($principal->can('anything'));
        $this->assertTrue($principal->allowsGroup(null));
        $this->assertFalse($principal->allowsGroup('module'));
    }

    /** The REST gate reads the access token the API guard authenticated, and nothing without one. */
    public function test_the_rest_principal_comes_from_the_authenticated_access_token(): void
    {
        $resolver = new AgentPrincipalResolver;
        $anonymous = $resolver->principal(Request::create('/api/v1/context'));
        $this->assertInstanceOf(AgentOperationPrincipal::class, $anonymous);
        $this->assertFalse($anonymous->isAuthenticated());
        $this->assertFalse($anonymous->hasScope('identity:read'));

        $user = User::factory()->create();
        $this->actingAsMcp($user, ['identity:read']);
        $request = Request::create('/api/v1/context');
        $request->setUserResolver(static fn () => app('auth')->guard('api')->user());
        $principal = $resolver->principal($request);
        $this->assertInstanceOf(AgentOperationPrincipal::class, $principal);
        $this->assertTrue($principal->isAuthenticated());
        $this->assertTrue($principal->hasScope('identity:read'));
        $this->assertFalse($principal->hasScope('billing:read'));
        $this->assertFalse($principal->managesWorkspace(), 'The REST gate never applies the discovery-only manager rule');
    }

    public function test_the_catalog_knows_manager_only_operations_before_anything_else_builds_it(): void
    {
        $catalog = new AgentOperationCatalog;
        $this->assertTrue($catalog->isManagerOnly('agreements.list'));
        $this->assertFalse((new AgentOperationCatalog)->isManagerOnly('invoices.list'));
    }
}
