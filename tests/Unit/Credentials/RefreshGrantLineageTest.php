<?php

namespace Tests\Unit\Credentials;

use App\Services\Credentials\AgentCredentialFence;
use App\Services\Credentials\RefreshGrantLineage;
use App\Support\WorkspaceClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptTrait;
use Tests\TestCase;

/**
 * Which connection a refresh grant continues, read from the refresh token it
 * presents, and the lineage fence that a connection disconnecting itself raises.
 */
final class RefreshGrantLineageTest extends TestCase
{
    public function test_a_refresh_grant_names_the_access_token_its_refresh_token_belongs_to(): void
    {
        $lineage = app(RefreshGrantLineage::class);
        $presented = $this->encrypt(['refresh_token_id' => 'refresh-1', 'access_token_id' => 'access-1', 'client_id' => 'c']);

        $this->assertSame('access-1', $lineage->redeemedAccessTokenId($this->request('refresh_token', $presented)));
    }

    public function test_anything_but_a_well_formed_refresh_grant_names_nothing(): void
    {
        $lineage = app(RefreshGrantLineage::class);
        $good = $this->encrypt(['access_token_id' => 'access-1']);

        $this->assertNull($lineage->redeemedAccessTokenId(null));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('authorization_code', $good)));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', '')));
        $this->assertNull($lineage->redeemedAccessTokenId(Request::create('/oauth/token', 'POST', ['grant_type' => 'refresh_token', 'refresh_token' => ['x']])));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', 'not-encrypted')));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', $this->encrypt(['refresh_token_id' => 'r']))));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', $this->encrypt(['access_token_id' => '']))));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', $this->encrypt(['access_token_id' => 7]))));
        $this->assertNull($lineage->redeemedAccessTokenId($this->request('refresh_token', $this->encryptRaw('"just a string"'))));
    }

    public function test_a_lineage_fence_covers_its_token_alone_for_its_window(): void
    {
        $this->freezeSecond();
        $cache = new Repository(new ArrayStore);
        $fence = new AgentCredentialFence($cache, new WorkspaceClock);

        $fence->raiseLineage('access-1');

        $this->assertTrue($fence->coversLineage('access-1'));
        $this->assertFalse($fence->coversLineage('access-2'));
        $this->assertFalse($fence->covers(1, 'access-1'));
        // Keyed by digest: the cache never holds the token id.
        $this->assertSame(now()->getTimestamp() + AgentCredentialFence::SECONDS, $cache->get('agent-credential-fence:lineage:'.hash('sha256', 'access-1')));
        $this->travel(AgentCredentialFence::SECONDS - 1)->seconds();
        $this->assertTrue($fence->coversLineage('access-1'));
        $this->travel(1)->seconds();
        $this->assertFalse($fence->coversLineage('access-1'));
    }

    private function request(string $grant, string $refreshToken): Request
    {
        return Request::create('/oauth/token', 'POST', ['grant_type' => $grant, 'refresh_token' => $refreshToken]);
    }

    /** @param  array<string, mixed>  $payload */
    private function encrypt(array $payload): string
    {
        return $this->encryptRaw((string) json_encode($payload));
    }

    private function encryptRaw(string $plain): string
    {
        $crypt = new class
        {
            use CryptTrait;

            public function seal(string $plain): string
            {
                return $this->encrypt($plain);
            }
        };
        $crypt->setEncryptionKey(Passport::tokenEncryptionKey(app(Encrypter::class)));

        return $crypt->seal($plain);
    }
}
