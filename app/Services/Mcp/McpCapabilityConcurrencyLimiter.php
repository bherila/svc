<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Throwable;

/** Acquires a bounded, non-blocking capability execution slot. */
final readonly class McpCapabilityConcurrencyLimiter
{
    public function __construct(private ?LockProvider $locks) {}

    public function acquire(McpRequestContext $context, string $capability): Lock|McpCapabilityConcurrencyFailure
    {
        $limit = config('agent_api.mcp_concurrency_limit');
        $leaseSeconds = config('agent_api.mcp_concurrency_lock_seconds');
        if (! $this->locks instanceof LockProvider || ! is_int($limit) || $limit < 1 || ! is_int($leaseSeconds) || $leaseSeconds < 1) {
            return McpCapabilityConcurrencyFailure::Unavailable;
        }

        $key = 'mcp:concurrency:'.hash('sha256', $capability.'|'.$context->principal->credentialId);
        try {
            for ($slot = 1; $slot <= $limit; $slot++) {
                $lock = $this->locks->lock($key.':'.$slot, $leaseSeconds);
                if ($lock->get()) {
                    return $lock;
                }
            }
        } catch (Throwable) {
            return McpCapabilityConcurrencyFailure::Unavailable;
        }

        return McpCapabilityConcurrencyFailure::Limited;
    }

    public function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // Lease expiry is the fail-safe when a lock backend disappears.
        }
    }
}
