<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

final class AgentOAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSigningKeys();
    }

    public function test_full_code_refresh_rotation_connection_revoke_and_rejected_refresh_flow(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier);

        $exchange = $this->exchangeCode($clientId, $code, $verifier, OAuthResourceIndicator::agentApi())
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
        $this->assertStringContainsString('no-store', (string) $exchange->headers->get('Cache-Control'));
        $tokens = $exchange->json();
        $firstAccess = Passport::token()->newQuery()->where('client_id', $clientId)->sole();
        $this->assertSame(OAuthResourceIndicator::agentApi(), $firstAccess->resource_uri);
        $this->assertNotNull(Passport::client()->newQuery()->findOrFail($clientId)->last_used_at);

        $rotated = $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::agentApi())
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token'])
            ->json();
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::agentApi())
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');

        $newAccess = Passport::token()->newQuery()->where('client_id', $clientId)->where('id', '!=', $firstAccess->id)->sole();
        $revocation = $this->withToken($rotated['access_token'])
            ->deleteJson("/api/v1/connections/{$newAccess->id}")
            ->assertNoContent();
        $this->assertStringContainsString('no-store', (string) $revocation->headers->get('Cache-Control'));
        $this->assertTrue((bool) $newAccess->fresh()->revoked);

        $this->refresh($clientId, $rotated['refresh_token'], OAuthResourceIndicator::agentApi())
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $newAccess->id, 'revoked' => true]);
    }

    public function test_wrong_resource_revokes_authorization_code_or_refresh_family_without_leaking_resource(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier);
        $wrong = url('/api/other');

        $codeFailure = $this->exchangeCode($clientId, $code, $verifier, $wrong)
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');
        $this->assertStringNotContainsString($wrong, $codeFailure->getContent());
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $clientId, 'revoked' => true]);

        $verifier = $this->verifier();
        $validCode = $this->authorize($user, $clientId, $verifier);
        $tokens = $this->exchangeCode($clientId, $validCode, $verifier, OAuthResourceIndicator::agentApi())->assertOk()->json();
        $access = Passport::token()->newQuery()->where('client_id', $clientId)->where('revoked', false)->latest('created_at')->firstOrFail();
        $refreshFailure = $this->refresh($clientId, $tokens['refresh_token'], $wrong)
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');
        $this->assertStringNotContainsString($wrong, $refreshFailure->getContent());
        $this->assertTrue((bool) $access->fresh()->revoked);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $access->id, 'revoked' => true]);
    }

    private function registerClient(): string
    {
        return $this->postJson('/oauth/register', [
            'client_name' => 'Lifecycle client',
            'redirect_uris' => ['http://127.0.0.1:3210/callback'],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    private function authorize(User $user, string $clientId, string $verifier): string
    {
        $this->actingAs($user, 'web');
        $response = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'response_type' => 'code',
            'scope' => implode(' ', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]),
            'state' => 'oauth-test-state',
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::agentApi(),
        ], '', '&', PHP_QUERY_RFC3986));
        $response->assertOk()->assertSee('Connect Lifecycle client to SVC?');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $authToken = $this->app['session']->get('authToken');
        $this->assertIsString($authToken);

        $approval = $this->post('/oauth/authorize', ['auth_token' => $authToken])->assertRedirect();
        $location = $approval->headers->get('Location');
        $this->assertIsString($location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('oauth-test-state', $query['state'] ?? null);
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
    }

    private function exchangeCode(string $clientId, string $code, string $verifier, string $resource): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'code' => $code,
            'code_verifier' => $verifier,
            'resource' => $resource,
        ], ['Accept' => 'application/json']);
    }

    private function refresh(string $clientId, string $refreshToken, string $resource): TestResponse
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            'resource' => $resource,
        ], ['Accept' => 'application/json']);
    }

    private function verifier(): string
    {
        return $this->base64Url(random_bytes(48));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
