<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\Http\Middleware\EnforceOAuthPkce;
use BWH\Auth\Http\Middleware\EnforceOAuthResourceIndicator;
use BWH\Auth\Http\Middleware\EnsureOAuthServerEnabled;
use BWH\Auth\Http\Middleware\ExpectOAuthResource;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ResourceAccessTokenRepository;
use BWH\Auth\OAuth\Server\ResourceAuthCodeRepository;
use BWH\Auth\OAuth\Server\ResourceClient;
use BWH\Auth\OAuth\Server\ResourceRefreshTokenRepository;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Tests\TestCase;

final class AgentOAuthLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const string MCP = 'mcp';

    private const string REST = 'rest';

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

        $exchange = $this->exchangeCode($clientId, $code, $verifier, OAuthResourceIndicator::resource(self::MCP))
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token', 'expires_in']);
        $this->assertStringContainsString('no-store', (string) $exchange->headers->get('Cache-Control'));
        $tokens = $exchange->json();
        $firstAccess = Passport::token()->newQuery()->where('client_id', $clientId)->sole();
        $this->assertSame(OAuthResourceIndicator::resource(self::MCP), $firstAccess->resource_uri);
        $claims = OAuthResourceIndicator::tokenClaims($tokens['access_token']);
        $this->assertSame(config('bherila-auth.oauth_server.issuer'), $claims['iss'] ?? null);
        $this->assertSame(OAuthResourceIndicator::resource(self::MCP), $claims['resource'] ?? null);
        $this->assertContains(OAuthResourceIndicator::resource(self::MCP), $claims['aud'] ?? []);
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'access_token_id' => $firstAccess->id,
            'resource_uri' => OAuthResourceIndicator::resource(self::MCP),
        ]);
        $this->assertNotNull(Passport::client()->newQuery()->findOrFail($clientId)->last_used_at);

        $rotated = $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::resource(self::MCP))
            ->assertOk()
            ->assertJsonStructure(['access_token', 'refresh_token'])
            ->json();
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::resource(self::MCP))
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');

        $newAccess = Passport::token()->newQuery()->where('client_id', $clientId)->where('id', '!=', $firstAccess->id)->sole();
        $revocation = $this->withToken($rotated['access_token'])
            ->deleteJson("/api/v1/connections/{$newAccess->id}")
            ->assertNoContent();
        $this->assertStringContainsString('no-store', (string) $revocation->headers->get('Cache-Control'));
        $this->assertTrue((bool) $newAccess->fresh()->revoked);

        $this->refresh($clientId, $rotated['refresh_token'], OAuthResourceIndicator::resource(self::MCP))
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $newAccess->id, 'revoked' => true]);
    }

    public function test_wrong_resource_is_rejected_without_consuming_a_retryable_code_or_refresh_token(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier);
        $wrong = url('/api/other');

        $codeFailure = $this->exchangeCode($clientId, $code, $verifier, $wrong)
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_target');
        $this->assertStringNotContainsString($wrong, $codeFailure->getContent());
        $this->assertDatabaseHas('oauth_auth_codes', ['client_id' => $clientId, 'revoked' => false]);

        $tokens = $this->exchangeCode($clientId, $code, $verifier, OAuthResourceIndicator::resource(self::MCP))->assertOk()->json();
        $access = Passport::token()->newQuery()->where('client_id', $clientId)->where('revoked', false)->latest('created_at')->firstOrFail();
        $refreshFailure = $this->refresh($clientId, $tokens['refresh_token'], $wrong)
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_target');
        $this->assertStringNotContainsString($wrong, $refreshFailure->getContent());
        $this->assertFalse((bool) $access->fresh()->revoked);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['access_token_id' => $access->id, 'revoked' => false]);
        $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::resource(self::MCP))->assertOk();
    }

    public function test_v011_package_owns_the_resource_repositories_schema_and_middleware_order(): void
    {
        $this->assertInstanceOf(ResourceAccessTokenRepository::class, app(PassportAccessTokenRepository::class));
        $this->assertInstanceOf(ResourceAuthCodeRepository::class, app(PassportAuthCodeRepository::class));
        $this->assertInstanceOf(ResourceRefreshTokenRepository::class, app(PassportRefreshTokenRepository::class));
        $this->assertSame(ResourceClient::class, Passport::clientModel());
        $this->assertTrue(Schema::hasColumns('oauth_clients', [
            'dynamically_registered_at',
            'last_used_at',
            'scopes',
        ]));
        $this->assertTrue(Schema::hasColumn('oauth_auth_codes', 'resource_uri'));
        $this->assertTrue(Schema::hasColumn('oauth_access_tokens', 'resource_uri'));
        $this->assertTrue(Schema::hasColumn('oauth_refresh_tokens', 'resource_uri'));
        $migration = require database_path('migrations/2026_09_02_000000_add_oauth_server_metadata.php');
        $migration->up();
        $migration->up();

        foreach (['passport.authorizations.authorize', 'passport.authorizations.approve', 'passport.token'] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = app('router')->gatherRouteMiddleware($route);
            $enabled = array_search(EnsureOAuthServerEnabled::class, $middleware, true);
            $pkce = array_search(EnforceOAuthPkce::class, $middleware, true);
            $resource = array_search(EnforceOAuthResourceIndicator::class, $middleware, true);
            $this->assertIsInt($enabled);
            $this->assertIsInt($pkce);
            $this->assertIsInt($resource);
            $this->assertLessThan($pkce, $enabled);
            $this->assertLessThan($resource, $pkce);
        }

        foreach ([
            'agent-api.v1.mcp' => self::MCP,
            'agent-api.v1.connections.destroy' => self::MCP,
            'agent-api.v1.context' => self::REST,
        ] as $name => $resource) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = app('router')->gatherRouteMiddleware($route);
            $expected = array_search(ExpectOAuthResource::class.':'.$resource, $middleware, true);
            $authenticated = array_search(Authenticate::class.':api', $middleware, true);
            $this->assertIsInt($expected, $name);
            $this->assertIsInt($authenticated, $name);
            $this->assertLessThan($authenticated, $expected, $name);
        }
    }

    /**
     * Generic OAuth clients never send RFC 8707 `resource`, so an omitted one
     * means the REST resource, /api/v1 (#384). A different explicit resource
     * and a scope gain on refresh are still refused, and a refusal consumes
     * nothing.
     */
    public function test_an_omitted_resource_binds_to_the_api_and_a_different_one_or_a_scope_gain_fails_without_consuming_credentials(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier, null);

        $this->exchangeCode($clientId, $code, $verifier, 'https://other.example.test/api/v1')
            ->assertBadRequest();
        $this->assertDatabaseHas('oauth_auth_codes', [
            'client_id' => $clientId,
            'revoked' => false,
        ]);

        $tokens = $this->exchangeCode($clientId, $code, $verifier, null)->assertOk()->json();
        $access = Passport::token()->newQuery()
            ->where('client_id', $clientId)
            ->where('revoked', false)
            ->sole();
        $this->assertSame(OAuthResourceIndicator::resource(self::REST), $access->resource_uri);

        $this->refresh($clientId, $tokens['refresh_token'], 'https://other.example.test/api/v1')
            ->assertBadRequest();
        $this->refresh(
            $clientId,
            $tokens['refresh_token'],
            null,
            ['scope' => implode(' ', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::PROJECTS_READ])],
        )->assertBadRequest()->assertJsonPath('error', 'invalid_scope');

        $this->assertFalse((bool) $access->fresh()->revoked);
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'access_token_id' => $access->id,
            'revoked' => false,
        ]);
        $refreshed = $this->refresh($clientId, $tokens['refresh_token'], null)->assertOk()->json();
        $this->assertSame(
            OAuthResourceIndicator::resource(self::REST),
            OAuthResourceIndicator::tokenClaims($refreshed['access_token'])['resource'] ?? null,
        );
    }

    public function test_refresh_resource_survives_access_token_row_purge(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier);
        $tokens = $this->exchangeCode($clientId, $code, $verifier, OAuthResourceIndicator::resource(self::MCP))
            ->assertOk()
            ->json();
        $claims = OAuthResourceIndicator::tokenClaims($tokens['access_token']);
        $accessTokenId = $claims['jti'] ?? null;
        $this->assertIsString($accessTokenId);
        $this->assertDatabaseHas('oauth_refresh_tokens', [
            'access_token_id' => $accessTokenId,
            'resource_uri' => OAuthResourceIndicator::resource(self::MCP),
        ]);

        Passport::token()->newQuery()->whereKey($accessTokenId)->delete();
        $this->assertDatabaseMissing('oauth_access_tokens', ['id' => $accessTokenId]);

        $refreshed = $this->refresh($clientId, $tokens['refresh_token'], OAuthResourceIndicator::resource(self::MCP))
            ->assertOk()
            ->json();
        $refreshedClaims = OAuthResourceIndicator::tokenClaims($refreshed['access_token']);
        $this->assertSame(OAuthResourceIndicator::resource(self::MCP), $refreshedClaims['resource'] ?? null);
        $this->assertContains(OAuthResourceIndicator::resource(self::MCP), $refreshedClaims['aud'] ?? []);
    }

    public function test_bound_token_requires_an_explicit_matching_route_resource(): void
    {
        $user = User::factory()->create();
        $restClient = $this->registerClient();
        $verifier = $this->verifier();
        $rest = $this->exchangeCode($restClient, $this->authorize($user, $restClient, $verifier, self::REST), $verifier, OAuthResourceIndicator::resource(self::REST))
            ->assertOk()
            ->json('access_token');
        $mcpClient = $this->registerClient();
        $mcp = $this->exchangeCode($mcpClient, $this->authorize($user, $mcpClient, $verifier), $verifier, OAuthResourceIndicator::resource(self::MCP))
            ->assertOk()
            ->json('access_token');
        $this->assertIsString($rest);
        $this->assertIsString($mcp);

        Auth::forgetGuards();
        $this->withToken($rest)->getJson('/api/v1/context')->assertOk();
        Auth::forgetGuards();
        $this->mcpInitialize($mcp)->assertOk();

        // An endpoint is its own audience: neither token crosses to the other.
        Auth::forgetGuards();
        $this->mcpInitialize($rest)->assertUnauthorized();
        Auth::forgetGuards();
        $this->withToken($mcp)->getJson('/api/v1/context')->assertUnauthorized();

        Route::get('/api/unmarked-oauth-test', static fn () => response()->json(['ok' => true]))
            ->middleware('auth:api');
        foreach ([$rest, $mcp] as $token) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/unmarked-oauth-test')->assertUnauthorized();
        }
    }

    public function test_legacy_dynamic_client_without_scope_metadata_fails_closed(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        Passport::client()->newQuery()->whereKey($clientId)->update(['scopes' => null]);
        $verifier = $this->verifier();

        $response = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'response_type' => 'code',
            'scope' => AgentApiScopes::MCP_USE,
            'state' => 'legacy-client-state',
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource(self::MCP),
        ], '', '&', PHP_QUERY_RFC3986));

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_scope', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('state=legacy-client-state', (string) $response->headers->get('Location'));
        $this->assertDatabaseCount('oauth_auth_codes', 0);
    }

    public function test_disabling_oauth_issuance_closes_metadata_registration_authorization_and_token_routes(): void
    {
        config(['bherila-auth.oauth_server.enabled' => false]);

        foreach ([
            $this->getJson('/.well-known/oauth-authorization-server'),
            $this->getJson('/.well-known/oauth-protected-resource/api/v1'),
            $this->getJson('/.well-known/oauth-protected-resource/api/v1/mcp'),
        ] as $response) {
            $response->assertNotFound()->assertJsonPath('error', 'not_found');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        $registration = $this->postJson('/oauth/register', [
            'client_name' => 'Disabled client',
            'redirect_uris' => ['https://client.example.test/callback'],
        ])->assertNotFound()->assertJsonPath('error', 'not_found');
        $this->assertStringContainsString('no-store', (string) $registration->headers->get('Cache-Control'));

        foreach ([
            $this->getJson('/oauth/authorize'),
            $this->postJson('/oauth/token', ['grant_type' => 'refresh_token']),
            $this->postJson('/oauth/token/refresh'),
        ] as $response) {
            $response->assertNotFound()->assertJsonPath('error', 'not_found');
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    public function test_disabling_oauth_issuance_does_not_disable_a_valid_existing_resource_credential(): void
    {
        $user = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $code = $this->authorize($user, $clientId, $verifier, self::REST);
        $token = $this->exchangeCode($clientId, $code, $verifier, OAuthResourceIndicator::resource(self::REST))
            ->assertOk()
            ->json('access_token');
        $this->assertIsString($token);

        config(['bherila-auth.oauth_server.enabled' => false]);
        Auth::forgetGuards();

        $this->withToken($token)->getJson('/api/v1/context')->assertOk();
    }

    /**
     * `connections.revoke` ends the calling connection and nothing else: not
     * another of the same person's connections, and not anyone else's.
     */
    public function test_a_connection_can_revoke_only_itself(): void
    {
        $person = User::factory()->create();
        $stranger = User::factory()->create();
        $clientId = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $connect = function (User $user, string $clientId) use ($resource): array {
            $verifier = $this->verifier();

            return $this->exchangeCode($clientId, $this->authorize($user, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        };
        $caller = $connect($person, $clientId);
        // Consent is asked once per person and client, so the sibling is another client's.
        $sibling = $connect($person, $this->registerClient());
        $theirs = $connect($stranger, $clientId);
        $id = static fn (array $tokens): string => OAuthResourceIndicator::tokenClaims($tokens['access_token'])['jti'];

        foreach ([$id($sibling), $id($theirs)] as $other) {
            Auth::forgetGuards();
            $this->withToken($caller['access_token'])->deleteJson("/api/v1/connections/{$other}")->assertNotFound();
            $this->assertFalse((bool) Passport::token()->newQuery()->whereKey($other)->value('revoked'));
        }
        $this->assertSame(0, Passport::refreshToken()->newQuery()->where('revoked', true)->count());

        Auth::forgetGuards();
        $this->withToken($caller['access_token'])->deleteJson("/api/v1/connections/{$id($caller)}")->assertNoContent();
        $this->refresh($clientId, $caller['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        Auth::forgetGuards();
        $this->mcpInitialize($sibling['access_token'])->assertOk();
        $this->refresh($clientId, $theirs['refresh_token'], $resource)->assertOk();
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

    /**
     * An MCP connection by default: `mcp:use` plus an operation scope, for the
     * MCP resource. A REST client asks for operation scopes only, naming the
     * REST resource or (null) nothing at all.
     *
     * @param  list<string>|null  $scopes
     */
    private function authorize(User $user, string $clientId, string $verifier, ?string $resource = self::MCP, ?array $scopes = null): string
    {
        $scopes ??= $resource === self::MCP
            ? [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]
            : [AgentApiScopes::IDENTITY_READ];
        $this->actingAs($user, 'web');
        $response = $this->get('/oauth/authorize?'.http_build_query(array_filter([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'oauth-test-state',
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $resource === null ? null : OAuthResourceIndicator::resource($resource),
        ], static fn (?string $value): bool => $value !== null), '', '&', PHP_QUERY_RFC3986));
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

    private function exchangeCode(string $clientId, string $code, string $verifier, ?string $resource): TestResponse
    {
        $parameters = [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'code' => $code,
            'code_verifier' => $verifier,
        ];
        if ($resource !== null) {
            $parameters['resource'] = $resource;
        }

        return $this->post('/oauth/token', $parameters, ['Accept' => 'application/json']);
    }

    /** @param array<string, string> $extra */
    private function refresh(string $clientId, string $refreshToken, ?string $resource, array $extra = []): TestResponse
    {
        $parameters = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refreshToken,
            ...$extra,
        ];
        if ($resource !== null) {
            $parameters['resource'] = $resource;
        }

        return $this->post('/oauth/token', $parameters, ['Accept' => 'application/json']);
    }

    private function mcpInitialize(string $token): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Mcp-Protocol-Version' => '2025-06-18',
        ])->postJson('/api/v1/mcp', $this->initializeMessage());
    }

    /** @return array<string, mixed> */
    private function initializeMessage(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'SVC OAuth lifecycle test', 'version' => '1'],
            ],
        ];
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
