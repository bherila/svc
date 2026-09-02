<?php

namespace App\Events;

/** Safe, payload-free hook for MCP metrics and alerts. */
final readonly class McpCapabilityInvoked
{
    public function __construct(
        public string $requestId,
        public string $capability,
        public string $rateLimitBucket,
        public string $auditClassification,
        public string $outcome,
        public int $durationMs,
        public string $subjectId,
        public string $credentialFingerprint,
        public string $clientFingerprint,
    ) {}
}
