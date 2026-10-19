<?php

namespace Tests\Feature\AgentApi;

use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Authorization-server hardening for first-party connections (F2), through
 * Passport's real routes and controllers: `client_credentials` is refused at
 * the token endpoint whatever the client, so no client secret alone ever
 * mints a credential that acts for nobody.
 */
final class AgentOAuthFirstPartyClientTest extends TestCase
{
    use RefreshDatabase;

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

    /** @param  TestResponse<Response>  $response */
    private function assertUnsupportedGrant(TestResponse $response): void
    {
        $response->assertStatus(400)
            ->assertJsonPath('error', 'unsupported_grant_type')
            ->assertHeader('Cache-Control', 'no-store, private');
    }
}
