<?php

namespace Tests\Feature\AgentApi;

use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\ResourceServer;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * What a connector sees of the authorization server, pinned as a snapshot.
 *
 * Discovery documents, dynamic client registration, the PKCE and resource
 * refusals and the consent page are the interoperability surface: a connector
 * that worked yesterday must work today. The snapshot was recorded before the
 * agent OAuth preset replaced the hand-written configuration (#408) and the
 * preset must reproduce it exactly. Change it only for a deliberate protocol
 * change, recorded in the PR that makes it.
 *
 * Regenerate with SVC_UPDATE_OAUTH_SNAPSHOT=1.
 */
final class AgentOAuthBehaviourSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private const string SNAPSHOT = 'tests/Fixtures/AgentApi/oauth-behaviour-snapshot.json';

    private const string REDIRECT = 'http://127.0.0.1:3210/callback';

    public function test_the_authorization_server_behaves_as_recorded(): void
    {
        $this->configureSigningKeys();
        $observed = $this->observe();
        $path = base_path(self::SNAPSHOT);

        if (getenv('SVC_UPDATE_OAUTH_SNAPSHOT') === '1') {
            file_put_contents($path, json_encode($observed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        $this->assertFileExists($path);
        $this->assertSame(
            json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            json_decode(json_encode($observed, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function observe(): array
    {
        $observed = [];

        $observed['authorization_server'] = $this->document($this->getJson('/.well-known/oauth-authorization-server'));
        foreach (['', '/api/v1', '/api/v1/mcp', '/api/v2'] as $suffix) {
            $observed['protected_resource'.$suffix] = $this->document($this->getJson('/.well-known/oauth-protected-resource'.$suffix));
        }

        $public = $this->postJson('/oauth/register', [
            'client_name' => 'Snapshot client',
            'redirect_uris' => [self::REDIRECT],
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
            'scope' => AgentApiScopes::MCP_USE.' '.AgentApiScopes::IDENTITY_READ,
        ]);
        $registration = $public->json();
        $clientId = (string) ($registration['client_id'] ?? '');
        $observed['registration'] = [
            'status' => $public->getStatusCode(),
            'cache_control' => $public->headers->get('Cache-Control'),
            'keys' => $this->sortedKeys($registration),
            'values' => array_diff_key($registration, array_flip(['client_id', 'client_id_issued_at'])),
        ];
        $observed['registration_refusals'] = [
            'confidential' => $this->refusal($this->postJson('/oauth/register', [
                'client_name' => 'Confidential', 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'client_secret_basic',
            ])),
            'insecure_redirect' => $this->refusal($this->postJson('/oauth/register', [
                'client_name' => 'Insecure', 'redirect_uris' => ['http://app.example.test/cb'], 'token_endpoint_auth_method' => 'none',
            ])),
            'unknown_scope' => $this->refusal($this->postJson('/oauth/register', [
                'client_name' => 'Unknown scope', 'redirect_uris' => [self::REDIRECT], 'token_endpoint_auth_method' => 'none', 'scope' => 'admin:everything',
            ])),
        ];

        $user = User::factory()->create(['email' => 'snapshot-'.Str::random(8).'@synthetic.test']);
        $this->actingAs($user, 'web');
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $authorize = [
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'scope' => AgentApiScopes::MCP_USE.' '.AgentApiScopes::IDENTITY_READ,
            'state' => 'snapshot-state',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $observed['authorize_refusals'] = [
            'no_pkce' => $this->authorizeRefusal(array_diff_key($authorize, array_flip(['code_challenge', 'code_challenge_method']))),
            'plain_pkce' => $this->authorizeRefusal(['code_challenge_method' => 'plain', 'code_challenge' => $verifier] + $authorize),
            'scope_beyond_registration' => $this->authorizeRefusal(['scope' => AgentApiScopes::IDENTITY_READ.' '.AgentApiScopes::BILLING_READ] + $authorize),
            'unknown_scope' => $this->authorizeRefusal(['scope' => 'admin:everything'] + $authorize),
            'other_resource' => $this->authorizeRefusal(['resource' => 'https://other.example.test/api/v1'] + $authorize),
        ];

        $consent = $this->get('/oauth/authorize?'.http_build_query($authorize, '', '&', PHP_QUERY_RFC3986));
        $html = (string) $consent->getContent();
        $observed['consent'] = [
            'status' => $consent->getStatusCode(),
            'no_store' => str_contains((string) $consent->headers->get('Cache-Control'), 'no-store'),
            'shows' => array_values(array_filter([
                'Connect Snapshot client to SVC?',
                'This application is requesting access to your SVC account.',
                'Only continue if you recognize and trust this application. You can revoke the connection later.',
                'This application registered automatically. After approval, your browser returns to:',
                self::REDIRECT,
                'SVC permissions and current workspace and project roles still apply to every request.',
                'Authorize',
                'Cancel',
            ], static fn (string $text): bool => str_contains($html, e($text)))),
            'scope_descriptions' => array_values(array_filter(
                [AgentApiScopes::descriptions()[AgentApiScopes::MCP_USE], AgentApiScopes::descriptions()[AgentApiScopes::IDENTITY_READ]],
                static fn (string $text): bool => str_contains($html, e($text)),
            )),
        ];

        $approval = $this->post('/oauth/authorize', ['auth_token' => (string) session('authToken')]);
        parse_str((string) parse_url((string) $approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $observed['approval'] = ['status' => $approval->getStatusCode(), 'keys' => $this->sortedKeys($query)];

        $withoutVerifier = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code' => $query['code'] ?? '',
        ], ['Accept' => 'application/json']);
        $observed['token_without_verifier'] = $this->refusal($withoutVerifier);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code' => $query['code'] ?? '', 'code_verifier' => $verifier,
        ], ['Accept' => 'application/json']);
        $observed['token'] = ['status' => $tokens->getStatusCode(), 'keys' => $this->sortedKeys($tokens->json()), 'token_type' => $tokens->json('token_type')];

        $this->app['auth']->forgetGuards();
        $unauthenticated = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $observed['mcp_challenge'] = ['status' => $unauthenticated->getStatusCode(), 'www_authenticate' => $unauthenticated->headers->get('WWW-Authenticate')];

        return $observed;
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function document(TestResponse $response): array
    {
        $ok = $response->getStatusCode() === 200;

        return [
            'status' => $response->getStatusCode(),
            'cache_control' => $ok ? $response->headers->get('Cache-Control') : null,
            'body' => $ok ? $response->json() : null,
        ];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    private function refusal(TestResponse $response): array
    {
        return ['status' => $response->getStatusCode(), 'error' => $response->json('error')];
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function authorizeRefusal(array $query): array
    {
        $response = $this->get('/oauth/authorize?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $location = $response->headers->get('Location');
        $redirected = [];
        if (is_string($location)) {
            parse_str((string) parse_url($location, PHP_URL_QUERY), $redirected);
        }

        return [
            'status' => $response->getStatusCode(),
            'redirects_to_client' => is_string($location) && str_starts_with($location, self::REDIRECT),
            'error' => $redirected['error'] ?? $response->json('error'),
            'state' => $redirected['state'] ?? null,
            'consent_prepared' => $response->getStatusCode() === 200,
        ];
    }

    /**
     * @param  array<array-key, mixed>|null  $value
     * @return list<string>
     */
    private function sortedKeys(?array $value): array
    {
        $keys = array_map('strval', array_keys($value ?? []));
        sort($keys);

        return $keys;
    }

    private function configureSigningKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key);
        $private = '';
        $this->assertTrue(openssl_pkey_export($key, $private));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $private, 'passport.public_key' => $details['key']]);
        $this->app->forgetInstance(AuthorizationServer::class);
        $this->app->forgetInstance(ResourceServer::class);
    }
}
