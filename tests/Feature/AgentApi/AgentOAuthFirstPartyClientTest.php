<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\Auth\OAuthClient;
use BWH\Auth\OAuth\Credentials\ApiCredentialService;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Authorization-server hardening for first-party connections (F2), through
 * Passport's real routes and controllers:
 *
 * - `client_credentials` is refused at the token endpoint whatever the client,
 *   so no client secret alone ever mints a credential that acts for nobody;
 * - an operator registers a first-party application as a confidential,
 *   ownerless, authorization-code client with a fixed scope ceiling;
 * - only a client on the configured allow-list skips the consent screen, and
 *   only while it is still such a client;
 * - every authorization response names the issuer (RFC 9207), so a client of
 *   several authorization servers can tell which one answered.
 */
final class AgentOAuthFirstPartyClientTest extends TestCase
{
    use RefreshDatabase;

    private const string REDIRECT = 'https://finance.example.test/oauth/svc/callback';

    private const array SCOPES = [AgentApiScopes::IDENTITY_READ, AgentApiScopes::FINANCE_READ, AgentApiScopes::FINANCE_RECONCILE];

    public function test_passport_resolves_clients_through_the_first_party_model(): void
    {
        $this->assertSame(OAuthClient::class, Passport::clientModel());
    }

    public function test_client_credentials_is_refused_even_for_a_client_passport_would_serve(): void
    {
        // A confidential, ownerless client registered for exactly this grant:
        // Passport itself would issue it a token.
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('Synthetic machine client');
        $this->assertTrue($client->hasGrantType('client_credentials'));

        $body = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $client->getKey(),
            'client_secret' => $client->plainSecret,
            'scope' => AgentApiScopes::BILLING_READ,
        ], ['Accept' => 'application/json']);
        $this->assertUnsupportedGrant($body);

        $basic = $this->post('/oauth/token', ['grant_type' => 'client_credentials'], [
            'Accept' => 'application/json',
            'Authorization' => 'Basic '.base64_encode($client->getKey().':'.$client->plainSecret),
        ]);
        $this->assertUnsupportedGrant($basic);

        $this->assertDatabaseCount('oauth_access_tokens', 0);
    }

    public function test_the_registration_command_creates_a_confidential_authorization_code_client(): void
    {
        [$client, $secret, $output] = $this->register('Synthetic Finance');

        $this->assertNull($client->user_id ?? $client->getAttribute('owner_id'));
        $this->assertTrue($client->firstParty());
        $this->assertTrue($client->confidential());
        $this->assertSame(['authorization_code', 'refresh_token'], $client->getAttribute('grant_types'));
        $this->assertFalse($client->hasGrantType('client_credentials'));
        $this->assertSame([self::REDIRECT], $client->getAttribute('redirect_uris'));
        $this->assertSame(self::SCOPES, json_decode((string) $client->getRawOriginal('scopes'), true));
        $this->assertNull($client->getAttribute('dynamically_registered_at'));
        // Shown once; only its hash is kept.
        $this->assertNotSame($secret, $client->getRawOriginal('secret'));
        $this->assertTrue(password_verify($secret, (string) $client->getRawOriginal('secret')));
        $this->assertStringContainsString('AGENT_API_FIRST_PARTY_OAUTH_CLIENTS', $output);

        // And it is still refused the client credentials grant.
        $this->assertUnsupportedGrant($this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->getKey(), 'client_secret' => $secret,
        ], ['Accept' => 'application/json']));
    }

    public function test_the_registration_command_refuses_unsafe_or_ambiguous_registrations(): void
    {
        $refused = [
            [['--redirect-uri' => [self::REDIRECT], '--scope' => [AgentApiScopes::MCP_USE]], 'not a REST operation scope'],
            [['--redirect-uri' => [self::REDIRECT], '--scope' => ['admin:everything']], 'not a REST operation scope'],
            [['--redirect-uri' => [self::REDIRECT], '--scope' => []], 'at least one --scope'],
            [['--redirect-uri' => [], '--scope' => [AgentApiScopes::FINANCE_READ]], 'at least one --redirect-uri'],
            [['--redirect-uri' => ['http://finance.example.test/callback'], '--scope' => [AgentApiScopes::FINANCE_READ]], 'https'],
            [['--redirect-uri' => ['https://finance.example.test/callback#fragment'], '--scope' => [AgentApiScopes::FINANCE_READ]], 'https'],
        ];
        foreach ($refused as [$options, $message]) {
            $this->assertSame(2, Artisan::call('svc:auth:register-first-party-client', ['--apply' => true, 'name' => 'Refused', ...$options]));
            $this->assertStringContainsString($message, Artisan::output());
        }
        $this->assertSame(0, Passport::client()->newQuery()->count());

        $this->register('Synthetic Finance');
        $this->assertSame(1, Artisan::call('svc:auth:register-first-party-client', ['--apply' => true,
            'name' => 'Synthetic Finance', '--redirect-uri' => [self::REDIRECT], '--scope' => [AgentApiScopes::FINANCE_READ],
        ]));
        $this->assertStringContainsString('already exists', Artisan::output());
        $this->assertSame(1, Passport::client()->newQuery()->count());
    }

    public function test_an_allow_listed_first_party_client_connects_without_a_consent_screen(): void
    {
        [$client, $secret] = $this->register('Synthetic Finance');
        config(['agent_api.first_party_oauth_clients' => [strtoupper((string) $client->getKey())]]);
        $user = User::factory()->create();
        $verifier = str_repeat('v', 64);

        $authorize = $this->actingAs($user, 'web')->get($this->authorizeUrl($client, $verifier));
        $query = $this->redirectQuery($authorize);
        $this->assertSame('synthetic-state', $query['state'] ?? null);
        $this->assertSame(OAuthResourceIndicator::issuer(), $query['iss'] ?? null);
        $this->assertIsString($query['code'] ?? null);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->getKey(),
            'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->app['auth']->forgetGuards();
        $this->withToken((string) $tokens['access_token'])->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id);

        // The scope ceiling still holds without a prompt.
        $this->app['auth']->forgetGuards();
        $beyond = $this->actingAs($user, 'web')->get($this->authorizeUrl($client, $verifier, [...self::SCOPES, AgentApiScopes::BILLING_WRITE]));
        $this->assertSame('invalid_scope', $this->redirectQuery($beyond)['error'] ?? null);
    }

    public function test_every_other_client_still_sees_the_consent_screen(): void
    {
        $user = User::factory()->create();
        $verifier = str_repeat('v', 64);

        // Registered as first-party but not (or no longer) allow-listed.
        [$unlisted] = $this->register('Synthetic Finance');
        $this->actingAs($user, 'web')->get($this->authorizeUrl($unlisted, $verifier))->assertOk();

        // Allow-listed, but registered by a person: an owned client is never first-party.
        $owned = app(ApiCredentialService::class)->registerApp(AgentPrincipal::query()->findOrFail($user->id), 'Owned app', [self::REDIRECT], true, self::SCOPES)['client'];
        // Allow-listed, but registered dynamically.
        $dynamic = Passport::client()->newQuery()->whereKey((string) $this->postJson('/oauth/register', [
            'client_name' => 'Dynamic client', 'redirect_uris' => ['http://127.0.0.1:3210/callback'], 'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id'))->firstOrFail();
        // Allow-listed and first-party, but revoked.
        [$revoked] = $this->register('Revoked Finance');
        $revoked->forceFill(['revoked' => true])->save();
        config(['agent_api.first_party_oauth_clients' => [(string) $owned->getKey(), (string) $dynamic->getKey(), (string) $revoked->getKey()]]);

        $this->actingAs($user, 'web')->get($this->authorizeUrl($owned, $verifier))->assertOk();
        $this->actingAs($user, 'web')->get($this->authorizeUrl($dynamic, $verifier, [AgentApiScopes::IDENTITY_READ], 'http://127.0.0.1:3210/callback'))->assertOk();
        $this->assertFalse(OAuthClient::query()->whereKey($revoked->getKey())->firstOrFail()->skipsAuthorization($user, []));
        $this->assertFalse(OAuthClient::query()->whereKey($unlisted->getKey())->firstOrFail()->skipsAuthorization($user, []));
    }

    public function test_every_authorization_response_names_the_issuer(): void
    {
        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('authorization_response_iss_parameter_supported', true);
        [$client] = $this->register('Synthetic Finance');
        $user = User::factory()->create();
        $verifier = str_repeat('v', 64);

        // Approved on the consent screen.
        $this->actingAs($user, 'web')->get($this->authorizeUrl($client, $verifier))->assertOk();
        $approved = $this->redirectQuery($this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')]));
        $this->assertIsString($approved['code'] ?? null);
        $this->assertSame(OAuthResourceIndicator::issuer(), $approved['iss'] ?? null);

        // Denied on the consent screen.
        $this->actingAs($user, 'web')->get($this->authorizeUrl($client, $verifier))->assertOk();
        $denied = $this->redirectQuery($this->delete('/oauth/authorize', ['auth_token' => (string) session('authToken')]));
        $this->assertSame('access_denied', $denied['error'] ?? null);
        $this->assertSame(OAuthResourceIndicator::issuer(), $denied['iss'] ?? null);

        // Refused before consent.
        $refused = $this->redirectQuery($this->actingAs($user, 'web')->get($this->authorizeUrl($client, $verifier, ['admin:everything'])));
        $this->assertSame('invalid_scope', $refused['error'] ?? null);
        $this->assertSame(OAuthResourceIndicator::issuer(), $refused['iss'] ?? null);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<int|string, mixed>
     */
    private function redirectQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function authorizeUrl(Client $client, string $verifier, array $scopes = self::SCOPES, string $redirect = self::REDIRECT): string
    {
        return '/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'synthetic-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource('rest'),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{0: Client, 1: string, 2: string} */
    private function register(string $name): array
    {
        $this->assertSame(0, Artisan::call('svc:auth:register-first-party-client', ['--apply' => true,
            'name' => $name,
            '--redirect-uri' => [self::REDIRECT],
            '--scope' => self::SCOPES,
        ]));
        $output = Artisan::output();
        $line = collect(explode("\n", $output))->first(static fn (string $line): bool => str_starts_with($line, '{'));
        $registered = json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);
        $client = Passport::client()->newQuery()->whereKey($registered['client_id'])->firstOrFail();
        $this->assertSame($name, $registered['name']);

        return [$client, (string) $registered['client_secret'], $output];
    }

    /** @param  TestResponse<Response>  $response */
    private function assertUnsupportedGrant(TestResponse $response): void
    {
        $response->assertStatus(400)
            ->assertJsonPath('error', 'unsupported_grant_type')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
