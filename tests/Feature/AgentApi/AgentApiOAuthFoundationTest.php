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

    public function test_protected_resource_metadata_uses_the_exact_mcp_browser_origin_policy(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['https://chatgpt.com']]);

        $this->withHeader('Origin', 'https://chatgpt.com')
            ->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://chatgpt.com')
            ->assertHeader('Vary', 'Origin');

        $this->withHeader('Origin', 'https://unapproved.example')
            ->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_public_client_registration_accepts_only_safe_redirects(): void
    {
        $scope = implode(' ', array_keys(AgentApiScopes::descriptions()));
        $this->postJson('/oauth/register', [
            'client_name' => 'SVC MCP Test',
            'redirect_uris' => ['http://127.0.0.1:3210/callback'],
            'grant_types' => ['refresh_token', 'authorization_code'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => $scope,
            'application_type' => 'native',
        ])->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->assertJsonPath('scope', $scope)->assertJsonPath('application_type', 'native')->assertJsonStructure(['client_id']);
        $this->assertDatabaseHas('oauth_clients', ['name' => 'SVC MCP Test', 'secret' => null]);
        $client = Passport::client()->newQuery()->where('name', 'SVC MCP Test')->firstOrFail();
        $this->assertNotNull($client->dynamically_registered_at);
        $this->assertSame(array_keys(AgentApiScopes::descriptions()), $client->scopes);

        $this->postJson('/oauth/register', [
            'client_name' => 'Unsafe Client',
            'redirect_uris' => ['http://example.test/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_client_metadata');
    }

    public function test_registration_rejects_unsupported_or_ambiguous_metadata(): void
    {
        $base = [
            'client_name' => 'Invalid metadata client',
            'redirect_uris' => ['http://localhost:3210/callback'],
            'token_endpoint_auth_method' => 'none',
        ];
        foreach ([
            ['grant_types' => ['authorization_code']],
            ['grant_types' => ['authorization_code', 'client_credentials']],
            ['response_types' => ['token']],
            ['token_endpoint_auth_method' => 'client_secret_basic'],
            ['client_name' => "Invalid\nname"],
            ['scope' => 'mcp:use unsupported:scope'],
            ['application_type' => 'web'],
        ] as $invalid) {
            $response = $this->postJson('/oauth/register', [...$base, ...$invalid])
                ->assertBadRequest()
                ->assertJsonPath('error', 'invalid_client_metadata');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('oauth_clients', 0);

        // RFC 7591 says unrecognized client metadata is ignored. Scope input is
        // normalized so harmless duplicates and whitespace do not break clients.
        $this->postJson('/oauth/register', [
            ...$base,
            'scope' => 'mcp:use  mcp:use identity:read',
            'software_id' => 'synthetic-harness',
        ])->assertCreated()->assertJsonPath('scope', 'mcp:use identity:read');
    }

    public function test_registration_persists_distinct_omitted_and_explicit_empty_scope_ceilings(): void
    {
        $omitted = $this->postJson('/oauth/register', [
            'client_name' => 'Catalog client',
            'redirect_uris' => ['https://client.example.test/catalog-callback'],
            'application_type' => 'web',
        ])->assertCreated();
        $this->assertSame(
            array_keys(AgentApiScopes::descriptions()),
            Passport::client()->newQuery()->findOrFail($omitted->json('client_id'))->scopes,
        );

        $empty = $this->postJson('/oauth/register', [
            'client_name' => 'No-scope client',
            'redirect_uris' => ['https://client.example.test/empty-callback'],
            'application_type' => 'web',
            'scope' => '',
        ])->assertCreated()->assertJsonPath('scope', '');
        $this->assertSame(
            [],
            Passport::client()->newQuery()->findOrFail($empty->json('client_id'))->scopes,
        );
    }
}
