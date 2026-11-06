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

/** @implements RequestHandlerInterface<mixed> */
final readonly class McpProgressiveDiscoveryHandler implements RequestHandlerInterface
{
    /** @param list<Operation> $operations */
    public function __construct(private RegistryInterface $registry, private AgentMcpToolsets $toolsets, private array $operations) {}

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest || $request instanceof ListPromptsRequest;
    }

    /** @return Response<ListToolsResult>|Response<ListPromptsResult>|Error */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof ListToolsRequest) {
            $tools = [];
            foreach ($this->registry->getTools()->references as $tool) {
                if ($tool instanceof Tool && $this->toolsets->visible($tool->name, $session)) {
                    $tools[] = $tool;
                }
            }
            [$page, $cursor] = $this->page($tools, $request->cursor);

            return new Response($request->getId(), new ListToolsResult($page, $cursor));
        }
        if ($request instanceof ListPromptsRequest) {
            $prompts = [];
            foreach ($this->registry->getPrompts()->references as $prompt) {
                if ($prompt instanceof Prompt && $this->promptVisible($prompt->name, $session)) {
                    $prompts[] = $prompt;
                }
            }
            [$page, $cursor] = $this->page($prompts, $request->cursor);

            return new Response($request->getId(), new ListPromptsResult($page, $cursor));
        }

        return new Error($request->getId(), -32002, 'Prompt not found or not available.');
    }

    public function promptVisible(string $name, SessionInterface $session): bool
    {
        foreach ($this->operations as $operation) {
            // @infection-ignore-all The factory passes only MCP-bound operations; null-safe access is defensive and removing it changes no supported request.
            $kind = $operation->mcp?->kind;
            if ($kind === McpKind::Prompt && $operation->mcpName() === $name) {
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
        $cursor = $cursor ?? base64_encode('0');
        $decoded = base64_decode($cursor, true);
        if (! is_string($decoded) || ! ctype_digit($decoded)) {
            throw new InvalidCursorException($cursor);
        }
        // @infection-ignore-all ctype_digit established a numeric string; weak PHP and array_slice coerce it identically. The cast is required by static analysis.
        $offset = (int) $decoded;
        if ($offset > count($items)) {
            throw new InvalidCursorException($cursor);
        }
        $next = $offset + 100;
        $nextCursor = null;
        if ($next < count($items)) {
            // @infection-ignore-all base64_encode weakly coerces this integer to the same decimal string; the explicit cast is required by static analysis.
            $text = (string) $next;
            $nextCursor = base64_encode($text);
        }

        return [array_slice($items, $offset, 100), $nextCursor];
    }
}
