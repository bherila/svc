<?php

namespace App\Services\Mcp;

use App\Services\Mcp\Toolsets\McpToolPage;
use App\Services\Mcp\Toolsets\McpToolsetSelection;
use App\Services\Mcp\Toolsets\McpToolVisibility;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * tools/list for a session: the authorized tools the session currently shows,
 * in the registry's order and paged with the SDK's cursor.
 *
 * @implements RequestHandlerInterface<ListToolsResult>
 */
final readonly class McpToolsetListToolsHandler implements RequestHandlerInterface
{
    public function __construct(
        private RegistryInterface $registry,
        private McpToolVisibility $visibility,
        private int $pageSize,
    ) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        \assert($request instanceof ListToolsRequest);
        $selection = McpToolsetSelection::fromSession($session->get(McpToolsetSelection::SESSION_KEY));
        $tools = [];
        foreach ($this->registry->getTools()->references as $name => $tool) {
            if ($this->visibility->isVisible((string) $name, $selection)) {
                $tools[(string) $name] = $tool;
            }
        }
        $page = McpToolPage::slice(array_keys($tools), $this->pageSize, $request->cursor);

        return new Response(
            $request->getId(),
            new ListToolsResult(array_map(static fn (string $name): Tool => $tools[$name], $page['names']), $page['next']),
        );
    }
}
