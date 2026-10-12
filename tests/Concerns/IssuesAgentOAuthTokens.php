<?php

namespace Tests\Concerns;

use App\Models\User;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use DateTimeImmutable;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

/**
 * Agent credentials for tests: a real self-registered client's
 * authorization-code exchange, and hand-built JWTs shaped like the access
 * tokens the server issues, signed with its key or with a stranger's.
 */
trait IssuesAgentOAuthTokens
{
    private const string OAUTH_TEST_REDIRECT = 'http://127.0.0.1:3210/callback';

    /**
     * Approved in a browser session as the given person.
     *
     * @param  list<string>  $scopes
     * @return array{access_token: string, refresh_token: string, client_id: string}
     */
    protected function issueAgentTokens(User $user, string $resource, array $scopes): array
    {
        $clientId = (string) $this->postJson('/oauth/register', [
            'client_name' => 'Synthetic agent client',
            'redirect_uris' => [self::OAUTH_TEST_REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ])->assertCreated()->json('client_id');
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $this->actingAs($user, 'web');
        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => self::OAUTH_TEST_REDIRECT,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'state' => 'synthetic-state',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'resource' => OAuthResourceIndicator::resource($resource),
        ], '', '&', PHP_QUERY_RFC3986))->assertOk();
        $approval = $this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')])->assertRedirect();
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertIsString($query['code'] ?? null);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => self::OAUTH_TEST_REDIRECT,
            'code' => $query['code'],
            'code_verifier' => $verifier,
            'resource' => OAuthResourceIndicator::resource($resource),
        ], ['Accept' => 'application/json'])->assertOk()->json();
        $this->app['auth']->forgetGuards();

        return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId];
    }

    /**
     * A JWT shaped like an issued access token. Signed with the server's own
     * key unless another private key is given.
     */
    protected function handBuiltAccessToken(string $clientId, DateTimeImmutable $expiresAt, ?string $privateKey = null): string
    {
        $privateKey ??= str_replace('\\n', "\n", (string) config('passport.private_key'));
        if ($privateKey === '' || $clientId === '') {
            $this->fail('A hand-built token needs a signing key and a client id.');
        }
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey),
            InMemory::plainText('unused-verification-key'),
        );
        $issuedAt = $expiresAt->modify('-15 minutes');

        return $configuration->builder()
            ->permittedFor($clientId)
            ->identifiedBy('synthetic-token-'.bin2hex(random_bytes(8)))
            ->issuedAt($issuedAt)
            ->canOnlyBeUsedAfter($issuedAt)
            ->expiresAt($expiresAt)
            ->relatedTo('1')
            ->withClaim('scopes', [])
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }

    /** A private key the server has never seen. */
    protected function strangerPrivateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $private));

        return $private;
    }
}
