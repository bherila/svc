<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use Laravel\Passport\RefreshToken;
use Tests\Concerns\IssuesAgentOAuthTokens;
use Tests\TestCase;

/**
 * `connections.disconnect` (DELETE /api/v1/connection): a REST connection - a
 * finance system disconnecting, say - ends its own grant through the shared
 * CredentialRevoker. Every credential the person holds for the calling OAuth
 * client goes: access tokens, refresh tokens and pending authorization codes,
 * refresh first. The person's other connections, and everyone else's, stay.
 */
final class AgentConnectionDisconnectTest extends TestCase
{
    use IssuesAgentOAuthTokens;
    use RefreshDatabase;

    private const string REDIRECT = 'http://127.0.0.1:3210/callback';

    public function test_a_connection_ends_every_credential_of_its_client_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $client = $this->publicClient();
        $first = $this->connect($user, $client);
        $second = $this->connect($user, $client);
        $pendingCode = $this->authorizationCode($user, $client);
        $elsewhere = $this->issueAgentTokens($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $someoneElse = $this->connect($other, $client);

        $this->withToken($first['access_token'])->deleteJson('/api/v1/connection')->assertNoContent();

        foreach ([$first, $second] as $ended) {
            $this->app['auth']->forgetGuards();
            $this->withToken($ended['access_token'])->getJson('/api/v1/context')->assertUnauthorized();
            $this->post('/oauth/token', [
                'grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $ended['refresh_token'],
                'resource' => OAuthResourceIndicator::resource('rest'),
            ], ['Accept' => 'application/json'])->assertStatus(400);
        }
        $this->assertSame(0, RefreshToken::query()->whereIn('access_token_id', $this->tokenIds($user, $client))->where('revoked', false)->count());
        // The pending code too, so it can no longer be exchanged.
        $this->assertSame(0, Passport::authCode()->newQuery()->where('user_id', $user->id)->where('client_id', $client)->where('revoked', false)->count());
        $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client, 'redirect_uri' => self::REDIRECT, 'code' => $pendingCode,
            'code_verifier' => 'unused-verifier-unused-verifier-unused-verifier', 'resource' => OAuthResourceIndicator::resource('rest'),
        ], ['Accept' => 'application/json'])->assertStatus(400);

        foreach ([$elsewhere, $someoneElse] as $kept) {
            $this->app['auth']->forgetGuards();
            $this->withToken($kept['access_token'])->getJson('/api/v1/context')->assertOk();
        }
    }

    public function test_a_personal_token_disconnects_only_itself(): void
    {
        $user = User::factory()->create();
        $credentials = app(ApiCredentialService::class);
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $leaving = $credentials->issueToken($principal, 'Leaving connector', [AgentApiScopes::IDENTITY_READ], 'P30D');
        $staying = $credentials->issueToken($principal, 'Staying connector', [AgentApiScopes::IDENTITY_READ], 'P30D');

        $this->withToken($leaving['token'])->deleteJson('/api/v1/connection')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($leaving['token'])->getJson('/api/v1/context')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($staying['token'])->getJson('/api/v1/context')->assertOk();
    }

    public function test_disconnecting_takes_a_rest_credential(): void
    {
        $this->deleteJson('/api/v1/connection')->assertUnauthorized();

        // An MCP connection disconnects itself at connections.revoke instead.
        $user = User::factory()->create();
        $mcp = $this->issueAgentTokens($user, 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::IDENTITY_READ]);
        $this->withToken($mcp['access_token'])->deleteJson('/api/v1/connection')->assertUnauthorized();
        $this->assertFalse((bool) Passport::token()->newQuery()->where('user_id', $user->id)->sole()->revoked);
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

    /** @return list<string> */
    private function tokenIds(User $user, string $client): array
    {
        return array_values(Passport::token()->newQuery()->where('user_id', $user->id)->where('client_id', $client)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
    }
}
