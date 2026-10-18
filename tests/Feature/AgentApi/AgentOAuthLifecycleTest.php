<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Credentials\AgentCredentialFence;
use App\Services\Credentials\AgentCredentialSelection;
use App\Services\Credentials\CredentialRevoker;
use App\Services\Credentials\RevocationRefused;
use App\Services\Credentials\RevokeCredentialsIssuedDuringRevocation;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\WorkspaceClock;
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
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Bridge\AccessTokenRepository as PassportAccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository as PassportRefreshTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use RuntimeException;
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
     * Emergency containment (#456): an operator cuts off one person's
     * connections to one client, then all of them, with no SQL and no token of
     * the person's to do it with - and another person's connection to the same
     * client is never touched.
     */
    public function test_emergency_revocation_cuts_off_one_person_s_connections_by_client_then_all(): void
    {
        $person = User::factory()->create();
        $bystander = User::factory()->create();
        $first = $this->registerClient();
        $second = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $connect = function (User $user, string $clientId) use ($resource): array {
            $verifier = $this->verifier();

            return $this->exchangeCode($clientId, $this->authorize($user, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        };
        $purged = $connect($person, $first);
        $onSecond = $connect($person, $second);
        $neighbour = $connect($bystander, $first);
        // Passport purged this access row; its refresh token lives on alone.
        Passport::token()->newQuery()->whereKey(OAuthResourceIndicator::tokenClaims($purged['access_token'])['jti'])->delete();
        // A consent the connector has not yet exchanged.
        $this->authorize($person, $first, $this->verifier());
        $this->assertSame(1, Passport::authCode()->newQuery()->where('user_id', $person->id)->where('revoked', false)->count());

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => $first]));
        $this->assertSame(['access_tokens' => 0, 'refresh_tokens' => 1, 'authorization_codes' => 1, 'unattributed_refresh_tokens' => 0], json_decode(trim(Artisan::output()), true));
        $this->refresh($first, $purged['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        Auth::forgetGuards();
        $this->mcpInitialize($onSecond['access_token'])->assertOk();
        $onSecond = $this->refresh($second, $onSecond['refresh_token'], $resource)->assertOk()->json();

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id]));
        $this->assertSame(['access_tokens' => 1, 'refresh_tokens' => 1, 'authorization_codes' => 0, 'unattributed_refresh_tokens' => 0], json_decode(trim(Artisan::output()), true));
        Auth::forgetGuards();
        $this->mcpInitialize($onSecond['access_token'])->assertUnauthorized();
        $this->refresh($second, $onSecond['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');

        // Idempotent: nothing of the person's is left live.
        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id]));
        $this->assertSame(['access_tokens' => 0, 'refresh_tokens' => 0, 'authorization_codes' => 0, 'unattributed_refresh_tokens' => 0], json_decode(trim(Artisan::output()), true));

        // The other person's connection to the same client still works.
        Auth::forgetGuards();
        $this->mcpInitialize($neighbour['access_token'])->assertOk();
        $this->refresh($first, $neighbour['refresh_token'], $resource)->assertOk();
    }

    /**
     * A grant that passed its own check just before a revocation persists its
     * credentials just after it. Whatever lands inside the fence - here a code
     * exchange standing in for that grant - is revoked before it is returned,
     * for the fenced client only, and the fence lifts after its window.
     */
    public function test_credentials_a_grant_persists_just_after_a_revocation_are_revoked_too(): void
    {
        $person = User::factory()->create();
        $fenced = $this->registerClient();
        $other = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $verifier = $this->verifier();
        $inFlight = $this->authorize($person, $fenced, $verifier);
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => strtoupper($fenced)]));
        // The code itself was revoked; restore it to play the grant that had already passed its check.
        Passport::authCode()->newQuery()->where('client_id', $fenced)->update(['revoked' => false]);
        $raced = $this->exchangeCode($fenced, $inFlight, $verifier, $resource)->assertOk()->json();

        $raceId = OAuthResourceIndicator::tokenClaims($raced['access_token'])['jti'];
        $this->assertTrue((bool) Passport::token()->newQuery()->whereKey($raceId)->value('revoked'));
        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $raceId)->value('revoked'));
        Auth::forgetGuards();
        $this->mcpInitialize($raced['access_token'])->assertUnauthorized();
        $this->refresh($fenced, $raced['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        // Both are audited: the access token through the revoker, the refresh token as it lands.
        $fenceEntries = array_values(array_filter($logged, static fn (array $entry): bool => ($entry['context']['actor'] ?? null) === 'revocation-fence'));
        $this->assertSame(['agent_token.revoked', 'agent_token.revoked_in_fence'], array_column($fenceEntries, 'message'));
        $this->assertSame([hash('sha256', $raceId)], $fenceEntries[0]['context']['credential_fingerprints']);
        $this->assertSame(['refresh_token', $person->public_id, $fenced], [$fenceEntries[1]['context']['credential'], $fenceEntries[1]['context']['subject_id'], $fenceEntries[1]['context']['client_id']]);

        // A consent approved inside the fence leaves a dead code, even once the fence lapses.
        $codeVerifier = $this->verifier();
        $approvedInFence = $this->authorize($person, $fenced, $codeVerifier);
        $this->assertTrue((bool) Passport::authCode()->newQuery()->where('client_id', $fenced)->where('revoked', false)->doesntExist());

        // Another client of the same person is not fenced.
        $verifier = $this->verifier();
        $unfenced = $this->exchangeCode($other, $this->authorize($person, $other, $verifier), $verifier, $resource)->assertOk()->json();
        Auth::forgetGuards();
        $this->mcpInitialize($unfenced['access_token'])->assertOk();

        // After the window the person reconnects normally.
        $this->travel(AgentCredentialFence::SECONDS + 1)->seconds();
        $this->exchangeCode($fenced, $approvedInFence, $codeVerifier, $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
        $verifier = $this->verifier();
        $later = $this->exchangeCode($fenced, $this->authorize($person, $fenced, $verifier), $verifier, $resource)->assertOk()->json();
        Auth::forgetGuards();
        $this->mcpInitialize($later['access_token'])->assertOk();
    }

    /**
     * A refresh token from before refresh tokens recorded an owner, whose
     * access token is gone, cannot be tied to anyone: it is counted on every
     * run and revoked, for everyone, only when asked.
     */
    public function test_unattributed_refresh_tokens_are_reported_and_revoked_only_on_request(): void
    {
        $person = User::factory()->create();
        $clientId = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $verifier = $this->verifier();
        $legacy = $this->exchangeCode($clientId, $this->authorize($person, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        $accessId = OAuthResourceIndicator::tokenClaims($legacy['access_token'])['jti'];
        Passport::refreshToken()->newQuery()->where('access_token_id', $accessId)->update(['provider_user_id' => null, 'client_id' => null]);
        Passport::token()->newQuery()->whereKey($accessId)->delete();
        $stranger = User::factory()->create();

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $stranger->public_id]));
        $this->assertSame(['access_tokens' => 0, 'refresh_tokens' => 0, 'authorization_codes' => 0, 'unattributed_refresh_tokens' => 1], json_decode(trim(Artisan::output()), true));
        $this->assertFalse((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $accessId)->value('revoked'));

        // No fence can name an unattributed token's owner, so issuance must be off first.
        $this->assertSame(1, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $stranger->public_id, '--unattributed' => true]));
        $this->assertFalse((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $accessId)->value('revoked'));
        $this->expectsRefusal(static fn () => app(CredentialRevoker::class)->revokeUnattributedRefreshTokens('test'));

        config(['bherila-auth.oauth_server.enabled' => false]);
        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $stranger->public_id, '--unattributed' => true]));
        $this->assertSame(['access_tokens' => 0, 'refresh_tokens' => 0, 'authorization_codes' => 0, 'unattributed_refresh_tokens_revoked' => 1, 'unattributed_refresh_tokens' => 0], json_decode(trim(Artisan::output()), true));
        config(['bherila-auth.oauth_server.enabled' => true]);
        $this->refresh($clientId, $legacy['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * Each legacy refresh token takes its own parent's owner and provider
     * stamp, as the repository writes them; an owner already recorded is never
     * overwritten, and an orphan's is never guessed.
     */
    public function test_the_owner_backfill_gives_each_legacy_refresh_token_its_own_parent_s_owner_and_stamp(): void
    {
        $clientId = $this->registerClient();
        $legacy = function (int $userId, string $subject, int $generation, ?string $recorded = null) use ($clientId): string {
            $accessId = Str::random(80);
            Passport::token()->forceFill([
                'id' => $accessId, 'user_id' => $userId, 'client_id' => $clientId, 'scopes' => [], 'revoked' => false,
                'expires_at' => now()->addHour(), 'provider_subject' => $subject, 'provider_generation' => $generation,
            ])->saveOrFail();
            $refreshId = Str::random(80);
            Passport::refreshToken()->forceFill([
                'id' => $refreshId, 'access_token_id' => $accessId, 'revoked' => false, 'expires_at' => now()->addDay(),
                'provider_user_id' => $recorded,
            ])->saveOrFail();

            return $refreshId;
        };
        $first = User::factory()->create();
        $second = User::factory()->create();
        $firstRefresh = $legacy($first->id, 'subject-first', 3);
        $secondRefresh = $legacy($second->id, 'subject-second', 7);
        $kept = $legacy($first->id, 'subject-kept', 9, 'already-recorded');
        $orphan = Str::random(80);
        Passport::refreshToken()->forceFill(['id' => $orphan, 'access_token_id' => Str::random(80), 'revoked' => false, 'expires_at' => now()->addDay()])->saveOrFail();

        (require database_path('migrations/2026_10_18_120000_backfill_oauth_refresh_token_owners.php'))->up();

        $row = static fn (string $id): array => array_map(
            static fn (mixed $value): ?string => $value === null ? null : (string) $value,
            Passport::refreshToken()->newQuery()->whereKey($id)->firstOrFail(['provider_user_id', 'provider_subject', 'provider_generation'])->getAttributes(),
        );
        $this->assertSame(['provider_user_id' => (string) $first->id, 'provider_subject' => 'subject-first', 'provider_generation' => '3'], $row($firstRefresh));
        $this->assertSame(['provider_user_id' => (string) $second->id, 'provider_subject' => 'subject-second', 'provider_generation' => '7'], $row($secondRefresh));
        $this->assertSame(['provider_user_id' => 'already-recorded', 'provider_subject' => null, 'provider_generation' => null], $row($kept));
        $this->assertSame(['provider_user_id' => null, 'provider_subject' => null, 'provider_generation' => null], $row($orphan));
    }

    /**
     * A refresh grant revokes the old access token before it issues the new
     * one, so a self-revocation can find its token already revoked. It still
     * fences that token's client, so the replacement the refresh persists next
     * is revoked.
     */
    public function test_a_self_revocation_racing_a_refresh_still_fences_the_replacement(): void
    {
        $person = User::factory()->create();
        $clientId = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $verifier = $this->verifier();
        $tokens = $this->exchangeCode($clientId, $this->authorize($person, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        $accessId = OAuthResourceIndicator::tokenClaims($tokens['access_token'])['jti'];
        // The refresh grant got here first: the old access token is revoked, its refresh token not yet replaced.
        Passport::token()->newQuery()->whereKey($accessId)->update(['revoked' => true]);

        $revocation = app(CredentialRevoker::class)->revoke(AgentCredentialSelection::token(AgentPrincipal::query()->findOrFail($person->id), $accessId), 'agent');
        $this->assertSame(0, $revocation->accessTokens());

        // The refresh now persists its replacement, which lands inside the fence.
        Passport::token()->newQuery()->whereKey($accessId)->update(['revoked' => false]);
        $replacement = $this->refresh($clientId, $tokens['refresh_token'], $resource)->assertOk()->json();
        Auth::forgetGuards();
        $this->mcpInitialize($replacement['access_token'])->assertUnauthorized();
        $this->refresh($clientId, $replacement['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * A refresh token that records its owner but not its client, whose access
     * token is gone, may be any of the owner's clients: a client-scoped
     * revocation takes it rather than miss it, and never another person's.
     */
    public function test_a_client_scoped_revocation_takes_the_owner_s_orphans_of_unknown_client(): void
    {
        $person = User::factory()->create();
        $stranger = User::factory()->create();
        $clientId = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $orphan = function (User $user) use ($clientId, $resource): string {
            $verifier = $this->verifier();
            $tokens = $this->exchangeCode($clientId, $this->authorize($user, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
            $accessId = OAuthResourceIndicator::tokenClaims($tokens['access_token'])['jti'];
            Passport::refreshToken()->newQuery()->where('access_token_id', $accessId)->update(['client_id' => null]);
            Passport::token()->newQuery()->whereKey($accessId)->delete();

            return $accessId;
        };
        $mine = $orphan($person);
        $theirs = $orphan($stranger);
        $elsewhere = $this->registerClient();

        // Upper case, as an operator may paste it.
        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => strtoupper($elsewhere)]));
        $this->assertSame(['access_tokens' => 0, 'refresh_tokens' => 1, 'authorization_codes' => 0, 'unattributed_refresh_tokens' => 0], json_decode(trim(Artisan::output()), true));
        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $mine)->value('revoked'));
        $this->assertFalse((bool) Passport::refreshToken()->newQuery()->where('access_token_id', $theirs)->value('revoked'));
    }

    /**
     * A racing refresh revoked the token and a purge removed the row before the
     * self-revocation looked: its lineage is fenced all the same, keyed on the
     * id, and nothing wider is.
     */
    public function test_a_self_revocation_fences_its_lineage_when_the_row_is_gone(): void
    {
        $person = User::factory()->create();
        $owner = AgentPrincipal::query()->findOrFail($person->id);
        $fence = app(AgentCredentialFence::class);
        $tokenId = Str::random(80);

        $revocation = app(CredentialRevoker::class)->revoke(AgentCredentialSelection::token($owner, $tokenId), 'agent');

        $this->assertSame(0, $revocation->accessTokens());
        $this->assertTrue($fence->coversLineage($tokenId));
        $this->assertFalse($fence->coversLineage(Str::random(80)));
        $this->assertFalse($fence->covers($person->id, 'any-client'));
    }

    /**
     * Two connections of one person with the same client: disconnecting one
     * leaves the other refreshing inside the window, while the disconnected
     * one's own in-flight refresh is still caught.
     */
    public function test_a_self_revocation_spares_a_same_client_sibling_and_still_catches_its_own_refresh(): void
    {
        $person = User::factory()->create();
        $clientId = $this->registerClient();
        $resource = OAuthResourceIndicator::resource(self::MCP);
        $verifier = $this->verifier();
        $caller = $this->exchangeCode($clientId, $this->authorize($person, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        // Consent was given once for this client, so the second connection is approved without a prompt.
        $verifier = $this->verifier();
        $sibling = $this->exchangeCode($clientId, $this->authorizeWithoutPrompt($person, $clientId, $verifier), $verifier, $resource)->assertOk()->json();
        $callerId = OAuthResourceIndicator::tokenClaims($caller['access_token'])['jti'];

        Auth::forgetGuards();
        $this->withToken($caller['access_token'])->deleteJson("/api/v1/connections/{$callerId}")->assertNoContent();

        // The sibling refreshes inside the window and keeps working.
        $sibling = $this->refresh($clientId, $sibling['refresh_token'], $resource)->assertOk()->json();
        Auth::forgetGuards();
        $this->mcpInitialize($sibling['access_token'])->assertOk();
        $this->refresh($clientId, $sibling['refresh_token'], $resource)->assertOk();

        // The caller's own refresh, played as one that had passed its check before the lock, is caught.
        Passport::refreshToken()->newQuery()->where('access_token_id', $callerId)->update(['revoked' => false]);
        $raced = $this->refresh($clientId, $caller['refresh_token'], $resource)->assertOk()->json();
        Auth::forgetGuards();
        $this->mcpInitialize($raced['access_token'])->assertUnauthorized();
        $this->refresh($clientId, $raced['refresh_token'], $resource)->assertBadRequest()->assertJsonPath('error', 'invalid_grant');
    }

    /**
     * The fence is a backstop, never a precondition: with the cache down the
     * emergency revocation still revokes everything, and says the fence is off.
     */
    public function test_a_cache_outage_lifts_only_the_fence_never_the_revocation(): void
    {
        $person = User::factory()->create();
        $clientId = $this->registerClient();
        $verifier = $this->verifier();
        $tokens = $this->exchangeCode($clientId, $this->authorize($person, $clientId, $verifier), $verifier, OAuthResourceIndicator::resource(self::MCP))->assertOk()->json();
        $broken = new class(new ArrayStore) extends CacheRepository
        {
            public function put($key, $value, $ttl = null): bool
            {
                throw new RuntimeException('cache unavailable');
            }
        };
        $this->app->instance(AgentCredentialFence::class, new AgentCredentialFence($broken, new WorkspaceClock));
        $this->app->forgetInstance(CredentialRevoker::class);
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['level' => $event->level, 'message' => $event->message, 'context' => $event->context];
        });

        $this->assertSame(0, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id]));

        $this->assertSame(1, json_decode(trim(Artisan::output()), true)['access_tokens']);
        Auth::forgetGuards();
        $this->mcpInitialize($tokens['access_token'])->assertUnauthorized();
        $warnings = array_values(array_filter($logged, static fn (array $entry): bool => $entry['message'] === 'agent_token.fence_unavailable'));
        $this->assertNotEmpty($warnings);
        $this->assertSame('warning', $warnings[0]['level']);
        $this->assertSame($person->public_id, $warnings[0]['context']['subject_id'] ?? null);
        $this->assertStringNotContainsString($tokens['access_token'], json_encode($logged, JSON_THROW_ON_ERROR));
    }

    /** The window follows the commit, however long the revocation's transaction took. */
    public function test_the_fence_is_counted_from_the_revocation_s_commit(): void
    {
        $person = User::factory()->create();
        $owner = AgentPrincipal::query()->findOrFail($person->id);
        $fence = app(AgentCredentialFence::class);

        DB::transaction(function () use ($owner): void {
            app(CredentialRevoker::class)->revoke(AgentCredentialSelection::connections($owner), 'test');
            $this->travel(AgentCredentialFence::SECONDS - 10)->seconds();
        });
        $this->travel(AgentCredentialFence::SECONDS - 10)->seconds();

        $this->assertTrue($fence->covers($person->id, 'any-client'));
        $this->travel(11)->seconds();
        $this->assertFalse($fence->covers($person->id, 'any-client'));
    }

    /**
     * A purge between the fence revoking a racing grant's access token and
     * Passport persisting its refresh token removes the parent; the refresh
     * token, which keeps its own owner and client, is revoked all the same.
     */
    public function test_a_refresh_token_persisted_after_its_parent_was_purged_is_revoked(): void
    {
        $person = User::factory()->create();
        $refreshId = Str::random(80);
        Passport::refreshToken()->forceFill([
            'id' => $refreshId,
            'access_token_id' => Str::random(80),
            'revoked' => false,
            'expires_at' => now()->addDay(),
            'provider_user_id' => (string) $person->id,
            'client_id' => 'purged-parent-client',
        ])->saveOrFail();
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        app(RevokeCredentialsIssuedDuringRevocation::class)->refreshTokenCreated(new RefreshTokenCreated($refreshId, Str::random(80)));

        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->whereKey($refreshId)->value('revoked'));
        $this->assertSame([[
            'message' => 'agent_token.revoked_in_fence',
            'context' => [
                'actor' => 'revocation-fence',
                'subject_id' => $person->public_id,
                'credential' => 'refresh_token',
                'client_id' => 'purged-parent-client',
                'credential_fingerprint' => hash('sha256', $refreshId),
            ],
        ]], $logged);
    }

    /**
     * The repository takes a refresh token's owner from its parent, so after a
     * purge it records none; the audit still names whose access token the fence
     * revoked a moment before.
     */
    public function test_a_purged_parent_s_refresh_token_is_audited_with_the_fenced_owner(): void
    {
        $person = User::factory()->create();
        $clientId = $this->registerClient();
        $accessId = Str::random(80);
        Passport::token()->forceFill(['id' => $accessId, 'user_id' => $person->id, 'client_id' => $clientId, 'scopes' => [], 'revoked' => false, 'expires_at' => now()->addHour()])->saveOrFail();
        app(AgentCredentialFence::class)->raise($person->id, $clientId);
        $listener = app(RevokeCredentialsIssuedDuringRevocation::class);
        $listener->accessTokenCreated(new AccessTokenCreated($accessId, (string) $person->id, $clientId));
        $this->assertTrue((bool) Passport::token()->newQuery()->whereKey($accessId)->value('revoked'));
        // A catch raises no fence of its own, so catching never prolongs the window.
        $this->assertFalse(app(AgentCredentialFence::class)->coversLineage($accessId));
        Passport::token()->newQuery()->whereKey($accessId)->delete();
        $refreshId = Str::random(80);
        Passport::refreshToken()->forceFill(['id' => $refreshId, 'access_token_id' => $accessId, 'revoked' => false, 'expires_at' => now()->addDay()])->saveOrFail();
        $logged = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $event) use (&$logged): void {
            $logged[] = ['message' => $event->message, 'context' => $event->context];
        });

        $listener->refreshTokenCreated(new RefreshTokenCreated($refreshId, $accessId));

        $this->assertTrue((bool) Passport::refreshToken()->newQuery()->whereKey($refreshId)->value('revoked'));
        $this->assertSame('agent_token.revoked_in_fence', $logged[0]['message'] ?? null);
        $this->assertSame([$person->public_id, $clientId], [$logged[0]['context']['subject_id'], $logged[0]['context']['client_id']]);
    }

    public function test_emergency_revocation_refuses_what_is_not_an_exact_person_or_client(): void
    {
        $person = User::factory()->create();

        $this->assertSame(2, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => (string) $person->id]));
        $this->assertSame(1, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => (string) Str::uuid()]));
        $this->assertSame(2, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => 'not-a-client']));
        $this->assertSame(2, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => '']));
        // A bare --client never widens to every connection.
        $verifier = $this->verifier();
        $clientId = $this->registerClient();
        $kept = $this->exchangeCode($clientId, $this->authorize($person, $clientId, $verifier), $verifier, OAuthResourceIndicator::resource(self::MCP))->assertOk()->json();
        $this->assertSame(2, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => null]));
        $this->assertFalse((bool) Passport::token()->newQuery()->whereKey(OAuthResourceIndicator::tokenClaims($kept['access_token'])['jti'])->value('revoked'));
        $this->assertSame(1, Artisan::call('svc:auth:revoke-connections', ['user_public_id' => $person->public_id, '--client' => (string) Str::uuid()]));
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

    private function expectsRefusal(callable $revoke): void
    {
        try {
            $revoke();
        } catch (RevocationRefused) {
            return;
        }
        $this->fail('The unattributed revocation was not refused while OAuth issuance is on.');
    }

    /** A person who already consented to this client is sent straight back with a code. */
    private function authorizeWithoutPrompt(User $user, string $clientId, string $verifier): string
    {
        $this->actingAs($user, 'web');
        $location = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => 'http://127.0.0.1:3210/callback',
            'response_type' => 'code',
            'scope' => implode(' ', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]),
            'state' => 'oauth-test-state',
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource(self::MCP),
        ], '', '&', PHP_QUERY_RFC3986))->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        return $query['code'];
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
