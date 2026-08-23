<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class AgentApiOAuthFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_passport_principal_resolves_the_same_workspace_membership_as_the_browser_user(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'OAuth Workspace', 'slug' => 'oauth-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
        $principal = AgentPrincipal::query()->findOrFail($user->id);

        Passport::actingAs($principal, [AgentApiScopes::IDENTITY_READ]);

        $this->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id)
            ->assertJsonPath('data.workspaces.0.id', $workspace->public_id);
    }

    public function test_metadata_advertises_pkce_and_canonical_agent_resource(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('code_challenge_methods_supported.0', 'S256');
        $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1'));
    }

    public function test_public_client_registration_accepts_only_safe_redirects(): void
    {
        $this->postJson('/oauth/register', [
            'client_name' => 'SVC MCP Test',
            'redirect_uris' => ['http://127.0.0.1:3210/callback'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->assertJsonStructure(['client_id']);

        $this->postJson('/oauth/register', [
            'client_name' => 'Unsafe Client',
            'redirect_uris' => ['http://example.test/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
    }
}
