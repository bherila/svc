<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\AgentOAuthServer;
use BWH\Auth\Testing\AssertsAgentOAuthContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/**
 * The shared agent-API OAuth contract, checked against SVC's own routes (#408):
 * discovery, a self-registered client completing S256 PKCE without `resource`,
 * tokens bound to /api/v1, a protected route, and refresh.
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
