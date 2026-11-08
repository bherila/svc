<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Schema\ServerCapabilities;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Records, once per session, whether the connection lists every tool or
 * discovers toolsets progressively. The choice is made at connect time and
 * lives in the session from then on, so a later request cannot change it.
 *
 * mcp/sdk 0.9 strips every `listChanged` flag from the handshake `initialize`
 * result, because it only delivers list changes through `subscriptions/listen`.
 * Progressive discovery sends `notifications/tools/list_changed` on the
 * response stream of `toolsets.enable` and `toolsets.disable`, so the
 * capability this server configured is restored on the result. Without it a
 * client does not refetch the tool list after enabling a toolset.
 *
 * @implements RequestHandlerInterface<InitializeResult>
 */
final readonly class McpToolsetInitializeHandler implements RequestHandlerInterface
{
    public function __construct(
        private InitializeHandler $inner,
        private McpToolsetSelection $initial,
        private ServerCapabilities $advertised,
    ) {}

    public function supports(Request $request): bool
    {
        return $request instanceof InitializeRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        $session->set(McpToolsetSelection::SESSION_KEY, $this->initial->toSession());

        $response = $this->inner->handle($request, $session);
        $result = $response->result;
        if (! $this->advertised->toolsListChanged) {
            return $response;
        }

        return new Response($response->id, new InitializeResult(
            $this->advertised,
            $result->serverInfo,
            $result->instructions,
            $result->meta,
            $result->protocolVersion,
        ));
    }
}
