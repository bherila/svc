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
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        config(['passport.private_key' => $privateKey, 'passport.public_key' => $details['key']]);
    }

    /** @param list<string> $scopes */
    protected function actingAsMcp(User $user, array $scopes): AgentPrincipal
    {
        $principal = AgentPrincipal::query()->findOrFail($user->id);
        $token = new Token([
            'id' => 'mcp-test-'.Str::uuid(),
            'user_id' => $principal->id,
            'client_id' => 'mcp-test-client',
            'scopes' => $scopes,
            'revoked' => false,
            'resource_uri' => OAuthResourceIndicator::resource(),
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
