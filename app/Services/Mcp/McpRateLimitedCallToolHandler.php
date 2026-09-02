<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Context\McpRequestContext;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Enforces reviewed per-capability MCP buckets before tool execution.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class McpRateLimitedCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param  RequestHandlerInterface<CallToolResult>  $inner
     * @param  array<string, array{rate_limit_bucket: string, audit_classification: string}>  $metadataByTool
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpCapabilityRateLimiter $limiter,
        private McpCapabilityAuditor $auditor,
        private McpRequestContext $context,
        private array $metadataByTool,
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
        $metadata = $this->metadataByTool[$request->name] ?? [
            'rate_limit_bucket' => 'mcp-unknown',
            'audit_classification' => 'mcp.unknown',
        ];
        $bucket = $metadata['rate_limit_bucket'];
        $classification = $metadata['audit_classification'];
        $decision = $this->limiter->consume($this->context, $request->name, $bucket);
        if ($decision === McpCapabilityRateLimitDecision::RateLimited) {
            $response = new Response($request->getId(), CallToolResult::error([
                new TextContent('This operation is temporarily rate limited. Please retry later.'),
            ]));
            $this->auditor->record($this->context, $request->name, $bucket, $classification, 'rate_limited', 0);

            return $response;
        }
        if ($decision === McpCapabilityRateLimitDecision::Unavailable) {
            $response = new Response($request->getId(), CallToolResult::error([
                new TextContent('This operation is temporarily unavailable. Please retry later.'),
            ]));
            $this->auditor->record($this->context, $request->name, $bucket, $classification, 'rate_limit_unavailable', 0);

            return $response;
        }
        $started = hrtime(true);
        $response = $this->inner->handle($request, $session);
        $outcome = $response instanceof Error
            ? 'error'
            : ($response->result->isError ? 'rejected' : 'success');
        $this->auditor->record($this->context, $request->name, $bucket, $classification, $outcome, (int) ((hrtime(true) - $started) / 1_000_000));

        return $response;
    }
}
