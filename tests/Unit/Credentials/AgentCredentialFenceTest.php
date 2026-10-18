<?php

namespace Tests\Unit\Credentials;

use App\Services\Credentials\AgentCredentialFence;
use App\Support\WorkspaceClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
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

    public function test_a_value_that_is_not_an_expiry_covers_nothing(): void
    {
        $cache = new Repository(new ArrayStore);
        $cache->put('agent-credential-fence:7:client-a', (string) (time() + 600), 600);
        $cache->put('agent-credential-fence:7:*', true, 600);

        $this->assertFalse((new AgentCredentialFence($cache, new WorkspaceClock))->covers(7, 'client-a'));
    }
}
