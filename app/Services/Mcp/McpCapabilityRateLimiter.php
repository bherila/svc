<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Cache\RateLimiter;
use Throwable;

/**
 * Consumes one reviewed capability bucket without exposing limiter failures.
 *
 * Every capability also draws on one aggregate bucket per credential
 * (`agent_api.mcp_total_rate_limit` per minute). The per-capability buckets are
 * keyed by capability, so on their own a credential could spend each tool's
 * ceiling in turn, and the HTTP throttle counts a POST rather than the
 * messages a legacy JSON-RPC batch carries in it.
 */
final readonly class McpCapabilityRateLimiter
{
    public function __construct(private RateLimiter $limiter) {}

    public function consume(McpRequestContext $context, string $capability, string $bucket): McpCapabilityRateLimitDecision
    {
        $limit = config("agent_api.mcp_rate_limits.{$bucket}");
        $total = config('agent_api.mcp_total_rate_limit');
        $buckets = [];
        if (is_int($limit) && $limit > 0) {
            $buckets['mcp:'.hash('sha256', $bucket.'|'.$capability.'|'.$context->principal->credentialId)] = $limit;
        }
        if (is_int($total) && $total > 0) {
            $buckets['mcp:'.hash('sha256', 'mcp-total|'.$context->principal->credentialId)] = $total;
        }
        if ($buckets === []) {
            return McpCapabilityRateLimitDecision::Allowed;
        }

        try {
            // Checked together before either is spent, so a refusal by one
            // bucket does not draw down the other.
            foreach ($buckets as $key => $attempts) {
                if ($this->limiter->tooManyAttempts($key, $attempts)) {
                    return McpCapabilityRateLimitDecision::RateLimited;
                }
            }
            foreach (array_keys($buckets) as $key) {
                $this->limiter->hit($key, 60);
            }
        } catch (Throwable) {
            return McpCapabilityRateLimitDecision::Unavailable;
        }

        return McpCapabilityRateLimitDecision::Allowed;
    }
}
