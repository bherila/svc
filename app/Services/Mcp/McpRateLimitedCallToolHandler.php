<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Illuminate\Cache\RateLimiter;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;

/**
 * Enforces reviewed per-capability MCP buckets before tool execution.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class McpRateLimitedCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param  RequestHandlerInterface<CallToolResult>  $inner
     * @param  array<string, string>  $bucketsByTool
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private RateLimiter $limiter,
        private LoggerInterface $audit,
        private McpRequestContext $context,
        private array $bucketsByTool,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if (! $request instanceof CallToolRequest) {
            return $this->inner->handle($request, $session);
        }
        $bucket = $this->bucketsByTool[$request->name] ?? null;
        if ($bucket === null) {
            return $this->inner->handle($request, $session);
        }
        $limit = config("agent_api.mcp_rate_limits.{$bucket}");
        if (! is_int($limit) || $limit < 1) {
            return $this->inner->handle($request, $session);
        }
        $key = 'mcp:'.hash('sha256', $bucket.'|'.$request->name.'|'.$this->context->principal->credentialId);
        if ($this->limiter->tooManyAttempts($key, $limit)) {
            $response = new Response($request->getId(), CallToolResult::error([
                new TextContent('This operation is temporarily rate limited. Please retry later.'),
            ]));
            $this->audit($request, $bucket, 'rate_limited', 0);

            return $response;
        }
        $this->limiter->hit($key, 60);
        $started = hrtime(true);
        $response = $this->inner->handle($request, $session);
        $outcome = $response instanceof Error
            ? 'error'
            : ($response->result->isError ? 'rejected' : 'success');
        $this->audit($request, $bucket, $outcome, (int) ((hrtime(true) - $started) / 1_000_000));

        return $response;
    }

    private function audit(CallToolRequest $request, string $bucket, string $outcome, int $durationMs): void
    {
        $this->audit->info('mcp.capability.executed', [
            'request_id' => $this->context->requestId,
            'capability' => $request->name,
            'rate_limit_bucket' => $bucket,
            'outcome' => $outcome,
            'duration_ms' => $durationMs,
            'subject_id' => $this->context->principal->subject->public_id,
            'credential_fingerprint' => hash('sha256', $this->context->principal->credentialId),
            'client_fingerprint' => hash('sha256', $this->context->principal->clientId),
        ]);
    }
}
