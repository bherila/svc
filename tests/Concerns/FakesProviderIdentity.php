<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A fake identity provider for provider identity enforcement tests: the sign-in
 * callback's token and identity endpoints, and the reconciliation status
 * endpoint, all on a reserved test host.
 *
 * Each person is a subject with a credential generation, which the test can
 * reset (a new generation), disable (inactive) or take offline (the status
 * endpoint answers 503). Status requests are counted, so a test can say how
 * many times the provider was asked.
 */
trait FakesProviderIdentity
{
    private const string PROVIDER = 'test-provider';

    private const string PROVIDER_URL = 'https://identity.example.test';

    /** @var array<string, int|null> subject => credential generation, null when inactive */
    private array $providerIdentities = [];

    private bool $providerOffline = false;

    private int $providerStatusRequests = 0;

    /** The provider's sign-in identity response for the next callback. */
    private ?array $providerLoginIdentity = null;

    protected function fakeProviderIdentity(bool $enforced = true): void
    {
        config([
            'bherila-auth.oauth_client.provider' => self::PROVIDER,
            'bherila-auth.oauth_client.base_url' => self::PROVIDER_URL,
            'bherila-auth.oauth_client.client_id' => 'svc-test-client',
            'bherila-auth.oauth_client.client_secret' => 'synthetic-test-secret',
            'bherila-auth.oauth_client.redirect_uri' => url('/oauth/callback'),
            'bherila-auth.provider_identity.enabled' => $enforced,
        ]);

        Http::fake(function (ClientRequest $request) {
            if ($request->url() === self::PROVIDER_URL.'/api/reconciliation/identity-status') {
                $this->providerStatusRequests++;
                if ($this->providerOffline) {
                    return Http::response(['message' => 'unavailable'], 503);
                }
                $subject = (string) ($request->data()['subject'] ?? '');
                $generation = $this->providerIdentities[$subject] ?? null;

                return Http::response($generation === null
                    ? ['contract_version' => 1, 'active' => false]
                    : ['contract_version' => 1, 'active' => true, 'subject' => $subject, 'credential_version' => $generation]);
            }
            if ($request->url() === self::PROVIDER_URL.'/oauth/token') {
                return Http::response(['access_token' => 'synthetic-provider-access-token']);
            }
            if ($request->url() === self::PROVIDER_URL.'/api/oauth/user') {
                return Http::response($this->providerLoginIdentity ?? []);
            }

            return Http::response(['message' => 'not found'], 404);
        });
    }

    /** A person the provider knows, active at this generation. */
    protected function providerIdentity(string $subject, ?int $generation = 1): void
    {
        $this->providerIdentities[$subject] = $generation;
    }

    protected function disableProviderIdentity(string $subject): void
    {
        $this->providerIdentities[$subject] = null;
    }

    protected function takeProviderOffline(bool $offline = true): void
    {
        $this->providerOffline = $offline;
    }

    /** A local account bound to the provider subject, read back with every column. */
    protected function boundUser(string $subject): User
    {
        $user = User::query()->create([
            'name' => 'Bound '.$subject,
            'email' => $subject.'@synthetic.test',
            'password' => Str::random(40),
            'oauth_provider' => self::PROVIDER,
            'oauth_subject' => $subject,
        ]);

        return User::query()->findOrFail($user->id);
    }

    /**
     * Sign in through the real callback, as the provider reports the person
     * at sign-in: this records the session's login generation. Null sends an
     * identity response without one, as an older provider does.
     */
    protected function signInThroughProvider(string $subject, ?int $generation): TestResponse
    {
        $this->providerLoginIdentity = array_filter([
            'sub' => $subject,
            'name' => 'Bound '.$subject,
            'email' => $subject.'@synthetic.test',
            'credential_version' => $generation,
        ], static fn (mixed $value): bool => $value !== null);

        return $this->withSession([
            'oauth.login.state' => 'provider-state',
            'oauth.login.code_verifier' => 'provider-verifier',
        ])->get('/oauth/callback?state=provider-state&code=provider-code');
    }
}
