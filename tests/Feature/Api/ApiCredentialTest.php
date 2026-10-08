<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\ApiCredentialController;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

/**
 * Credentials for apps that use the REST API without MCP (#384).
 *
 * A connector that asks for "an API key" gets a personal token; one that runs
 * the OAuth flow gets a registered app, public or confidential. Each must reach
 * `/api/v1` with exactly the permissions chosen and the person's own role, be
 * bound to that resource, be shown once, and be revocable - and nothing about
 * it may be reachable by another person or minted by an OAuth credential.
 */
final class ApiCredentialTest extends TestCase
{
    use RefreshDatabase;

    private const string REDIRECT = 'http://127.0.0.1:3210/callback';

    private User $user;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSigningKeys();
        $this->user = User::factory()->create(['email' => 'person-'.Str::random(8).'@synthetic.test']);
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Credentials', 'slug' => 'synthetic-credentials-'.Str::random(8)]);
        $this->workspace->memberships()->create(['user_id' => $this->user->id, 'role' => 'owner']);
    }

    public function test_a_personal_token_reaches_the_api_with_only_its_scopes_and_stops_when_revoked(): void
    {
        $token = $this->issueToken(['identity:read'], 90);

        $row = Passport::token()->newQuery()->where('user_id', $this->user->id)->sole();
        $this->assertSame(OAuthResourceIndicator::resource(), $row->resource_uri);
        $this->assertSame(['identity:read'], array_values((array) $row->scopes));
        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $row->expires_at?->getTimestamp(), 60);

        $this->withToken($token)->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('data.workspaces.0.id', $this->workspace->public_id);
        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->workspace->public_id}/projects")->assertForbidden();
        // A REST credential is never an MCP connection.
        $this->withToken($token)->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertForbidden();

        $listed = $this->setupProps()['rest']['tokens'];
        $this->assertCount(1, $listed);
        $this->assertSame('Synthetic connector', $listed[0]['name']);
        $this->assertArrayNotHasKey('token', $listed[0]);

        $this->actingAs($this->user)->delete($listed[0]['revoke_href'])->assertRedirect();
        $this->assertTrue((bool) $row->fresh()->revoked);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_the_secret_is_shown_once_on_the_page_that_follows_creation(): void
    {
        $this->actingAs($this->user)
            ->from("/workspaces/{$this->workspace->public_id}/mcp")
            ->post('/account/api-tokens', ['name' => 'Once', 'scopes' => ['identity:read'], 'days' => 30])
            ->assertRedirect("/workspaces/{$this->workspace->public_id}/mcp");

        $first = $this->setupProps()['rest']['issued'];
        $this->assertSame('token', $first['kind']);
        $this->assertIsString($first['token']);
        $this->assertNull($this->setupProps()['rest']['issued'], 'The secret is not shown a second time');
    }

    public function test_tokens_are_refused_outside_the_offered_permissions_and_lifetimes(): void
    {
        $this->actingAs($this->user);
        $this->post('/account/api-tokens', ['name' => 'MCP', 'scopes' => [AgentApiScopes::MCP_USE], 'days' => 30])->assertSessionHasErrors('scopes.0');
        $this->post('/account/api-tokens', ['name' => 'Unknown', 'scopes' => ['admin:everything'], 'days' => 30])->assertSessionHasErrors('scopes.0');
        $this->post('/account/api-tokens', ['name' => 'Forever', 'scopes' => ['identity:read'], 'days' => 3650])->assertSessionHasErrors('days');
        $this->post('/account/api-tokens', ['name' => 'Empty', 'scopes' => [], 'days' => 30])->assertSessionHasErrors('scopes');
        $this->assertSame(0, Passport::token()->newQuery()->count());
    }

    public function test_another_person_cannot_revoke_or_see_a_token(): void
    {
        $this->issueToken(['identity:read'], 30);
        $row = Passport::token()->newQuery()->where('user_id', $this->user->id)->sole();
        $other = User::factory()->create(['email' => 'other-'.Str::random(8).'@synthetic.test']);

        $this->actingAs($other)->delete("/account/api-tokens/{$row->getKey()}")->assertNotFound();
        $this->assertFalse((bool) $row->fresh()->revoked);
    }

    /**
     * The flow a generic connector runs: a registered confidential app,
     * authorization code with PKCE, a client secret at the token endpoint, and
     * no `resource` parameter anywhere - yet the token reaches /api/v1.
     */
    public function test_a_confidential_app_completes_the_code_flow_without_a_resource_parameter(): void
    {
        $this->actingAs($this->user)
            ->post('/account/oauth-apps', [
                'name' => 'Synthetic connector app',
                'redirect_uris' => [self::REDIRECT],
                'confidential' => true,
                'scopes' => ['identity:read', 'projects:read'],
            ])->assertRedirect();
        $issued = session(ApiCredentialController::FLASH);
        $this->assertSame('app', $issued['kind']);
        $this->assertIsString($issued['client_secret']);
        $clientId = $issued['client_id'];

        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => 'identity:read',
            'state' => 'synthetic-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986))->assertOk();
        $approval = $this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $issued['client_secret'],
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->assertSame(OAuthResourceIndicator::resource(), OAuthResourceIndicator::tokenClaims($tokens['access_token'])['resource'] ?? null);

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/context')->assertOk();

        // A wrong secret is refused.
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => 'not-the-secret',
            'refresh_token' => $tokens['refresh_token'],
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        // Deleting the app revokes what it was issued.
        $app = $this->setupProps()['rest']['apps'][0];
        $this->assertSame($clientId, $app['id']);
        $this->assertTrue($app['confidential']);
        $this->actingAs($this->user)->delete($app['delete_href'])->assertRedirect();
        $this->assertSame(0, Passport::token()->newQuery()->where('client_id', $clientId)->where('revoked', false)->count());
        $this->assertTrue((bool) Passport::client()->newQuery()->findOrFail($clientId)->revoked);
    }

    public function test_app_registration_refuses_unsafe_redirects_and_unoffered_permissions(): void
    {
        $this->actingAs($this->user);
        foreach (['http://app.example.test/cb', 'https://app.example.test/cb#frag', 'https://user:pass@app.example.test/cb', 'javascript:alert(1)'] as $uri) {
            $this->post('/account/oauth-apps', [
                'name' => 'Unsafe', 'redirect_uris' => [$uri], 'confidential' => false, 'scopes' => ['identity:read'],
            ])->assertSessionHasErrors('credential');
        }
        $this->post('/account/oauth-apps', [
            'name' => 'MCP', 'redirect_uris' => [self::REDIRECT], 'confidential' => false, 'scopes' => [AgentApiScopes::MCP_USE],
        ])->assertSessionHasErrors('scopes.0');
        $this->assertSame(0, Passport::client()->newQuery()->where('name', 'Unsafe')->count());

        $this->post('/account/oauth-apps', [
            'name' => 'Loopback', 'redirect_uris' => ['http://localhost:8080/cb', 'https://app.example.test/cb'], 'confidential' => false, 'scopes' => ['identity:read'],
        ])->assertSessionHasNoErrors();
        $this->assertNull(session(ApiCredentialController::FLASH)['client_secret'], 'A public app has no secret');
    }

    public function test_another_person_cannot_delete_an_app(): void
    {
        $this->actingAs($this->user)->post('/account/oauth-apps', [
            'name' => 'Mine', 'redirect_uris' => [self::REDIRECT], 'confidential' => false, 'scopes' => ['identity:read'],
        ]);
        $clientId = session(ApiCredentialController::FLASH)['client_id'];
        $other = User::factory()->create(['email' => 'other-'.Str::random(8).'@synthetic.test']);

        $this->actingAs($other)->delete("/account/oauth-apps/{$clientId}")->assertNotFound();
        $this->assertFalse((bool) Passport::client()->newQuery()->findOrFail($clientId)->revoked);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function issueToken(array $scopes, int $days): string
    {
        $this->actingAs($this->user)
            ->post('/account/api-tokens', ['name' => 'Synthetic connector', 'scopes' => $scopes, 'days' => $days])
            ->assertSessionHasNoErrors();
        $issued = session(ApiCredentialController::FLASH);
        $this->assertIsString($issued['token'] ?? null);
        $this->app['auth']->forgetGuards();

        return $issued['token'];
    }

    /** @return array<string, mixed> */
    private function setupProps(): array
    {
        return $this->actingAs($this->user)
            ->get("/workspaces/{$this->workspace->public_id}/mcp")
            ->assertOk()
            ->viewData('page')['props'];
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
