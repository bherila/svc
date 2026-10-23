<?php

namespace Tests;

use App\Models\AgentPrincipal;
use App\Models\User;
use BWH\Auth\OAuth\Server\OAuthResourceIndicator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Str;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;

abstract class TestCase extends BaseTestCase
{
    /** @var array{private: string, public: string}|null */
    private static ?array $oauthSigningKeys = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // A locally built SSR bundle must not turn a backend test into an HTTP
        // call to the renderer. CI and a clean checkout have no such bundle.
        config(['inertia.ssr.enabled' => false]);
        // Real JWT validation needs ephemeral keys, not a new expensive keypair
        // for every assertion. Each isolated test process owns its own pair;
        // every new application still receives it through ordinary config.
        if (self::$oauthSigningKeys === null) {
            $key = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            $this->assertNotFalse($key);
            $this->assertTrue(openssl_pkey_export($key, $privateKey));
            $this->assertIsString($privateKey);
            $details = openssl_pkey_get_details($key);
            $this->assertIsArray($details);
            self::$oauthSigningKeys = ['private' => $privateKey, 'public' => $details['key']];
        }
        config(['passport.private_key' => self::$oauthSigningKeys['private'], 'passport.public_key' => self::$oauthSigningKeys['public']]);
    }

    /**
     * An MCP connection's credential: bound to the MCP resource, as a token an
     * MCP client obtains is. REST calls made after this reuse the guard's user
     * without consulting the token's audience.
     *
     * @param  list<string>  $scopes
     */
    protected function actingAsMcp(User $user, array $scopes): AgentPrincipal
    {
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $token = new Token([
            'id' => 'mcp-test-'.Str::uuid(),
            'user_id' => $principal->id,
            'client_id' => 'mcp-test-client',
            'scopes' => $scopes,
            'revoked' => false,
            'resource_uri' => OAuthResourceIndicator::resource('mcp'),
            'expires_at' => now()->addMinute(),
        ]);
        $token->save();
        $principal->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id' => $token->client_id,
            'oauth_scopes' => $scopes,
        ]));
        app('auth')->guard('api')->setUser($principal);
        app('auth')->shouldUse('api');

        return $principal;
    }
}
