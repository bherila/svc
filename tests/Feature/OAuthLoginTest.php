<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use BWH\Auth\OAuth\ProviderApplications;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_an_inertia_sign_out_navigates_the_browser_to_the_provider(): void
    {
        $user = User::factory()->create();

        // The account menu posts through Inertia, an XHR, which cannot follow a
        // redirect to another origin: a plain 302 here ended the session while
        // the page stayed where it was. A 409 with X-Inertia-Location is what
        // makes the Inertia client do a full navigation.
        $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->post('/logout')
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://identity.example.test/oauth/end-session?client_id=svc-client'
                    .'&post_logout_redirect_uri='.urlencode(url('/')),
            )
            ->assertHeaderMissing('Location');

        $this->assertGuest();
    }

    public function test_an_inertia_sign_out_with_no_provider_navigates_home(): void
    {
        config(['bherila-auth.oauth_client.client_id' => '']);

        $this->actingAs(User::factory()->create())
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/logout')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('home'));

        $this->assertGuest();
    }

    public function test_an_expired_session_can_still_sign_out_at_the_provider(): void
    {
        $endSession = 'https://identity.example.test/oauth/end-session?client_id=svc-client'
            .'&post_logout_redirect_uri='.urlencode(url('/'));

        // A page left open past its session still shows the menu. Behind `auth`
        // the click was sent to sign in, which the provider answers by signing
        // the person straight back in - the opposite of what they asked for.
        $this->post('/logout')->assertRedirect($endSession);
        $this->withHeaders(['X-Inertia' => 'true'])
            ->post('/logout')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', $endSession);

        $this->assertGuest();
    }

    public function test_an_expired_session_sign_out_passes_request_forgery_checks_from_this_origin(): void
    {
        // Laravel skips request-forgery checks under PHPUnit, so the test above
        // cannot see them. Enforce them here: an expired session has no token
        // left to send, and what lets its sign-out through is the browser's
        // Sec-Fetch-Site header, which a cross-site form cannot forge.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $this->withHeaders(['X-Inertia' => 'true', 'Sec-Fetch-Site' => 'same-origin'])
            ->post('/logout')
            ->assertStatus(409)
            ->assertHeader(
                'X-Inertia-Location',
                'https://identity.example.test/oauth/end-session?client_id=svc-client'
                    .'&post_logout_redirect_uri='.urlencode(url('/')),
            );

        $this->withHeaders(['X-Inertia' => 'true', 'Sec-Fetch-Site' => 'cross-site'])
            ->post('/logout')
            ->assertStatus(419);
    }

    public function test_provider_identity_enforcement_skips_the_sign_out_route(): void
    {
        // Enforcement skips routes by name, so renaming the route would quietly
        // put sign-out behind a provider check that fails during an outage.
        $route = app('router')->getRoutes()->getByName('logout');

        $this->assertNotNull($route);
        $this->assertSame('logout', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('logout', config('bherila-auth.provider_identity.except_routes'));
        $this->assertNotContains('auth', $route->gatherMiddleware());
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
        $request->session()->put(ProviderApplications::SESSION_KEY, $apps);
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

    public function test_a_same_site_return_page_is_kept_through_sign_in_and_a_foreign_one_is_dropped(): void
    {
        $this->get('/oauth/redirect?redirect=/app/somewhere')->assertSessionHas('url.intended');
        $this->flushSession();
        $this->get('/oauth/redirect?redirect=https://evil.example.test/')->assertSessionMissing('url.intended');
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

        $response->assertRedirect(route('workspaces.index'));
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

    public function test_callback_signs_in_when_the_provider_verified_the_address(): void
    {
        $this->signInWithEmailVerified('person-verified', 'verified@example.test', true)
            ->assertRedirect(route('workspaces.index'));

        $this->assertAuthenticated();
        $this->assertNotNull(User::query()->where('oauth_subject', 'person-verified')->sole()->email_verified_at);
    }

    public function test_callback_accepts_a_string_true_email_verified_claim(): void
    {
        $this->signInWithEmailVerified('person-string-true', 'string-true@example.test', 'TRUE')
            ->assertRedirect(route('workspaces.index'));

        $this->assertAuthenticated();
    }

    public function test_callback_keeps_trusting_the_provider_when_email_verified_is_absent(): void
    {
        $user = User::factory()->create(['email' => 'old-absent@example.test']);
        $user->forceFill(['oauth_provider' => 'test-provider', 'oauth_subject' => 'person-absent'])->save();

        // The provider this deployment uses sends no `email_verified` claim. Its address is
        // trusted as SECURITY.md records, and a returning sign-in still refreshes it.
        $this->signInWithEmailVerified('person-absent', 'new-absent@example.test', null)
            ->assertRedirect(route('workspaces.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('new-absent@example.test', $user->refresh()->email);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unverifiedClaims(): iterable
    {
        yield 'false' => [false];
        yield 'string false' => ['false'];
        yield 'string zero' => ['0'];
        yield 'empty string' => [''];
        yield 'integer zero' => [0];
        yield 'null' => [null];
    }

    #[DataProvider('unverifiedClaims')]
    public function test_callback_does_not_provision_an_account_from_an_unverified_address(mixed $claim): void
    {
        $this->signInWithEmailVerified('person-unverified', 'unverified@example.test', $claim, present: true)
            ->assertForbidden()
            ->assertSee('has not verified the email address');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['oauth_subject' => 'person-unverified']);
        $this->assertDatabaseMissing('users', ['email' => 'unverified@example.test']);
    }

    public function test_callback_does_not_update_a_linked_account_from_an_unverified_address(): void
    {
        $user = User::factory()->create(['email' => 'kept@example.test', 'name' => 'Kept Name']);
        $user->forceFill(['oauth_provider' => 'test-provider', 'oauth_subject' => 'person-changed'])->save();

        // An address changed at the provider without proof of the mailbox must not reach the
        // account, where it would claim every grant bound to that address.
        $this->signInWithEmailVerified('person-changed', 'claimed@example.test', false)
            ->assertForbidden();

        $this->assertGuest();
        $user->refresh();
        $this->assertSame('kept@example.test', $user->email);
        $this->assertSame('Kept Name', $user->name);
    }

    public function test_an_unverified_refusal_answers_json_clients_with_the_reason(): void
    {
        $this->signInWithEmailVerified('person-json', 'json@example.test', 'false', json: true)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your identity provider has not verified the email address on your account. Verify it with the provider, then sign in again.');
    }

    /**
     * Complete the callback with an identity response carrying `email_verified` as given; a
     * null claim is left out of the response unless `present` says it is sent as null.
     */
    private function signInWithEmailVerified(string $subject, string $email, mixed $claim, bool $present = false, bool $json = false): TestResponse
    {
        $identity = ['sub' => $subject, 'name' => 'Synthetic '.$subject, 'email' => $email];
        if ($claim !== null || $present) {
            $identity['email_verified'] = $claim;
        }

        Http::fake([
            'https://identity.example.test/oauth/token' => Http::response(['access_token' => 'access-token']),
            'https://identity.example.test/api/oauth/user' => Http::response($identity),
        ]);

        $request = $this->withSession([
            'oauth.login.state' => 'expected-state',
            'oauth.login.code_verifier' => 'expected-verifier',
        ]);

        return $json
            ? $request->getJson('/oauth/callback?state=expected-state&code=authorization-code')
            : $request->get('/oauth/callback?state=expected-state&code=authorization-code');
    }
}
