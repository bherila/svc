<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Services\Mcp\Toolsets\McpToolVisibility;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Answers a call to a tool the session does not list exactly as it answers a
 * call to a tool that does not exist. Enabling a toolset is how a tool becomes
 * callable; it is not an authorization, which the wrapped handler and the tool
 * itself still perform on every call.
 *
 * @implements RequestHandlerInterface<CallToolResult>
 */
final readonly class McpToolsetCallGateHandler implements RequestHandlerInterface
{
    /** @param  RequestHandlerInterface<CallToolResult>  $inner */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpToolVisibility $visibility,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof CallToolRequest) {
            $selection = McpToolsetSelection::fromSession($session->get(McpToolsetSelection::SESSION_KEY));
            if (! $this->visibility->isVisible($request->name, $selection)) {
                // The same answer the SDK gives for a name it does not know: -32602,
                // as the specification requires, so a hidden tool is not told apart
                // from an unknown one.
                return Error::forInvalidParams((new ToolNotFoundException($request->name))->getMessage(), $request->getId());
            }
        }

        return $this->inner->handle($request, $session);
    }
}
