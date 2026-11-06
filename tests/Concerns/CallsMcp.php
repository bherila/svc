<?php

namespace Tests\Concerns;

use App\Services\Mcp\Toolsets\McpToolsetSelection;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/** Drives the JSON-RPC MCP endpoint the way a connected client does. */
trait CallsMcp
{
    protected function initialize(): string
    {
        $response = $this->mcp($this->initializeMessage())->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @return array<string, mixed> */
    protected function initializeMessage(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']]];
    }

    /**
     * Connect the way a client that takes the default does: progressive
     * discovery, the core tools first. Every test connects as a client that
     * opts into the full list unless it says otherwise (see TestCase::setUp).
     */
    protected function initializeProgressive(): string
    {
        $response = $this->mcp($this->initializeMessage(), headers: [McpToolsetSelection::CONNECT_HEADER => 'progressive'])->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /**
     * @param  array<string, mixed>  $message
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function mcp(array $message, ?string $session = null, array $headers = []): TestResponse
    {
        $headers += ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }

    /**
     * Every JSON-RPC message a response carried, in order. A call that sends a
     * notification is answered as an event stream (notification first, then the
     * result); any other call is plain JSON.
     *
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    protected function messages(TestResponse $response): array
    {
        $response->assertOk();
        if (! str_starts_with((string) $response->headers->get('Content-Type'), 'text/event-stream')) {
            return [(array) $response->json()];
        }
        $messages = [];
        foreach (preg_split('/\R/', $response->streamedContent()) ?: [] as $line) {
            if (str_starts_with($line, 'data:')) {
                $messages[] = (array) json_decode(trim(substr($line, 5)), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        return $messages;
    }

    /**
     * Call a tool inside an initialised session and return the JSON-RPC body.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function callTool(string $session, string $name, array $arguments): array
    {
        return $this->mcp(['jsonrpc' => '2.0', 'id' => 100, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]], $session)
            ->assertOk()->json();
    }

    /** @return list<string> */
    protected function toolNames(string $session): array
    {
        return array_column($this->listTools($session), 'name');
    }

    /**
     * Every tool `tools/list` offers, following `nextCursor` as a client does:
     * discovery pages hold at most 100 tools, and there are more than that.
     *
     * @return list<array<string, mixed>>
     */
    protected function listTools(string $session): array
    {
        $tools = [];
        $cursor = null;
        for ($page = 0; $page < 20; $page++) {
            $result = $this->mcp(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'tools/list', 'params' => $cursor === null ? [] : ['cursor' => $cursor]], $session)
                ->assertOk()->json('result');
            $tools = [...$tools, ...(array) ($result['tools'] ?? [])];
            $cursor = $result['nextCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '') {
                return $tools;
            }
        }

        throw new \LogicException('tools/list did not stop paginating.');
    }
}
