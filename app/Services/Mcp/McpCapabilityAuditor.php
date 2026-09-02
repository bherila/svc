<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Psr\Log\LoggerInterface;

/** Emits the stable, metadata-only audit event for an MCP capability attempt. */
final readonly class McpCapabilityAuditor
{
    public function __construct(private LoggerInterface $audit) {}

    public function record(McpRequestContext $context, string $capability, string $bucket, string $classification, string $outcome, int $durationMs): void
    {
        $this->audit->info('mcp.capability.executed', [
            'request_id' => $context->requestId,
            'capability' => $capability,
            'rate_limit_bucket' => $bucket,
            'audit_classification' => $classification,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
            'subject_id' => $context->principal->subject->public_id,
            'credential_fingerprint' => hash('sha256', $context->principal->credentialId),
            'client_fingerprint' => hash('sha256', $context->principal->clientId),
        ]);
    }
}
