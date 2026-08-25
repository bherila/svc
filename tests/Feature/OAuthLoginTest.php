<?php

namespace Tests\Feature;

use App\Http\Controllers\OAuthLoginController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['bherila-auth.oauth_client' => [
            'provider' => 'test-provider',
            'base_url' => 'https://identity.example.test',
            'client_id' => 'svc-client',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://svc.example.test/oauth/callback',
            'scope' => 'identity:read',
            'authorize_path' => '/oauth/authorize',
            'token_path' => '/oauth/token',
            'identity_path' => '/api/oauth/user',
            // This application's own config does not restate `oauth_client`, so it inherits
            // the package's paths. This setUp *does* restate it, and `mergeConfigFrom` is a
            // shallow merge, so an omitted key here would be blank rather than inherited and
            // `endSessionUrl()` would abort 503.
            'end_session_path' => '/oauth/end-session',
        ]]);
    }

    public function test_signing_out_ends_the_session_at_the_provider(): void
    {
        $user = User::factory()->create();

        // Ending only the local session leaves the provider still recognising this person,
        // so the next protected page hands them straight back with no prompt.
        $this->actingAs($user)
            ->post('/logout')
            ->assertRedirect(
                'https://identity.example.test/oauth/end-session?client_id=svc-client'
                    .'&post_logout_redirect_uri='.urlencode(url('/')),
            );

        $this->assertGuest();
    }

    public function test_signing_out_stays_local_when_no_provider_is_configured(): void
    {
        config(['bherila-auth.oauth_client.client_id' => '']);

        $user = User::factory()->create();

        // Handing off to a provider that was never configured aborts 503, which would make
        // signing out fail outright — worse than signing out only locally.
        $this->actingAs($user)->post('/logout')->assertRedirect(route('home'));
        $this->assertGuest();
    }

    public function test_the_application_list_is_shared_with_the_page_only_when_signed_in(): void
    {
        $user = User::factory()->create();
        $apps = [['key' => 'phr', 'name' => 'Health', 'url' => 'https://phr.example.test']];

        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put(OAuthLoginController::APPLICATIONS_SESSION_KEY, $apps);
        $request->setUserResolver(fn () => $user);

        $shared = app(HandleInertiaRequests::class)->share($request);
        $this->assertSame($apps, $shared['applications']);

        // Which applications exist is not public, so an anonymous request is told nothing.
        $request->setUserResolver(fn () => null);
        $this->assertSame([], app(HandleInertiaRequests::class)->share($request)['applications']);
    }

    public function test_redirect_uses_state_and_pkce(): void
    {
        $response = $this->get('/oauth/redirect');

        $response->assertRedirectContains('https://identity.example.test/oauth/authorize?');
        $response->assertSessionHas('oauth.login.state');
        $response->assertSessionHas('oauth.login.code_verifier');

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('identity:read', $query['scope']);
        $this->assertSame(session('oauth.login.state'), $query['state']);
    }

    public function test_inertia_redirect_uses_an_external_location_response(): void
    {
        $version = app(HandleInertiaRequests::class)->version(Request::create('/oauth/redirect'));

        $response = $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version,
        ])->get('/oauth/redirect');

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location');
        $response->assertSessionHas('oauth.login.state');
        $response->assertSessionHas('oauth.login.code_verifier');

        $location = (string) $response->headers->get('X-Inertia-Location');

        $this->assertStringStartsWith('https://identity.example.test/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(session('oauth.login.state'), $query['state']);
    }

    public function test_protected_pages_redirect_guests_to_oauth_login(): void
    {
        $this->get('/app')->assertRedirect('/login');
    }

    public function test_callback_provisions_subject_bound_user(): void
    {
        Http::fake([
            'https://identity.example.test/oauth/token' => Http::response(['access_token' => 'access-token']),
            'https://identity.example.test/api/oauth/user' => Http::response([
                'sub' => 'person-123',
                'name' => 'Synthetic User',
                'email' => 'SYNTHETIC@example.test',
            ]),
        ]);

        $response = $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ])->get('/oauth/callback?state=expected-state&code=authorization-code');

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'synthetic@example.test',
            'oauth_provider' => 'test-provider',
            'oauth_subject' => 'person-123',
        ]);

        Http::assertSentCount(2);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ])->get('/oauth/callback?state=wrong-state&code=authorization-code')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_callback_does_not_bind_an_existing_email_to_a_new_subject(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        Http::fake([
            'https://identity.example.test/oauth/token' => Http::response(['access_token' => 'access-token']),
            'https://identity.example.test/api/oauth/user' => Http::response([
                'sub' => 'attacker-subject',
                'name' => 'Different Identity',
                'email' => 'existing@example.test',
            ]),
        ]);

        $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ])->get('/oauth/callback?state=expected-state&code=authorization-code')->assertConflict();

        $this->assertDatabaseMissing('users', ['oauth_subject' => 'attacker-subject']);
        $this->assertGuest();
    }
}
