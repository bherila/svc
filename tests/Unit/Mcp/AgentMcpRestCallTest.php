<?php

namespace Tests\Unit\Mcp;

use App\Models\AgentPrincipal;
use App\Services\Mcp\AgentMcpRestCall;
use App\Services\Mcp\Context\McpPrincipal;
use App\Services\Mcp\Context\McpRequestContext;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use LogicException;
use Mcp\Exception\ToolCallException;
use Tests\Concerns\RecordingAgentApiTransport;
use Tests\TestCase;

final class AgentMcpRestCallTest extends TestCase
{
    public function test_it_sends_the_operations_documented_method_and_path(): void
    {
        $transport = new RecordingAgentApiTransport(201, ['data' => ['id' => 'synthetic']]);
        $call = new AgentMcpRestCall($transport, app(OperationRegistry::class));

        $result = $call->call('tasks.update', ['task' => 'b/c', 'workspace' => 'a'], ['title' => 'Synthetic'], 'synthetic-key', ['view' => 'read']);

        $this->assertSame(['data' => ['id' => 'synthetic']], $result);
        $this->assertSame([[
            'method' => 'PATCH',
            'path' => 'workspaces/a/tasks/b%2Fc?view=read',
            'query' => [],
            'json' => ['title' => 'Synthetic'],
            'headers' => ['Idempotency-Key' => 'synthetic-key'],
        ]], $transport->requests);
    }

    public function test_a_read_sends_its_query_as_the_query_and_no_body_or_idempotency_key(): void
    {
        $transport = new RecordingAgentApiTransport;
        (new AgentMcpRestCall($transport, app(OperationRegistry::class)))->call('tasks.list', ['workspace' => 'a'], query: ['limit' => 5]);

        $this->assertSame([['method' => 'GET', 'path' => 'workspaces/a/tasks', 'query' => ['limit' => 5], 'json' => null, 'headers' => []]], $transport->requests);
    }

    public function test_a_write_without_a_query_has_a_bare_path(): void
    {
        $transport = new RecordingAgentApiTransport;
        (new AgentMcpRestCall($transport, app(OperationRegistry::class)))->call('tasks.create', ['workspace' => 'a', 'project' => 'b'], ['title' => 'Synthetic']);

        $this->assertSame('workspaces/a/projects/b/tasks', $transport->requests[0]['path']);
    }

    public function test_the_mcp_request_id_travels_with_the_call(): void
    {
        $transport = new RecordingAgentApiTransport;
        $context = new McpRequestContext(new McpPrincipal(new AgentPrincipal, 'credential', 'client', []), 'synthetic-request-id');
        (new AgentMcpRestCall($transport, app(OperationRegistry::class)))->forContext($context)
            ->call('tasks.create', ['workspace' => 'a', 'project' => 'b'], [], 'synthetic-key');

        $this->assertSame(['Idempotency-Key' => 'synthetic-key', 'X-Request-Id' => 'synthetic-request-id'], $transport->requests[0]['headers']);
    }

    public function test_an_unknown_operation_or_wrong_path_parameters_never_reach_the_transport(): void
    {
        $transport = new RecordingAgentApiTransport;
        $call = new AgentMcpRestCall($transport, app(OperationRegistry::class));
        foreach ([
            ['synthetic.unknown', ['workspace' => 'a']],
            ['tasks.update', ['workspace' => 'a']],
            ['tasks.update', ['workspace' => 'a', 'task' => 'b', 'project' => 'c']],
            ['tasks.update', ['workspace' => 'a', 'project' => 'b']],
            // An MCP-only operation has no route to call.
            ['resources.current_context', []],
        ] as [$operation, $path]) {
            try {
                $call->call($operation, $path);
                $this->fail("{$operation} reached the transport.");
            } catch (LogicException) {
            }
        }
        $this->assertSame([], $transport->requests);
    }

    public function test_only_refusals_written_for_the_caller_cross_back(): void
    {
        foreach ([
            [409, ['message' => 'The task changed. Read it again.'], 'The task changed. Read it again.'],
            [422, ['message' => 'Invalid.', 'errors' => ['title' => ['The title is required.']]], 'Invalid. The title is required.'],
            [403, ['message' => 'Internal synthetic detail.'], 'This connection lacks the required permission.'],
            [500, ['message' => 'Internal synthetic detail.'], 'The SVC API request could not be completed.'],
            [200, null, 'The SVC API request could not be completed.'],
            [302, ['data' => []], 'The SVC API request could not be completed.'],
        ] as [$status, $body, $message]) {
            $call = new AgentMcpRestCall(new RecordingAgentApiTransport($status, $body), app(OperationRegistry::class));
            try {
                $call->call('tasks.create', ['workspace' => 'a', 'project' => 'b'], [], 'synthetic-key');
                $this->fail("{$status} was treated as success.");
            } catch (ToolCallException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function test_every_success_status_is_returned(): void
    {
        foreach ([200, 201, 202, 299] as $status) {
            $call = new AgentMcpRestCall(new RecordingAgentApiTransport($status, ['data' => [$status]]), app(OperationRegistry::class));
            $this->assertSame(['data' => [$status]], $call->call('context.get', []));
        }
    }
}
