<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpRestCall;
use App\Services\Mcp\AgentMcpTaskOrganizationTools;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Bherila\McpLaravelBridge\Mcp\RequestArguments;
use Illuminate\Http\Request;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionMethod;
use Tests\Concerns\RecordingAgentApiTransport;
use Tests\TestCase;

#[CoversClass(AgentMcpTaskOrganizationTools::class)]
#[CoversClass(AgentMcpRestCall::class)]
final class AgentMcpTaskOrganizationToolsTest extends TestCase
{
    public function test_schema_requires_explicit_containers_closed_task_fields_and_bounded_complete_orders(): void
    {
        $create = new ReflectionMethod(AgentMcpTaskOrganizationTools::class, 'tasksCreate');
        $parameters = [];
        foreach ($create->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }
        $task = $parameters['task']->getAttributes(Schema::class)[0]->newInstance()->definition;
        $this->assertSame(['title'], $task['required']);
        $this->assertFalse($task['additionalProperties']);
        $this->assertSame(['title', 'description', 'is_visible_to_client', 'starts_on', 'due_on', 'assignee_ids'], array_keys($task['properties']));
        $this->assertSame(10000, $task['properties']['description']['maxLength']);
        $this->assertSame(['type' => 'array', 'maxItems' => 10, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']], $task['properties']['assignee_ids']);
        foreach (['tasksCreate', 'tasksMove', 'tasksReorder'] as $method) {
            foreach ((new ReflectionMethod(AgentMcpTaskOrganizationTools::class, $method))->getParameters() as $parameter) {
                if (in_array($parameter->getName(), ['parent_id', 'list_id'], true)) {
                    $this->assertFalse($parameter->isOptional());
                    $this->assertTrue($parameter->allowsNull());
                }
                if (str_starts_with($parameter->getName(), 'expected_')) {
                    $schema = $parameter->getAttributes(Schema::class)[0]->newInstance();
                    $this->assertSame([64, 64], [$schema->minLength, $schema->maxLength]);
                }
            }
        }
        foreach (['listsReorder', 'tasksReorder'] as $method) {
            foreach ((new ReflectionMethod(AgentMcpTaskOrganizationTools::class, $method))->getParameters() as $parameter) {
                if ($parameter->getName() === 'ordered_ids') {
                    $schema = $parameter->getAttributes(Schema::class)[0]->newInstance();
                    $this->assertSame([100, true, ['type' => 'string', 'format' => 'uuid']], [$schema->maxItems, $schema->uniqueItems, $schema->items]);
                    $this->assertFalse($parameter->isOptional());
                }
            }
        }
    }

    public function test_every_adapter_dispatches_the_exact_route_body_revisions_confirmation_and_retry_key(): void
    {
        $version = str_repeat('a', 64);
        $task = ['title' => 'Synthetic transport task', 'description' => null, 'due_on' => null, 'is_visible_to_client' => false, 'assignee_ids' => []];
        $cases = [
            ['get', 'get', 'GET', [], ['workspace', 'project', true, 'parent', null], null, ['container' => true, 'parent_id' => 'parent', 'list_id' => null]],
            ['listsCreate', 'lists.create', 'POST', [], ['workspace', 'project', 'Synthetic list', $version, 'key', $this->context(['is_visible_to_client' => false]), false], ['name' => 'Synthetic list', 'expected_graph_version' => $version, 'is_visible_to_client' => false], []],
            ['listsUpdate', 'lists.update', 'PATCH', ['list'], ['workspace', 'project', 'list', $version, $version, 'key', $this->context(['name' => null, 'is_visible_to_client' => false]), null, false], ['expected_graph_version' => $version, 'expected_version' => $version, 'name' => null, 'is_visible_to_client' => false], []],
            ['listsUpdate', 'lists.update', 'PATCH', ['list'], ['workspace', 'project', 'list', $version, $version, 'key', $this->context([])], ['expected_graph_version' => $version, 'expected_version' => $version], []],
            ['listsArchive', 'lists.archive', 'DELETE', ['list'], ['workspace', 'project', 'list', $version, $version, true, 'key'], ['expected_graph_version' => $version, 'expected_version' => $version, 'confirm' => true], []],
            ['listsRestore', 'lists.restore', 'POST', ['list'], ['workspace', 'project', 'list', $version, $version, 'key'], ['expected_graph_version' => $version, 'expected_version' => $version], []],
            ['listsReorder', 'lists.reorder', 'PUT', [], ['workspace', 'project', [], $version, 'key'], ['expected_container_version' => $version, 'ordered_ids' => []], []],
            ['tasksCreate', 'tasks.create', 'POST', [], ['workspace', 'project', $task, null, 'list', $version, 'key'], ['expected_graph_version' => $version, 'task' => $task, 'parent_id' => null, 'list_id' => 'list'], []],
            ['tasksMove', 'tasks.move', 'PATCH', ['task'], ['workspace', 'project', 'task', null, null, $version, $version, 'key'], ['expected_graph_version' => $version, 'expected_version' => $version, 'parent_id' => null, 'list_id' => null], []],
            ['tasksReorder', 'tasks.reorder', 'PUT', [], ['workspace', 'project', 'parent', null, ['second', 'first'], $version, 'key'], ['expected_container_version' => $version, 'ordered_ids' => ['second', 'first'], 'parent_id' => 'parent', 'list_id' => null], []],
        ];
        foreach ($cases as [$method, $operation, $verb, $extra, $arguments, $body, $query]) {
            $registry = new OperationRegistry;
            $template = app(OperationRegistry::class)->find($verb === 'GET' ? 'tasks.list' : 'tasks.update');
            $this->assertNotNull($template);
            $parameters = ['workspace', 'project', ...$extra];
            $path = '/synthetic-organization/'.implode('/', array_map(static fn (string $name): string => '{'.$name.'}', $parameters));
            $registry->register($template->with(id: 'task_organization.'.$operation,
                rest: new RestBinding($verb, $path, pathParameters: $parameters), mcp: null));
            $transport = new RecordingAgentApiTransport(200, ['data' => ['synthetic' => true]]);
            $context = null;
            foreach ($arguments as $argument) {
                if ($argument instanceof RequestContext) {
                    $context = $argument;
                }
            }
            $raw = $context === null ? '' : json_encode($context->getRequest(), JSON_THROW_ON_ERROR);
            $tools = new AgentMcpTaskOrganizationTools(new AgentMcpRestCall($transport, $registry), new RequestArguments(Request::create('/', 'POST', content: $raw)));
            $this->assertSame(['data' => ['synthetic' => true]], $tools->$method(...$arguments));
            $this->assertSame([['method' => $verb, 'path' => 'synthetic-organization/'.implode('/', $parameters),
                'query' => $query, 'json' => $body, 'headers' => $verb === 'GET' ? [] : ['Idempotency-Key' => 'key']]], $transport->requests, $method);
        }
    }

    /** @param array<string,mixed> $arguments */
    private function context(array $arguments): RequestContext
    {
        return new RequestContext(new Session(new InMemorySessionStore), CallToolRequest::fromArray([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'synthetic-organization', 'arguments' => $arguments],
        ]));
    }
}
