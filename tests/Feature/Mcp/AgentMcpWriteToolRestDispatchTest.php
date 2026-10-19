<?php

namespace Tests\Feature\Mcp;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use Bherila\McpLaravelBridge\Capabilities\McpKind;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Bherila\McpLaravelBridge\Http\AgentApiMultipart;
use Bherila\McpLaravelBridge\Http\AgentApiTransport;
use Bherila\McpLaravelBridge\Http\AgentApiTransportResponse;
use Bherila\McpLaravelBridge\Http\InternalAgentApiTransport;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Concerns\RecordingAgentApiTransport;
use Tests\TestCase;
use Throwable;

/**
 * Every MCP write tool is its REST operation (#459): called, the handler
 * sends exactly one request, to the method and path its operation's REST
 * binding documents, and does nothing else itself - no action, validation or
 * authorization of its own. A new write tool that calls an action directly
 * fails here; so does one that reaches another operation's route.
 *
 * The handlers run against a recording transport with synthetic arguments,
 * so this needs no database: a handler that tries to do the work itself
 * fails before the transport is reached.
 */
final class AgentMcpWriteToolRestDispatchTest extends TestCase
{
    /**
     * Write tools that still run their action directly, with the reason.
     * Shrink only: an entry that starts dispatching through REST fails below.
     *
     * @var array<string, string>
     */
    private const array DIRECT = [
        'payments.correct' => 'Payment tools move with the payment routes and scopes (#457, #473).',
        'payments.record' => 'Payment tools move with the payment routes and scopes (#457, #473).',
        'payments.refund' => 'Payment tools move with the payment routes and scopes (#457, #473).',
        'payments.set_status' => 'Payment tools move with the payment routes and scopes (#457, #473).',
    ];

    public function test_every_write_tool_sends_one_request_to_its_own_rest_operation(): void
    {
        $writes = $this->writeTools();
        $this->assertNotEmpty($writes);
        // The finance reconciliation writes (#457) dispatch like every other.
        foreach (['finance.reconciliations.upsert', 'finance.reconciliations.deactivate'] as $finance) {
            $this->assertArrayHasKey($finance, $writes);
            $this->assertArrayNotHasKey($finance, self::DIRECT);
        }
        $this->assertSame([], array_values(array_diff(array_keys(self::DIRECT), array_keys($writes))), 'An allowlisted tool is no longer a write tool.');

        foreach ($writes as $id => $operation) {
            $rest = $operation->rest;
            $this->assertNotNull($rest, "{$id} is an MCP write tool with no REST operation.");
            [$requests, $failure] = $this->dispatch($operation);
            if (isset(self::DIRECT[$id])) {
                $this->assertSame([], $requests, "{$id} now reaches REST: remove it from DIRECT.");

                continue;
            }
            $this->assertNull($failure, "{$id} did not reach its REST operation: ".($failure?->getMessage() ?? ''));
            $this->assertCount(1, $requests, "{$id} must send exactly one request.");
            $pattern = '#^'.preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/?]+', preg_quote(ltrim($rest->path, '/'), '#')).'(\?.*)?$#';
            $this->assertSame([$rest->method, 1], [$requests[0]['method'], preg_match($pattern, $requests[0]['path'])], "{$id} sent {$requests[0]['method']} {$requests[0]['path']}, not its own {$rest->method} {$rest->path}.");
        }
    }

    /** @return array<string, Operation> */
    private function writeTools(): array
    {
        $writes = [];
        foreach (app(AgentOperationCatalog::class)->registry()->all() as $operation) {
            if ($operation->mcp !== null && $operation->mcp->kind === McpKind::Tool && ! $operation->effect->readOnly()) {
                $writes[$operation->id] = $operation;
            }
        }
        ksort($writes);

        return $writes;
    }

    /** @return array{list<array<string, mixed>>, ?Throwable} */
    private function dispatch(Operation $operation): array
    {
        $recording = new RecordingAgentApiTransport(200, ['data' => ['id' => '00000000-0000-4000-8000-000000000000']]);
        $this->app->instance(AgentApiTransport::class, $recording);
        $this->app->instance(InternalAgentApiTransport::class, $this->internal($recording));
        $handler = $operation->mcp?->handler;
        $this->assertIsArray($handler, "{$operation->id} has no handler method.");
        [$class, $method] = $handler;
        $instance = $this->app->make($class);
        $reflection = new ReflectionMethod($instance, $method);
        $arguments = [];
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                break;
            }
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $schema = ($parameter->getAttributes(Schema::class)[0] ?? null)?->newInstance();
            $arguments[] = match ($type->getName()) {
                RequestContext::class => new RequestContext(new Session(new InMemorySessionStore), CallToolRequest::fromArray(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => (string) $operation->mcpName(), 'arguments' => []]])),
                'string' => $schema?->enum[0] ?? ($schema?->format === 'uuid' ? '00000000-0000-4000-8000-000000000001' : 'synthetic'),
                'int' => 1,
                'bool' => true,
                'array' => [],
                default => $this->fail("{$operation->id}: no synthetic value for {$parameter->getName()}."),
            };
        }
        try {
            $reflection->invokeArgs($instance, $arguments);
            $failure = null;
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        return [$recording->requests, $failure];
    }

    /** The Square tools still take the concrete transport; this one records instead. */
    private function internal(RecordingAgentApiTransport $recording): InternalAgentApiTransport
    {
        return new class($recording) extends InternalAgentApiTransport
        {
            public function __construct(private readonly RecordingAgentApiTransport $recording) {}

            public function send(string $method, string $path, array $query = [], ?array $json = null, ?AgentApiMultipart $multipart = null, array $headers = []): AgentApiTransportResponse
            {
                return $this->recording->send($method, $path, $query, $json, $multipart, $headers);
            }
        };
    }
}
