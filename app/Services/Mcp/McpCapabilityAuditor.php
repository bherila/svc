<?php

namespace App\Services\Mcp;

use App\Events\McpCapabilityInvoked;
use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/** Emits the stable, metadata-only audit event for an MCP capability attempt. */
final readonly class McpCapabilityAuditor
{
    public function __construct(
        private LoggerInterface $audit,
        private Dispatcher $events,
    ) {}

    public function record(McpRequestContext $context, string $capability, string $bucket, string $classification, string $outcome, int $durationMs): void
    {
        $metadata = [
            'request_id' => $context->requestId,
            'capability' => $capability,
            'rate_limit_bucket' => $bucket,
            'audit_classification' => $classification,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
            'subject_id' => $context->principal->subject->public_id,
            'credential_fingerprint' => hash('sha256', $context->principal->credentialId),
            'client_fingerprint' => hash('sha256', $context->principal->clientId),
        ];
        $this->audit->info('mcp.capability.executed', $metadata);
        try {
            $this->events->dispatch(new McpCapabilityInvoked(
                $metadata['request_id'],
                $metadata['capability'],
                $metadata['rate_limit_bucket'],
                $metadata['audit_classification'],
                $metadata['outcome'],
                $metadata['duration_ms'],
                $metadata['subject_id'],
                $metadata['credential_fingerprint'],
                $metadata['client_fingerprint'],
            ));
        } catch (Throwable) {
            $this->audit->warning('mcp.capability.metrics_unavailable', [
                'request_id' => $metadata['request_id'],
                'capability' => $metadata['capability'],
                'audit_classification' => $metadata['audit_classification'],
            ]);
        }
    }
}
