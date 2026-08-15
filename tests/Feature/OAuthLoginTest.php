<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]]);
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
