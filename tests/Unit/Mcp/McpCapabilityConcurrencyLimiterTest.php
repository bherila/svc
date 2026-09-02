<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Models\User;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use App\Services\Mcp\McpCapabilityConcurrencyFailure;
use App\Services\Mcp\McpCapabilityConcurrencyLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpCapabilityConcurrencyLimiterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_limits_concurrent_execution_per_credential_and_capability(): void
    {
        config(['agent_api.mcp_concurrency_limit' => 2, 'agent_api.mcp_concurrency_lock_seconds' => 60]);
        $store = app('cache')->store('array')->getStore();
        $this->assertInstanceOf(LockProvider::class, $store);
        $limiter = new McpCapabilityConcurrencyLimiter($store);
        $context = $this->context();

        $first = $limiter->acquire($context, 'projects.list');
        $second = $limiter->acquire($context, 'projects.list');

        $this->assertInstanceOf(Lock::class, $first);
        $this->assertInstanceOf(Lock::class, $second);
        $this->assertSame(McpCapabilityConcurrencyFailure::Limited, $limiter->acquire($context, 'projects.list'));
        $otherCapability = $limiter->acquire($context, 'projects.get');
        $this->assertInstanceOf(Lock::class, $otherCapability);

        $limiter->release($first);
        $limiter->release($second);
        $limiter->release($otherCapability);
    }

    public function test_it_fails_closed_when_the_lock_store_is_unavailable(): void
    {
        $locks = new class implements LockProvider
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                throw new \RuntimeException('lock backend details must not escape');
            }

            public function restoreLock($name, $owner)
            {
                throw new \RuntimeException('lock backend details must not escape');
            }
        };

        $this->assertSame(
            McpCapabilityConcurrencyFailure::Unavailable,
            (new McpCapabilityConcurrencyLimiter($locks))->acquire($this->context(), 'projects.list'),
        );
    }

    private function context(): McpRequestContext
    {
        $user = User::factory()->create();

        return new McpRequestContext(new McpPrincipal(
            AgentPrincipal::query()->findOrFail($user->id),
            'credential',
            'client',
            ['mcp:use'],
        ), 'request-id');
    }
}
