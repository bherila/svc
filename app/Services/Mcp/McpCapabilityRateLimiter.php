<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Cache\RateLimiter;
use Throwable;

/** Consumes one reviewed capability bucket without exposing limiter failures. */
final readonly class McpCapabilityRateLimiter
{
    public function __construct(private RateLimiter $limiter) {}

    public function consume(McpRequestContext $context, string $capability, string $bucket): McpCapabilityRateLimitDecision
    {
        $limit = config("agent_api.mcp_rate_limits.{$bucket}");
        if (! is_int($limit) || $limit <= 0) {
            return McpCapabilityRateLimitDecision::Allowed;
        }

        try {
            $key = 'mcp:'.hash('sha256', $bucket.'|'.$capability.'|'.$context->principal->credentialId);
            if ($this->limiter->tooManyAttempts($key, $limit)) {
                return McpCapabilityRateLimitDecision::RateLimited;
            }
            $this->limiter->hit($key, 60);
        } catch (Throwable) {
            return McpCapabilityRateLimitDecision::Unavailable;
        }

        return McpCapabilityRateLimitDecision::Allowed;
    }
}
