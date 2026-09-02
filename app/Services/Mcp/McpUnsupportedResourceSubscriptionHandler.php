<?php

namespace App\Services\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\ResourceSubscribeRequest;
use Mcp\Schema\Request\ResourceUnsubscribeRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Rejects unsupported resource subscriptions even when invoked directly.
 *
 * @implements RequestHandlerInterface<never>
 */
final class McpUnsupportedResourceSubscriptionHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof ResourceSubscribeRequest || $request instanceof ResourceUnsubscribeRequest;
    }

    public function handle(Request $request, SessionInterface $session): Error
    {
        return Error::forMethodNotFound('Resource subscriptions are not supported.', $request->getId());
    }
}
