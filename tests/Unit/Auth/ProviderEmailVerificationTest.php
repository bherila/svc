<?php

namespace Tests\Unit\Auth;

use App\Auth\ProviderEmailVerification;
use BWH\Auth\OAuth\OAuthIdentity;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Client\Response as ClientResponse;
use Tests\TestCase;

final class ProviderEmailVerificationTest extends TestCase
{
    private const string IDENTITY_URL = 'https://identity.example.test/api/oauth/user';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'bherila-auth.oauth_client.base_url' => 'https://identity.example.test',
            'bherila-auth.oauth_client.identity_path' => '/api/oauth/user',
        ]);
    }

    public function test_nothing_is_refused_outside_a_sign_in_callback(): void
    {
        $verification = new ProviderEmailVerification;

        $this->assertFalse($verification->refuses($this->identity('person-1')));
    }

    public function test_an_identity_whose_response_was_never_seen_is_refused(): void
    {
        $verification = new ProviderEmailVerification;

        $this->assertTrue($verification->during(fn (): bool => $verification->refuses($this->identity('person-1'))));
    }

    public function test_a_response_for_another_subject_is_refused(): void
    {
        $verification = new ProviderEmailVerification;

        $refused = $verification->during(function () use ($verification): bool {
            $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'someone-else', 'email_verified' => true]));

            return $verification->refuses($this->identity('person-1'));
        });

        $this->assertTrue($refused);
    }

    public function test_only_the_first_identity_response_counts_and_other_requests_are_ignored(): void
    {
        $verification = new ProviderEmailVerification;

        $refused = $verification->during(function () use ($verification): bool {
            $verification->observe($this->request('GET', 'https://identity.example.test/oauth/token'), $this->response(['sub' => 'person-1', 'email_verified' => true]));
            $verification->observe($this->request('POST', self::IDENTITY_URL), $this->response(['sub' => 'person-1', 'email_verified' => true]));
            $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'person-1', 'email_verified' => false]));
            $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'person-1', 'email_verified' => true]));

            return $verification->refuses($this->identity('person-1'));
        });

        $this->assertTrue($refused);
    }

    public function test_the_watch_ends_with_the_callback(): void
    {
        $verification = new ProviderEmailVerification;

        $verification->during(function () use ($verification): void {
            $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'person-1', 'email_verified' => false]));
        });

        // A response arriving after the callback is not recorded, and nothing carries over.
        $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'person-1', 'email_verified' => false]));
        $this->assertFalse($verification->refuses($this->identity('person-1')));
        $this->assertFalse($verification->during(function () use ($verification): bool {
            $verification->observe($this->request('GET', self::IDENTITY_URL), $this->response(['sub' => 'person-1']));

            return $verification->refuses($this->identity('person-1'));
        }));
    }

    private function identity(string $subject): OAuthIdentity
    {
        return new OAuthIdentity('test-provider', $subject, 'Synthetic Person', $subject.'@example.test');
    }

    private function request(string $method, string $url): ClientRequest
    {
        return new ClientRequest(new PsrRequest($method, $url));
    }

    /** @param  array<string, mixed>  $body */
    private function response(array $body): ClientResponse
    {
        return new ClientResponse(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }
}
