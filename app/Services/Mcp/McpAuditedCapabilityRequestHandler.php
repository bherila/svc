<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Closure;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Throwable;

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
        private McpCapabilityRateLimiter $limiter,
        private McpCapabilityResultLimiter $resultLimiter,
        private McpCapabilityConcurrencyLimiter $concurrencyLimiter,
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
        $decision = $this->limiter->consume($this->context, $capability, $metadata['rate_limit_bucket']);
        if ($decision === McpCapabilityRateLimitDecision::RateLimited) {
            $this->auditor->record($this->context, $capability, $metadata['rate_limit_bucket'], $metadata['audit_classification'], 'rate_limited', 0);

            return Error::forServerError('This operation is temporarily rate limited. Please retry later.', $request->getId());
        }
        if ($decision === McpCapabilityRateLimitDecision::Unavailable) {
            $this->auditor->record($this->context, $capability, $metadata['rate_limit_bucket'], $metadata['audit_classification'], 'rate_limit_unavailable', 0);

            return Error::forServerError('This operation is temporarily unavailable. Please retry later.', $request->getId());
        }
        $lease = $this->concurrencyLimiter->acquire($this->context, $capability);
        if ($lease === McpCapabilityConcurrencyFailure::Limited) {
            $this->auditor->record($this->context, $capability, $metadata['rate_limit_bucket'], $metadata['audit_classification'], 'concurrency_limited', 0);

            return Error::forServerError('This operation is temporarily busy. Please retry later.', $request->getId());
        }
        if ($lease === McpCapabilityConcurrencyFailure::Unavailable) {
            $this->auditor->record($this->context, $capability, $metadata['rate_limit_bucket'], $metadata['audit_classification'], 'concurrency_unavailable', 0);

            return Error::forServerError('This operation is temporarily unavailable. Please retry later.', $request->getId());
        }
        $started = hrtime(true);
        try {
            $response = $this->inner->handle($request, $session);
            if ($response instanceof Response && $this->resultLimiter->exceeds($response)) {
                $this->auditor->record(
                    $this->context,
                    $capability,
                    $metadata['rate_limit_bucket'],
                    $metadata['audit_classification'],
                    'result_too_large',
                    (int) ((hrtime(true) - $started) / 1_000_000),
                );

                return Error::forServerError('This operation produced an unexpectedly large response.', $request->getId());
            }
            $this->auditor->record(
                $this->context,
                $capability,
                $metadata['rate_limit_bucket'],
                $metadata['audit_classification'],
                $response instanceof Error ? 'error' : 'success',
                (int) ((hrtime(true) - $started) / 1_000_000),
            );

            return $response;
        } catch (Throwable) {
            $this->auditor->record(
                $this->context,
                $capability,
                $metadata['rate_limit_bucket'],
                $metadata['audit_classification'],
                'error',
                (int) ((hrtime(true) - $started) / 1_000_000),
            );

            return Error::forServerError('This operation is temporarily unavailable. Please retry later.', $request->getId());
        } finally {
            $this->concurrencyLimiter->release($lease);
        }
    }
}
