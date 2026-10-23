<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\AgentOAuthServer;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\Testing\AssertsAgentOAuthContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/**
 * The shared agent-API OAuth contract, checked against SVC's own routes (#408):
 * discovery, a self-registered client completing S256 PKCE without `resource`,
 * tokens bound to /api/v1, a protected route, and refresh; and each endpoint's
 * challenge leading to its own protected-resource document.
 */
final class AgentOAuthContractTest extends TestCase
{
    use AssertsAgentOAuthContract;
    use RefreshDatabase;

    public function test_svc_runs_the_agent_oauth_preset(): void
    {
        $this->assertTrue(AgentOAuthServer::active());
        $this->assertSame(rtrim((string) config('app.url'), '/').'/api/v1', config('bherila-auth.oauth_server.resource'));
    }

    public function test_discovery_follows_the_agent_contract(): void
    {
        $this->assertAgentOAuthDiscovery();
    }

    public function test_a_self_registered_client_completes_the_agent_lifecycle(): void
    {
        $this->configureSigningKeys();
        $user = User::factory()->create(['email' => 'contract-'.Str::random(8).'@synthetic.test']);

        $this->assertAgentOAuthLifecycle($user, AgentApiScopes::IDENTITY_READ, '/api/v1/context');

        // The client named no resource, so every token it holds is a REST token.
        $this->assertSame(
            [OAuthResourceIndicator::resource('rest')],
            Passport::token()->newQuery()->where('user_id', $user->id)->pluck('resource_uri')->unique()->values()->all(),
        );
    }

    /**
     * Each endpoint's 401 names its own resource's metadata, which names that
     * resource exactly, as a strict client following the challenge checks.
     */
    public function test_each_endpoint_challenges_with_its_own_protected_resource(): void
    {
        $rest = OAuthResourceIndicator::resource('rest');
        $mcp = OAuthResourceIndicator::resource('mcp');

        $this->assertSame(url('/api/v1'), $rest);
        $this->assertSame(url('/api/v1/mcp'), $mcp);
        $this->assertProtectedResourceChallenge('GET', '/api/v1/context', $rest);
        $this->assertProtectedResourceChallenge('POST', '/api/v1/workspaces', $rest);
        $this->assertProtectedResourceChallenge('POST', '/api/v1/mcp', $mcp);
        $this->assertProtectedResourceChallenge('DELETE', '/api/v1/connections/'.Str::random(40), $mcp);
    }

    /** A route under /api/v1 that is not an OAuth resource keeps its plain 401. */
    /**
     * The finance reconciliation routes were the one place under /api/v1 that
     * named no protected resource and so kept the application's own 401. As
     * registry operations that also admit REST OAuth tokens (#457), they name
     * the REST resource and challenge as it does; the status is unchanged.
     */
    public function test_the_finance_routes_challenge_as_the_rest_resource(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Challenge workspace', 'slug' => 'challenge-workspace']);

        $response = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments")
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_token');
        $this->assertStringContainsString(
            sprintf('resource_metadata="%s"', url('/.well-known/oauth-protected-resource/api/v1')),
            (string) $response->headers->get('WWW-Authenticate'),
        );
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function configureSigningKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $private, 'passport.public_key' => $details['key']]);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
    }
}
