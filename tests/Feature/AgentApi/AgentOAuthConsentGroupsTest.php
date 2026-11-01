<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopePresentation;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/** The consent screen groups what an MCP client asks for and flags Admin scopes (#582). */
final class AgentOAuthConsentGroupsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $private = '';
        openssl_pkey_export($key, $private);
        config(['passport.private_key' => $private, 'passport.public_key' => openssl_pkey_get_details($key)['key']]);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
    }

    public function test_an_admin_scope_renders_the_admin_section_and_its_warning(): void
    {
        $response = $this->consent([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ, AgentApiScopes::BILLING_DELIVER]);

        $response->assertOk()
            ->assertSee('<h3>Admin</h3>', false)
            ->assertSee('class="scope-group sensitive"', false)
            ->assertSee(e(AgentApiScopePresentation::ADMIN_DESCRIPTION), false)
            ->assertSee('These permissions allow sensitive actions. Only approve them if you need them.')
            ->assertSee('<h3>Account</h3>', false)
            ->assertSee(e(AgentApiScopes::descriptions()[AgentApiScopes::BILLING_DELIVER]), false);
    }

    public function test_a_request_without_admin_scopes_shows_no_admin_section(): void
    {
        $this->consent([AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ])
            ->assertOk()
            ->assertSee('<h3>Account</h3>', false)
            ->assertDontSee('<h3>Admin</h3>', false)
            ->assertDontSee('These permissions allow sensitive actions');
    }

    /** @param list<string> $scopes */
    private function consent(array $scopes): TestResponse
    {
        $clientId = $this->postJson('/oauth/register', [
            'client_name' => 'Consent client',
            'redirect_uris' => ['http://127.0.0.1:3210/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => implode(' ', AgentApiScopes::mcp()),
        ])->assertCreated()->json('client_id');

        return $this->actingAs(User::factory()->create(), 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'consent-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', str_repeat('v', 48), true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource('mcp'),
        ], '', '&', PHP_QUERY_RFC3986));
    }
}
