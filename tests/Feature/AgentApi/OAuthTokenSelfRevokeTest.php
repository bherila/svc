<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Laravel\Passport\Passport;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

final class OAuthTokenSelfRevokeTest extends TestCase
{
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    private const string ENDPOINT = '/api/v1/oauth/token';

    private const string REDIRECT = 'http://127.0.0.1:3210/callback';

    public function test_self_revocation_ends_only_the_calling_family_and_preserves_other_grants(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$user, $other] as $owner) {
            $workspace = Workspace::query()->create(['name' => 'Synthetic workspace', 'slug' => 'self-revoke-'.$owner->id]);
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        }
        $client = $this->publicClient();
        $existing = $this->connect($user, $client);
        $leaving = $this->connect($user, $client);
        $anotherClient = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $anotherOwner = $this->connect($other, $client);
        $pendingCode = $this->authorizationCode($user, $client);
        $rotated = $this->refresh($leaving, $client);
        $familyIds = array_map(static fn (array $tokens): string => OAuthResourceIndicator::tokenClaims($tokens['access_token'])['jti'], [$leaving, $rotated]);

        $this->withToken($rotated['access_token'])->deleteJson(self::ENDPOINT)
            ->assertNoContent()->assertHeader('Cache-Control', 'no-store, private');

        foreach ([$leaving, $rotated] as $ended) {
            $this->app['auth']->forgetGuards();
            $this->withToken($ended['access_token'])->getJson('/api/v1/context')->assertUnauthorized();
            $this->assertRefreshRefused($ended, $client);
        }
        $this->assertSame(0, Passport::token()->newQuery()->whereKey($familyIds)->where('revoked', false)->count());
        $this->assertSame(0, Passport::refreshToken()->newQuery()->whereIn('access_token_id', $familyIds)->where('revoked', false)->count());
        foreach ([[$existing, $client], [$anotherClient, $anotherClient['client_id']], [$anotherOwner, $client]] as [$kept, $keptClient]) {
            $this->app['auth']->forgetGuards();
            $this->withToken($kept['access_token'])->getJson('/api/v1/context')->assertOk();
            $this->refresh($kept, $keptClient);
        }
        // An unexchanged grant for the same person/client belongs to another family too.
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => self::REDIRECT,
            'code' => $pendingCode, 'code_verifier' => 'unused-verifier-unused-verifier-unused-verifier',
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($rotated['access_token'])->deleteJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_both_resources_and_minimal_scopes_can_revoke_themselves(): void
    {
        foreach ([['rest', [AgentApiScopes::IDENTITY_READ]], ['mcp', [AgentApiScopes::IDENTITY_READ]], ['mcp', [AgentApiScopes::MCP_USE]]] as [$resource, $scopes]) {
            // Neither finance permissions nor mcp:use is needed to discard a credential.
            $tokens = $this->issueAgentTokens(User::factory()->create(), $resource, $scopes);
            $this->withToken($tokens['access_token'])->deleteJson(self::ENDPOINT)->assertNoContent();
            $this->assertRefreshRefused($tokens, $tokens['client_id'], $resource);
            $this->app['auth']->forgetGuards();
            $this->withToken($tokens['access_token'])->deleteJson(self::ENDPOINT)->assertUnauthorized();
        }
    }

    public function test_a_personal_oauth_token_with_no_scopes_revokes_only_itself(): void
    {
        $user = User::factory()->create();
        $credentials = app(ApiCredentialService::class);
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $leaving = $credentials->issueToken($principal, 'Discarded token', [AgentApiScopes::IDENTITY_READ], 'P30D');
        $id = OAuthResourceIndicator::tokenClaims($leaving['token'])['jti'];
        Passport::token()->newQuery()->whereKey($id)->update(['scopes' => '[]']);
        $leaving['token'] = $this->signedClaims($leaving['token'], ['scopes' => []]);
        $staying = $credentials->issueToken($principal, 'Existing token', [AgentApiScopes::IDENTITY_READ], 'P30D');
        $this->withToken($leaving['token'])->deleteJson(self::ENDPOINT)->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($staying['token'])->getJson('/api/v1/context')->assertOk();
    }

    public function test_invalid_bearers_and_untrusted_audiences_revoke_nothing(): void
    {
        $user = User::factory()->create();
        $tokens = $this->issueAgentTokens($user, 'mcp', [AgentApiScopes::IDENTITY_READ]);
        $bearer = $tokens['access_token'];
        $claims = OAuthResourceIndicator::tokenClaims($bearer);
        $id = $claims['jti'];
        $this->deleteJson(self::ENDPOINT)->assertUnauthorized();
        foreach ([
            'malformed',
            $this->signedClaims($bearer, [], $this->strangerPrivateKey()),
            $this->signedClaims($bearer, ['exp' => now()->subMinute()->timestamp]),
            $this->signedClaims($bearer, ['iss' => 'https://untrusted.example.test']),
            $this->signedClaims($bearer, ['aud' => [$tokens['client_id'], OAuthResourceIndicator::resource('rest')], 'resource' => OAuthResourceIndicator::resource('rest')]),
            $this->signedClaims($bearer, ['aud' => ['unknown-client', OAuthResourceIndicator::resource('mcp')]]),
        ] as $invalid) {
            $this->app['auth']->forgetGuards();
            $this->withToken($invalid)->deleteJson(self::ENDPOINT)->assertUnauthorized();
        }
        // Even a correctly signed token and matching row cannot introduce a new audience.
        $unknown = 'https://unknown.example.test/resource';
        Passport::token()->newQuery()->whereKey($id)->update(['resource_uri' => $unknown]);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->signedClaims($bearer, ['aud' => [$tokens['client_id'], $unknown], 'resource' => $unknown]))
            ->deleteJson(self::ENDPOINT)->assertUnauthorized();
        Passport::token()->newQuery()->whereKey($id)->update(['resource_uri' => OAuthResourceIndicator::resource('mcp')]);

        Passport::client()->newQuery()->whereKey($tokens['client_id'])->update(['revoked' => true]);
        // Passport caches clients with once() within a request; emulate a fresh request.
        Once::flush();
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->deleteJson(self::ENDPOINT)->assertUnauthorized();
        Passport::client()->newQuery()->whereKey($tokens['client_id'])->update(['revoked' => false]);
        Once::flush();
        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', true)->count());
        $this->assertSame(0, Passport::refreshToken()->newQuery()->where('revoked', true)->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($bearer)->deleteJson(self::ENDPOINT)->assertNoContent();
    }

    public function test_a_browser_session_or_sanctum_token_cannot_replace_the_oauth_bearer(): void
    {
        $user = User::factory()->create();
        $tokens = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $this->actingAs($user, 'web')->deleteJson(self::ENDPOINT)->assertUnauthorized();
        $sanctum = $user->createToken('Synthetic finance connector', ['finance.read', 'finance.reconcile']);
        $this->app['auth']->forgetGuards();
        $this->withToken($sanctum->plainTextToken)->deleteJson(self::ENDPOINT)->assertUnauthorized();
        $this->assertNotNull($sanctum->accessToken->fresh());
        $this->assertSame(0, Passport::token()->newQuery()->where('revoked', true)->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($tokens['access_token'])->getJson('/api/v1/context')->assertOk();
    }

    public function test_self_revocation_does_not_expand_other_routes_resource_admission(): void
    {
        $mcp = $this->issueAgentTokens(User::factory()->create(), 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $id = OAuthResourceIndicator::tokenClaims($mcp['access_token'])['jti'];
        $this->withToken($mcp['access_token'])->getJson('/api/v1/context')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($mcp['access_token'])->deleteJson('/api/v1/connection')->assertUnauthorized();
        $rest = $this->issueAgentTokens(User::factory()->create(), 'rest', [AgentApiScopes::IDENTITY_READ]);
        $this->withToken($rest['access_token'])->deleteJson('/api/v1/connections/'.$id)->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($rest['access_token'])->getJson('/api/v1/context')->assertOk();
    }

    public function test_the_registry_documents_scope_free_rest_only_self_revocation(): void
    {
        $operation = app(OperationRegistry::class)->find('oauth.token.revoke');
        $this->assertNotNull($operation);
        $this->assertSame([], $operation->requirement->scopes);
        $this->assertSame([], $operation->requirement->flags);
        $this->assertTrue($operation->requirement->authenticated);
        $this->assertTrue($operation->isIdempotent());
        $this->assertNull($operation->output);
        $this->assertNull($operation->mcp, 'Credential disposal is a client lifecycle operation, not an agent tool.');
        $documented = AgentOpenApi::checked()->full()['paths']['/oauth/token']['delete'];
        $this->assertSame('oauth.token.revoke', $documented['operationId']);
        $this->assertArrayHasKey(204, $documented['responses']);
        $this->assertSame([['oauth2' => []], ['apiToken' => []]], $documented['security']);
        $this->assertArrayNotHasKey('requestBody', $documented);
    }

    /**
     * @param  array{access_token: string, refresh_token: string}  $tokens
     * @return array{access_token: string, refresh_token: string}
     */
    private function refresh(array $tokens, string $client): array
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $tokens['refresh_token'],
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], ['Accept' => 'application/json'])->assertOk()->json();
    }

    /** @param  array{refresh_token: string}  $tokens */
    private function assertRefreshRefused(array $tokens, string $client, string $resource = 'rest'): void
    {
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $tokens['refresh_token'],
            'resource' => OAuthResourceIndicator::resource($resource),
        ], ['Accept' => 'application/json'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    }

    /** @param  array<string, mixed>  $replace */
    private function signedClaims(string $token, array $replace, ?string $privateKey = null): string
    {
        $claims = array_replace(OAuthResourceIndicator::tokenClaims($token), $replace);
        $configuration = Configuration::forAsymmetricSigner(new Sha256, InMemory::plainText($privateKey ?? str_replace('\\n', "\n", (string) config('passport.private_key'))), InMemory::plainText('unused-verification-key'));
        $builder = $configuration->builder()
            ->issuedBy($claims['iss'])->identifiedBy($claims['jti'])->relatedTo($claims['sub'])
            ->issuedAt(new DateTimeImmutable('@'.$claims['iat']))
            ->canOnlyBeUsedAfter(new DateTimeImmutable('@'.$claims['nbf']))
            ->expiresAt(new DateTimeImmutable('@'.$claims['exp']));
        foreach ($claims['aud'] as $audience) {
            $builder = $builder->permittedFor($audience);
        }

        return $builder->withClaim('scopes', $claims['scopes'])->withClaim('resource', $claims['resource'])
            ->getToken($configuration->signer(), $configuration->signingKey())->toString();
    }

    private function publicClient(): string
    {
        return (string) $this->postJson('/oauth/register', [
            'client_name' => 'Synthetic connector',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function connect(User $user, string $client): array
    {
        $verifier = str_repeat('c', 64);
        $code = $this->authorizationCode($user, $client, $verifier);
        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'code' => $code,
            'code_verifier' => $verifier,
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->app['auth']->forgetGuards();

        return ['access_token' => (string) $tokens['access_token'], 'refresh_token' => (string) $tokens['refresh_token']];
    }

    private function authorizationCode(User $user, string $client, string $verifier = 'unused-verifier-unused-verifier-unused-verifier'): string
    {
        // A route keeps its controller between requests, and Passport's keeps
        // the guard it was built with: rebuild them for this person, as a new
        // request in production would.
        foreach (['passport.authorizations.authorize', 'passport.authorizations.approve'] as $name) {
            Route::getRoutes()->getByName($name)?->flushController();
        }
        $authorize = $this->actingAs($user, 'web')->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => AgentApiScopes::IDENTITY_READ,
            'state' => 'synthetic-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], '', '&', PHP_QUERY_RFC3986));
        // A person who already holds a live grant for these scopes is not asked again.
        $approval = $authorize->isRedirect()
            ? $authorize
            : $this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);
        $this->app['auth']->forgetGuards();

        return $query['code'];
    }
}
