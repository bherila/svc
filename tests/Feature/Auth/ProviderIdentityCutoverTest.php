<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\AuthenticateFirstPartySession;
use BWH\Auth\OAuth\Server\ProviderIdentityTokens;
use BWH\Auth\OAuth\Session\ProviderIdentityEnforcement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Once svc records its provider identity cutover, enforcement stays on even if
 * BHERILA_AUTH_PROVIDER_IDENTITY_ENABLED is later false: on the web session, on the
 * first-party session API and on bearer credentials alike.
 */
#[CoversClass(AuthenticateFirstPartySession::class)]
final class ProviderIdentityCutoverTest extends TestCase
{
    use FakesProviderIdentity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeProviderIdentity();
    }

    public function test_the_cutover_table_is_migrated_and_nothing_is_recorded_by_default(): void
    {
        $this->assertTrue(Schema::hasTable(ProviderIdentityEnforcement::TABLE));

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->assertFalse(app(ProviderIdentityEnforcement::class)->enabled());
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertSuccessful();
    }

    public function test_a_recorded_cutover_keeps_every_path_enforced_with_the_setting_off(): void
    {
        $this->boundUser('person-cutover');
        $this->providerIdentity('person-cutover', 1);
        $this->signInThroughProvider('person-cutover', 1)->assertRedirect();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertSuccessful();

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->assertTrue(app(ProviderIdentityEnforcement::class)->enabled());
        $this->assertTrue(ProviderIdentityTokens::enabled(), 'Bearer credentials are held to the same lock.');

        // The provider ends the identity: the first-party session API still refuses it.
        $this->disableProviderIdentity('person-cutover');
        $this->travel(6)->minutes();
        $this->sessionApi()->getJson('/api/v1/context')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Your sign-in has ended. Sign in again.');
        $this->assertGuest('web');
    }

    public function test_the_deploy_check_fails_when_the_setting_disagrees_with_a_recorded_cutover(): void
    {
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'record'])->assertSuccessful();
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertSuccessful();

        config(['bherila-auth.provider_identity.enabled' => false]);
        $this->artisan('bherila-auth:provider-identity-cutover', ['action' => 'check'])->assertFailed();
    }

    private function sessionApi(): static
    {
        return $this->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }
}
