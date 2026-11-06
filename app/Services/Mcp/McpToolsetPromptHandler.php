<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Services\Mcp\Toolsets\McpToolVisibility;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Request\GetPromptRequest;
use Mcp\Schema\Result\GetPromptResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Fetching a prompt enables the toolsets of the tools that prompt walks the
 * model through, so a workflow the person picked does not stop at a tool that
 * is not listed yet. Only toolsets of tools this principal may already call
 * are enabled, and only the ones the session does not already show; nothing
 * is granted, and an unknown or unavailable prompt enables nothing.
 *
 * @implements RequestHandlerInterface<GetPromptResult>
 */
final readonly class McpToolsetPromptHandler implements RequestHandlerInterface
{
    /**
     * @param  RequestHandlerInterface<GetPromptResult>  $inner
     * @param  array<string, list<string>>  $toolsByPrompt  the tools each available prompt needs, by prompt name
     */
    public function __construct(
        private RequestHandlerInterface $inner,
        private McpToolVisibility $visibility,
        private array $toolsByPrompt,
    ) {}

    public function supports(Request $request): bool
    {
        return $this->inner->supports($request);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof GetPromptRequest) {
            $selection = McpToolsetSelection::fromSession($session->get(McpToolsetSelection::SESSION_KEY));
            $needed = $this->visibility->toolsetsNeededFor($this->toolsByPrompt[$request->name] ?? [], $selection);
            if ($needed !== []) {
                // Stored before the notification suspends this handler, as
                // the toolset tools do.
                $session->set(McpToolsetSelection::SESSION_KEY, $selection->enable($needed)->toSession());
                (new ClientGateway($session))->notify(new ToolListChangedNotification);
            }
        }

        return $this->inner->handle($request, $session);
    }
}
