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
            return new Response($request->getId(), CallToolResult::error([
                new TextContent('This operation is temporarily rate limited. Please retry later.'),
            ]));
        }
        $this->limiter->hit($key, 60);

        return $this->inner->handle($request, $session);
    }
}
