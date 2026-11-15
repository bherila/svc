<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class BrowserSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_hash_session_guard_rejects_a_stale_hash_even_at_the_current_credential_generation(): void
    {
        Route::middleware('web')->get('/synthetic-browser-session', static fn () => response()->json(['ok' => true]));
        $user = User::factory()->create();
        $this->actingAs($user)->withToken('synthetic-invalid-bearer')->withSession([
            'password_hash_web' => str_repeat('0', 64),
        ])->getJson('/synthetic-browser-session')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_a_downstream_bearer_guard_switch_cannot_rewrite_the_browser_password_hash(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.public_key' => $details['key']]);
        $browser = User::factory()->create(['password' => 'synthetic-browser-password']);
        $bearer = User::factory()->create(['password' => 'synthetic-bearer-password']);
        $expected = auth('web')->hashPasswordForCookie($browser->getAuthPassword());
        Route::middleware('web')->get('/synthetic-guard-switch', static function () use ($bearer) {
            Auth::guard('api')->setUser($bearer);
            Auth::shouldUse('api');

            return response()->json(['subject' => (string) auth()->id()]);
        });

        $this->actingAs($browser)->getJson('/synthetic-guard-switch')->assertOk()
            ->assertJsonPath('subject', (string) $bearer->id);
        $this->assertSame($expected, session('password_hash_web'));
        $this->assertFalse(session()->has('password_hash_api'));
        $this->assertSame('api', Auth::getDefaultDriver());
    }

    public function test_a_downstream_request_principal_switch_cannot_poison_the_browser_password_hash(): void
    {
        $browser = User::factory()->create(['password' => 'synthetic-browser-password']);
        $api = User::factory()->create(['password' => 'synthetic-api-password']);
        $expected = auth('web')->hashPasswordForCookie($browser->getAuthPassword());
        Route::middleware('web')->get('/synthetic-principal-switch', static function (Request $request) use ($api) {
            $request->setUserResolver(static fn () => $api);

            return response()->json(['subject' => (string) $request->user()->id]);
        });

        $this->actingAs($browser)->getJson('/synthetic-principal-switch')->assertOk()
            ->assertJsonPath('subject', (string) $api->id);
        $this->assertSame($expected, session('password_hash_web'));
        $this->assertAuthenticatedAs($browser);
    }
}
