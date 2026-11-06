<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\InitializeRequest;
use Mcp\Schema\Result\InitializeResult;
use Mcp\Server\Handler\Request\InitializeHandler;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Records, once per session, whether the connection lists every tool or
 * discovers toolsets progressively. The choice is made at connect time and
 * lives in the session from then on, so a later request cannot change it.
 *
 * @implements RequestHandlerInterface<InitializeResult>
 */
final readonly class McpToolsetInitializeHandler implements RequestHandlerInterface
{
    public function __construct(
        private InitializeHandler $inner,
        private McpToolsetSelection $initial,
    ) {}

    public function supports(Request $request): bool
    {
        return $request instanceof InitializeRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        $session->set(McpToolsetSelection::SESSION_KEY, $this->initial->toSession());

        return $this->inner->handle($request, $session);
    }
}
