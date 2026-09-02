<?php

namespace App\Services\Mcp;

use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\Request\CompletionCompleteRequest;
use Mcp\Schema\Request\SetLogLevelRequest;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Rejects optional protocol features SVC does not advertise or implement.
 *
 * @implements RequestHandlerInterface<never>
 */
final class McpUnsupportedOptionalProtocolHandler implements RequestHandlerInterface
{
    public function supports(Request $request): bool
    {
        return $request instanceof CompletionCompleteRequest || $request instanceof SetLogLevelRequest;
    }

    public function handle(Request $request, SessionInterface $session): Error
    {
        return Error::forMethodNotFound('This optional MCP feature is not supported.', $request->getId());
    }
}
