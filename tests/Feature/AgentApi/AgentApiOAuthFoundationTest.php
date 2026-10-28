<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // One document per endpoint, each naming exactly that endpoint and the
        // scopes it admits (RFC 9728): `mcp:use` only on MCP's.
        $rest = $this->getJson('/.well-known/oauth-protected-resource/api/v1')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1'))
            ->assertJsonPath('authorization_servers', [url('/')])
            ->assertJsonPath('service_documentation', route('openapi.document'));
        $this->assertSame(AgentApiScopes::rest(), $rest->json('scopes_supported'));
        $this->assertNotContains(AgentApiScopes::MCP_USE, $rest->json('scopes_supported'));
        $mcp = $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertJsonPath('resource', url('/api/v1/mcp'))
            ->assertJsonPath('service_documentation', route('openapi.document'));
        $this->assertSame(AgentApiScopes::mcp(), $mcp->json('scopes_supported'));
        // No document answers for a resource that is not configured, the bare
        // well-known path included: its `resource` would name no endpoint.
        $this->getJson('/.well-known/oauth-protected-resource')->assertNotFound();
        $this->getJson('/.well-known/oauth-protected-resource/api/v2')->assertNotFound();
    }

    /**
     * Browser agents reach discovery, registration and the token endpoint
     * through the package's CORS list; an unlisted origin is answered without
     * CORS headers rather than refused, so non-browser clients never notice.
     * The MCP endpoint keeps its own, separate origin policy.
     */
    public function test_oauth_machine_endpoints_give_cors_only_to_listed_browser_origins(): void
    {
        config(['agent_api.mcp_allowed_origins' => []]);

        foreach (['https://claude.ai', 'https://chatgpt.com'] as $origin) {
            foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource/api/v1', '/.well-known/oauth-protected-resource/api/v1/mcp'] as $path) {
                $this->withHeader('Origin', $origin)->getJson($path)
                    ->assertOk()
                    ->assertHeader('Access-Control-Allow-Origin', $origin)
                    ->assertHeaderMissing('Access-Control-Allow-Credentials');
            }
            foreach (['/oauth/register', '/oauth/token'] as $path) {
                $this->withHeaders(['Origin' => $origin, 'Access-Control-Request-Method' => 'POST'])
                    ->options($path)
                    ->assertNoContent()
                    ->assertHeader('Access-Control-Allow-Origin', $origin);
            }
        }

        $unlisted = $this->withHeader('Origin', 'https://unapproved.example.test')
            ->getJson('/.well-known/oauth-protected-resource/api/v1/mcp')
            ->assertOk()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->assertStringContainsString('Origin', (string) $unlisted->headers->get('Vary'));

        // Discovery being open to a browser origin does not open the endpoint.
        $this->withHeaders(['Origin' => 'https://claude.ai', 'Mcp-Protocol-Version' => '2025-06-18'])
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
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
            ['token_endpoint_auth_method' => 'private_key_jwt'],
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

        // Auth package 0.24.4: a requested secret method still registers a
        // public client, with no secret, answered as `none`.
        foreach (['client_secret_post', 'client_secret_basic'] as $method) {
            $this->postJson('/oauth/register', [...$base, 'token_endpoint_auth_method' => $method])
                ->assertCreated()
                ->assertJsonPath('token_endpoint_auth_method', 'none')
                ->assertJsonMissingPath('client_secret');
        }
        $this->assertSame(2, DB::table('oauth_clients')->whereNull('secret')->count());
        DB::table('oauth_clients')->delete();

        // RFC 7591 says unrecognized client metadata is ignored. Scope input is
        // normalized so harmless duplicates and whitespace do not break clients.
        $this->postJson('/oauth/register', [
            ...$base,
            'scope' => 'mcp:use  mcp:use identity:read',
            'software_id' => 'synthetic-harness',
        ])->assertCreated()->assertJsonPath('scope', 'mcp:use identity:read');
    }

    public function test_requested_secret_authentication_methods_are_normalized_to_public_clients(): void
    {
        foreach (['client_secret_basic', 'client_secret_post'] as $method) {
            $response = $this->postJson('/oauth/register', [
                'client_name' => 'Synthetic public '.$method,
                'redirect_uris' => ['http://localhost:3210/callback'],
                'token_endpoint_auth_method' => $method,
            ])->assertCreated()
                ->assertJsonPath('token_endpoint_auth_method', 'none')
                ->assertJsonMissingPath('client_secret');

            $client = Passport::client()->newQuery()->findOrFail($response->json('client_id'));
            $this->assertNull($client->secret);
            $this->assertSame(array_keys(AgentApiScopes::descriptions()), $client->scopes);
        }
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
