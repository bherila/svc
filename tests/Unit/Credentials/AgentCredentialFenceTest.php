<?php

namespace Tests\Unit\Credentials;

use App\Services\Credentials\AgentCredentialFence;
use App\Services\Credentials\RevokeCredentialsIssuedDuringRevocation;
use App\Support\WorkspaceClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Event;
use Laravel\Passport\AuthCode;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Events\RefreshTokenCreated;
use Tests\TestCase;

/** The revocation fence's window and reach, on an in-memory cache. */
final class AgentCredentialFenceTest extends TestCase
{
    public function test_a_fence_covers_its_person_and_client_for_its_window_only(): void
    {
        $this->freezeSecond();
        $fence = new AgentCredentialFence(new Repository(new ArrayStore), new WorkspaceClock);

        $fence->raise(7, 'client-a');

        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->assertFalse($fence->covers(7, 'client-b'));
        $this->assertFalse($fence->covers(8, 'client-a'));

        $this->travel(AgentCredentialFence::SECONDS - 1)->seconds();
        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->travel(1)->seconds();
        $this->assertFalse($fence->covers(7, 'client-a'));
        $this->assertSame(60, AgentCredentialFence::SECONDS);
    }

    public function test_a_fence_on_every_client_covers_each_and_does_not_replace_another(): void
    {
        $this->freezeSecond();
        $fence = new AgentCredentialFence(new Repository(new ArrayStore), new WorkspaceClock);

        $fence->raise(7, 'client-a');
        $this->travel(30)->seconds();
        $fence->raise(7, null);

        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->assertTrue($fence->covers(7, 'client-z'));
        $this->assertFalse($fence->covers(8, 'client-z'));

        // The later fence on every client still holds after the earlier one lapses.
        $this->travel(31)->seconds();
        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->travel(29)->seconds();
        $this->assertFalse($fence->covers(7, 'client-a'));
    }

    /**
     * The entry's own expiry decides, not only the cache's: a store that keeps
     * an entry past its TTL still fences nothing from the stated second on.
     */
    public function test_a_fence_ends_at_its_stated_second_under_its_documented_key(): void
    {
        $this->freezeSecond();
        $cache = new Repository(new ArrayStore);
        $cache->forever('agent-credential-fence:7:client-a', now()->getTimestamp() + 5);
        $fence = new AgentCredentialFence($cache, new WorkspaceClock);

        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->assertFalse($fence->covers(7, 'client-b'));
        $this->travel(4)->seconds();
        $this->assertTrue($fence->covers(7, 'client-a'));
        $this->travel(1)->seconds();
        $this->assertFalse($fence->covers(7, 'client-a'));

        $fence->raise(9, null);
        $this->assertSame(now()->getTimestamp() + AgentCredentialFence::SECONDS, $cache->get('agent-credential-fence:9:*'));

        // Keys are in Passport's lower case, whichever casing names the client.
        $fence->raise(10, 'CLIENT-B');
        $this->assertTrue($fence->covers(10, 'client-b'));
        $this->assertTrue($fence->covers(10, 'Client-B'));
        $this->assertNotNull($cache->get('agent-credential-fence:10:client-b'));
    }

    public function test_the_fence_listens_to_every_credential_passport_persists(): void
    {
        $listeners = Event::getRawListeners();

        $this->assertContains([RevokeCredentialsIssuedDuringRevocation::class, 'accessTokenCreated'], $listeners[AccessTokenCreated::class] ?? []);
        $this->assertContains([RevokeCredentialsIssuedDuringRevocation::class, 'refreshTokenCreated'], $listeners[RefreshTokenCreated::class] ?? []);
        $this->assertContains([RevokeCredentialsIssuedDuringRevocation::class, 'authCodeCreated'], $listeners['eloquent.created: '.AuthCode::class] ?? []);
    }

    /** Redis without value serialization returns a stored number as a numeric string. */
    public function test_an_expiry_read_back_as_a_numeric_string_still_fences(): void
    {
        $cache = new Repository(new ArrayStore);
        $cache->put('agent-credential-fence:7:client-a', (string) (time() + 600), 600);

        $this->assertTrue((new AgentCredentialFence($cache, new WorkspaceClock))->covers(7, 'client-a'));
    }

    public function test_a_value_that_is_not_an_expiry_covers_nothing(): void
    {
        $cache = new Repository(new ArrayStore);
        $cache->put('agent-credential-fence:7:client-a', 'soon', 600);
        $cache->put('agent-credential-fence:7:*', [time() + 600], 600);
        $cache->put('agent-credential-fence:8:*', (string) (time() + 600).'.5', 600);

        $fence = new AgentCredentialFence($cache, new WorkspaceClock);
        $this->assertFalse($fence->covers(7, 'client-a'));
        $this->assertFalse($fence->covers(8, 'client-a'));
    }
}
