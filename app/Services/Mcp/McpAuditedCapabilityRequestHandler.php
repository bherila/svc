<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Closure;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Audits resource and prompt reads without retaining their URI, arguments, or result.
 *
 * @implements RequestHandlerInterface<mixed>
 */
final readonly class McpAuditedCapabilityRequestHandler implements RequestHandlerInterface
{
    /**
     * @param  RequestHandlerInterface<mixed>  $inner
     * @param  array<string, array{rate_limit_bucket: string, audit_classification: string}>  $metadataByCapability
     * @param  Closure(Request): string  $capabilityName
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpCapabilityAuditor $auditor,
        private McpRequestContext $context,
        private array $metadataByCapability,
        private Closure $capabilityName,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $capability = ($this->capabilityName)($request);
        $metadata = $this->metadataByCapability[$capability] ?? [
            'rate_limit_bucket' => 'mcp-unknown',
            'audit_classification' => 'mcp.unknown',
        ];
        $started = hrtime(true);
        $response = $this->inner->handle($request, $session);
        $this->auditor->record(
            $this->context,
            $capability,
            $metadata['rate_limit_bucket'],
            $metadata['audit_classification'],
            $response instanceof Error ? 'error' : 'success',
            (int) ((hrtime(true) - $started) / 1_000_000),
        );

        return $response;
    }
}
