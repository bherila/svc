<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a legacy JSON-RPC batch larger than `agent_api.mcp_max_batch_size`.
 *
 * The SDK accepts batches of up to a hundred messages and runs each, while the
 * route's HTTP throttle counts the POST. Its message factory is built inside
 * the server builder with no way to pass a smaller size, so the bound is held
 * here, before the SDK reads the body. A refused batch runs nothing; the error
 * is the one the SDK itself gives an oversized batch. A single message, and a
 * batch within the bound, pass through untouched.
 */
final class LimitMcpBatchSize
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $body = ltrim((string) $request->getContent());
        if (! str_starts_with($body, '[')) {
            return $next($request);
        }

        $messages = json_decode($body, true);
        $max = max(1, (int) config('agent_api.mcp_max_batch_size', 10));
        if (! is_array($messages) || ! array_is_list($messages) || count($messages) <= $max) {
            return $next($request);
        }

        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => sprintf('JSON-RPC batch size %d exceeds the maximum allowed batch size of %d.', count($messages), $max),
            ],
        ], Response::HTTP_BAD_REQUEST);
    }
}
