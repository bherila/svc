<?php

namespace Tests\Feature\Auth;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use BWH\Auth\OAuth\Session\ProviderBindingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Provider identity enforcement, switched on: every credential of a person
 * bound to the identity provider stops working once the provider says that
 * identity ended, and an unavailable provider is a retryable refusal rather
 * than a pass. Accounts with no binding are left alone.
 */
final class ProviderIdentityEnforcementTest extends TestCase
{
    use CallsMcp;
    use FakesProviderIdentity;
    use RefreshDatabase;

    private const string REDIRECT = 'http://127.0.0.1:3210/callback';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderIdentity();
        // Workspace creation stands in for any agent write.
        config(['agent_api.writes_enabled' => true, 'agent_api.workspace_writes_enabled' => true]);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
    }

    public function test_enforcement_is_off_unless_the_operator_enables_it(): void
    {
        $this->refreshApplication();

        $this->assertFalse(config('bherila-auth.provider_identity.enabled'));
        $this->assertFalse(ProviderIdentityTokens::enabled());
        $this->assertSame('login', config('bherila-auth.provider_identity.expired_redirect_route'));
        $this->assertSame(['logout'], config('bherila-auth.provider_identity.except_routes'));
        $this->assertSame('api', config('bherila-auth.provider_identity.bearer_guard'));
    }

    public function test_sign_in_records_the_login_generation_and_later_checks_use_it(): void
    {
        $this->boundUser('person-login');
        $this->providerIdentity('person-login', 7);

        $this->signInThroughProvider('person-login', 7)->assertRedirect();
        $this->assertAuthenticated();
        $this->get('/app')->assertOk();
        $this->assertSame(0, $this->providerStatusRequests, 'A fresh login needs no status request');

        $this->travel(6)->minutes();
        $this->get('/app')->assertOk();
        $this->assertSame(1, $this->providerStatusRequests);

        // A reset at the provider is a newer generation: it ends the older login.
        $this->providerIdentity('person-login', 8);
        $this->travel(6)->minutes();
        $this->get('/app')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sign_in_without_a_login_generation_is_undone_with_a_retryable_503(): void
    {
        $this->boundUser('person-old-provider');

        $this->signInThroughProvider('person-old-provider', null)
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30');
        $this->assertGuest();
    }

    public function test_sign_in_without_a_login_generation_still_works_while_enforcement_is_off(): void
    {
        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->boundUser('person-before-enforcement');

        $this->signInThroughProvider('person-before-enforcement', null)->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_a_web_session_ends_when_the_identity_is_disabled(): void
    {
        $this->boundUser('person-disabled');
        $this->providerIdentity('person-disabled', 1);
        $this->signInThroughProvider('person-disabled', 1);
        $this->get('/app')->assertOk();

        $this->disableProviderIdentity('person-disabled');
        // Within the freshness window a read is answered from the last check...
        $this->get('/app')->assertOk();
        // ...but an unsafe method always asks.
        $this->sessionApi()->withHeader('Idempotency-Key', 'workspace-after-disable')->postJson('/api/v1/workspaces', ['name' => 'Synthetic after disable'])->assertUnauthorized();
        $this->assertGuest('web');
        $this->assertDatabaseMissing('workspaces', ['name' => 'Synthetic after disable']);
    }

    public function test_a_json_web_request_gets_a_401_when_the_identity_ended(): void
    {
        $this->boundUser('person-json');
        $this->providerIdentity('person-json', 1);
        $this->signInThroughProvider('person-json', 1);

        $this->disableProviderIdentity('person-json');
        $this->travel(6)->minutes();
        $this->getJson('/search?q=x')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your sign-in has ended. Sign in again.');
        $this->assertGuest();
    }

    public function test_a_provider_outage_answers_503_and_keeps_the_session_and_sign_out(): void
    {
        $this->boundUser('person-outage');
        $this->providerIdentity('person-outage', 1);
        $this->signInThroughProvider('person-outage', 1);

        $this->takeProviderOffline();
        $this->travel(6)->minutes();
        $this->get('/app')->assertStatus(503)->assertHeader('Retry-After', '30');
        $this->assertAuthenticated();

        $this->takeProviderOffline(false);
        $this->get('/app')->assertOk();

        // Signing out stays reachable while the provider is down.
        $this->takeProviderOffline();
        $this->travel(6)->minutes();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_the_first_party_session_api_is_refused_when_the_identity_ended(): void
    {
        $this->boundUser('person-session-api');
        $this->providerIdentity('person-session-api', 1);
        $this->signInThroughProvider('person-session-api', 1);

        $this->sessionApi()->getJson('/api/v1/context')->assertOk();

        $this->takeProviderOffline();
        $this->travel(6)->minutes();
        $this->sessionApi()->getJson('/api/v1/context')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('message', 'Sign-in verification is temporarily unavailable. Please retry.');
        $this->assertAuthenticated('web');

        $this->takeProviderOffline(false);
        $this->sessionApi()->getJson('/api/v1/context')->assertOk();

        // A write asks freshly, inside the freshness window.
        $this->disableProviderIdentity('person-session-api');
        $requests = $this->providerStatusRequests;
        $this->sessionApi()->withHeader('Idempotency-Key', 'session-after-disable')
            ->postJson('/api/v1/workspaces', ['name' => 'Synthetic session write'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your sign-in has ended. Sign in again.');
        $this->assertSame($requests + 1, $this->providerStatusRequests);
        $this->assertGuest('web');
        $this->assertDatabaseMissing('workspaces', ['name' => 'Synthetic session write']);
    }

    public function test_a_passport_bearer_is_refused_when_the_identity_ended_and_a_write_checks_freshly(): void
    {
        $user = $this->boundUser('person-bearer');
        $this->providerIdentity('person-bearer', 3);
        $this->signInThroughProvider('person-bearer', 3);
        $token = $this->oauthToken($user, 'rest', [AgentApiScopes::IDENTITY_READ, AgentApiScopes::WORKSPACES_CREATE])['access_token'];
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'provider_subject' => 'person-bearer', 'provider_generation' => 3]);

        $this->bearer($token)->getJson('/api/v1/context')->assertOk();
        $requests = $this->providerStatusRequests;
        $this->bearer($token)->withHeader('Idempotency-Key', 'bearer-active')
            ->postJson('/api/v1/workspaces', ['name' => 'Synthetic bearer write'])
            ->assertCreated();
        $this->assertSame($requests + 1, $this->providerStatusRequests, 'A write asks the provider once, freshly');

        $this->disableProviderIdentity('person-bearer');
        // Reads ride the shared observation until it ages out...
        $this->bearer($token)->getJson('/api/v1/context')->assertOk();
        // ...a write does not wait for that, and refreshes it for everyone.
        $this->bearer($token)->withHeader('Idempotency-Key', 'bearer-disabled')
            ->postJson('/api/v1/workspaces', ['name' => 'Synthetic refused write'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate');
        $this->assertDatabaseMissing('workspaces', ['name' => 'Synthetic refused write']);
        $this->bearer($token)->getJson('/api/v1/context')->assertUnauthorized();
    }

    public function test_a_passport_bearer_gets_a_retryable_503_during_an_outage(): void
    {
        $user = $this->boundUser('person-bearer-outage');
        $this->providerIdentity('person-bearer-outage', 1);
        $this->signInThroughProvider('person-bearer-outage', 1);
        $token = $this->oauthToken($user, 'rest', [AgentApiScopes::IDENTITY_READ])['access_token'];

        $this->takeProviderOffline();
        $this->travel(6)->minutes();
        $this->bearer($token)->getJson('/api/v1/context')
            ->assertStatus(503)
            ->assertJsonPath('error', 'temporarily_unavailable');

        $this->takeProviderOffline(false);
        $this->bearer($token)->getJson('/api/v1/context')->assertOk();
    }

    public function test_credentials_issued_before_enforcement_without_a_stamp_are_refused_refresh_included(): void
    {
        config(['bherila-auth.provider_identity.enabled' => false]);
        $user = $this->boundUser('person-legacy');
        $this->providerIdentity('person-legacy', 1);
        // Signed in before the login callback recorded a generation.
        $this->actingAs($user, 'web');
        $tokens = $this->oauthToken($user, 'rest', [AgentApiScopes::IDENTITY_READ]);
        $this->assertDatabaseHas('oauth_access_tokens', ['user_id' => $user->id, 'provider_subject' => null]);
        $this->assertDatabaseHas('oauth_refresh_tokens', ['provider_user_id' => (string) $user->id, 'provider_subject' => null]);

        config(['bherila-auth.provider_identity.enabled' => true]);
        Auth::guard('web')->logout();
        $this->bearer($tokens['access_token'])->getJson('/api/v1/context')->assertUnauthorized();

        Auth::forgetGuards();
        $this->post('/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $tokens['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ], ['Accept' => 'application/json'])
            ->assertBadRequest()
            ->assertJsonPath('error', 'invalid_grant');
    }

    public function test_an_mcp_write_tool_checks_the_identity_once_per_call(): void
    {
        $user = $this->boundUser('person-mcp');
        $this->providerIdentity('person-mcp', 2);
        $this->signInThroughProvider('person-mcp', 2);
        $token = $this->oauthToken($user, 'mcp', [AgentApiScopes::MCP_USE, AgentApiScopes::WORKSPACES_CREATE])['access_token'];

        Auth::forgetGuards();
        $this->withToken($token);
        $session = $this->initialize();
        $requests = $this->providerStatusRequests;
        $created = $this->callTool($session, 'workspaces.create', ['name' => 'Synthetic MCP tenant', 'idempotency_key' => 'mcp-identity-write']);
        $this->assertFalse($created['result']['isError'] ?? false, json_encode($created, JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('workspaces', ['name' => 'Synthetic MCP tenant']);
        // The tool asked once; the REST route it dispatched to did not ask again.
        $this->assertSame($requests + 1, $this->providerStatusRequests);

        $this->disableProviderIdentity('person-mcp');
        Auth::forgetGuards();
        $refused = $this->callTool($session, 'workspaces.create', ['name' => 'Synthetic refused tenant', 'idempotency_key' => 'mcp-identity-refused']);
        $this->assertTrue($refused['result']['isError'] ?? false);
        $this->assertStringContainsString('has ended', (string) ($refused['result']['content'][0]['text'] ?? ''));
        $this->assertDatabaseMissing('workspaces', ['name' => 'Synthetic refused tenant']);

        Auth::forgetGuards();
        $this->mcp($this->initializeMessage())->assertUnauthorized();
    }

    public function test_sanctum_tokens_are_stamped_at_issue_and_follow_the_identity(): void
    {
        $user = $this->boundUser('person-finance');
        $workspace = Workspace::query()->create(['name' => 'Synthetic finance', 'slug' => 'synthetic-finance-'.Str::lower(Str::random(6))]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoice-payments";
        $this->providerIdentity('person-finance', 4);

        $plain = $this->issueSanctumToken($user);
        $stored = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($stored);
        $this->assertSame('person-finance', $stored->provider_subject);
        $this->assertSame(4, (int) $stored->provider_generation);

        $this->bearer($plain)->getJson($path)->assertOk();

        // Unstamped: issued before stamping existed.
        $legacy = $user->createToken('legacy', ['finance.read'], now()->addHour())->plainTextToken;
        $this->bearer($legacy)->getJson($path)->assertUnauthorized();

        // Another subject's stamp never stands in for this binding.
        $stored->forceFill(['provider_subject' => 'person-other'])->save();
        $this->bearer($plain)->getJson($path)->assertUnauthorized();
        $stored->forceFill(['provider_subject' => 'person-finance'])->save();
        $this->bearer($plain)->getJson($path)->assertOk();

        $this->takeProviderOffline();
        $this->travel(6)->minutes();
        $this->bearer($plain)->getJson($path)->assertStatus(503)->assertJsonPath('error', 'temporarily_unavailable');

        $this->takeProviderOffline(false);
        $this->disableProviderIdentity('person-finance');
        $this->travel(6)->minutes();
        $this->bearer($plain)->getJson($path)->assertUnauthorized();
    }

    public function test_sanctum_issue_is_refused_for_an_inactive_identity(): void
    {
        $user = $this->boundUser('person-finance-inactive');
        $this->disableProviderIdentity('person-finance-inactive');

        $this->assertSame(1, Artisan::call('svc:auth:issue-token', ['--apply' => true,
            'user_public_id' => $user->public_id,
            'name' => 'refused',
            'abilities' => ['finance.read'],
            '--expires-at' => now()->addDay()->toAtomString(),
        ]));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    public function test_unbound_accounts_are_left_alone_the_deploy_smoke_principal_included(): void
    {
        Artisan::call('svc:mcp:deploy-smoke-credentials', ['--apply' => true]);
        $issued = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        Auth::forgetGuards();
        $this->withToken($issued['authorized_token']);
        $session = $this->initialize();
        $context = $this->callTool($session, 'context.get', []);
        $this->assertFalse($context['result']['isError'] ?? false);

        $unbound = User::factory()->create();
        $finance = $unbound->createToken('unbound', ['finance.read'], now()->addHour())->plainTextToken;
        $workspace = Workspace::query()->create(['name' => 'Synthetic unbound', 'slug' => 'synthetic-unbound-'.Str::lower(Str::random(6))]);
        $workspace->memberships()->create(['user_id' => $unbound->id, 'role' => 'owner']);
        $this->bearer($finance)->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments")->assertOk();

        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_the_agent_principal_carries_the_binding_the_bearer_check_reads(): void
    {
        $user = $this->boundUser('person-principal');
        $principal = Auth::createUserProvider('agent-principals')?->retrieveById($user->id);

        $this->assertInstanceOf(AgentPrincipal::class, $principal);
        $binding = app(ProviderBindingResolver::class)->binding($principal);
        $this->assertNotNull($binding);
        $this->assertSame('person-principal', $binding->subject);
        $this->assertArrayNotHasKey('oauth_subject', $principal->toArray());
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    private function sessionApi(): static
    {
        return $this->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }

    private function issueSanctumToken(User $user): string
    {
        $this->assertSame(0, Artisan::call('svc:auth:issue-token', ['--apply' => true,
            'user_public_id' => $user->public_id,
            'name' => 'finance integration',
            'abilities' => ['finance.read'],
            '--expires-at' => now()->addDay()->toAtomString(),
        ]));
        $this->assertSame(1, preg_match('/Token \(shown once\): (\S+)/', Artisan::output(), $match));

        return $match[1];
    }

    /**
     * A self-registered client's authorization-code flow, approved in the
     * current browser session.
     *
     * @param  list<string>  $scopes
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    private function oauthToken(User $user, string $resource, array $scopes): array
    {
        $clientId = (string) $this->postJson('/oauth/register', [
            'client_name' => 'Enforcement client',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'enforcement-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource($resource),
        ], '', '&', PHP_QUERY_RFC3986))->assertOk();
        $approval = $this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null, (string) $approval->headers->get('Location'));

        $tokens = $this->exchange([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
            'resource' => OAuthResourceIndicator::resource($resource),
        ])->assertOk()->json();

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId];
    }

    /**
     * @param  array<string, string>  $parameters
     * @return TestResponse<Response>
     */
    private function exchange(array $parameters): TestResponse
    {
        return $this->post('/oauth/token', $parameters, ['Accept' => 'application/json']);
    }
}
