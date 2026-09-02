<?php

namespace Tests\Feature\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Mcp\Context\McpAccountContextResolver;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpAccountContextResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_only_a_workspace_reachable_by_the_authenticated_principal(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Authorized', 'slug' => 'authorized']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);

        $resolved = app(McpAccountContextResolver::class)->resolve($this->contextFor($user), $workspace->public_id);

        $this->assertSame($workspace->id, $resolved->workspace?->id);
    }

    public function test_it_does_not_load_an_unreachable_workspace_before_rejecting_its_selector(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Other tenant', 'slug' => 'other-tenant']);

        $this->expectException(ModelNotFoundException::class);

        app(McpAccountContextResolver::class)->resolve($this->contextFor($user), $workspace->public_id);
    }

    private function contextFor(User $user): McpRequestContext
    {
        $principal = AgentPrincipal::query()->findOrFail($user->id);

        return new McpRequestContext(
            new McpPrincipal($principal, 'test-credential', 'test-client', ['mcp:use']),
            'mcp-account-context-test',
        );
    }
}
