<?php

namespace App\Services\Mcp;

use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\InvalidCursorException;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Prompt;
use Mcp\Schema\Request\ListPromptsRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListPromptsResult;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

/** @implements RequestHandlerInterface<ListToolsResult|ListPromptsResult> */
final readonly class McpProgressiveDiscoveryHandler implements RequestHandlerInterface
{
    /** @param list<Operation> $operations */
    public function __construct(private RegistryInterface $registry, private AgentMcpToolsets $toolsets, private array $operations) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof ListPromptsRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof ListToolsRequest) {
            $tools = array_values(array_filter($this->registry->getTools()->references, fn (Tool $tool): bool => $this->toolsets->visible($tool->name, $session)));
            [$page, $cursor] = $this->page($tools, $request->cursor);

            return new Response($request->getId(), new ListToolsResult($page, $cursor));
        }
        if ($request instanceof ListPromptsRequest) {
            $prompts = array_values(array_filter($this->registry->getPrompts()->references, fn (Prompt $prompt): bool => $this->promptVisible($prompt->name, $session)));
            [$page, $cursor] = $this->page($prompts, $request->cursor);

            return new Response($request->getId(), new ListPromptsResult($page, $cursor));
        }

        return new Error($request->getId(), -32002, 'Prompt not found or not available.');
    }

    public function promptVisible(string $name, SessionInterface $session): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->mcp?->kind === McpKind::Prompt && $operation->mcpName() === $name) {
                return array_all($operation->requiresOperations, fn (string $tool): bool => $this->toolsets->visible($tool, $session));
            }
        }

        return false;
    }

    /** @template T
     * @param list<T> $items
     * @return array{list<T>, ?string} */
    private function page(array $items, ?string $cursor): array
    {
        $decoded = $cursor === null ? '0' : base64_decode($cursor, true);
        if (! is_string($decoded) || ! ctype_digit($decoded) || (int) $decoded > count($items)) {
            throw new InvalidCursorException($cursor ?? '');
        }
        $offset = (int) $decoded;

        return [array_slice($items, $offset, 100), $offset + 100 < count($items) ? base64_encode((string) ($offset + 100)) : null];
    }
}
