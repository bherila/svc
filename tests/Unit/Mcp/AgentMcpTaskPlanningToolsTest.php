<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpRestCall;
use App\Services\Mcp\AgentMcpTaskPlanningTools;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Mcp\Capability\Attribute\Schema;
use ReflectionMethod;
use Tests\Concerns\RecordingAgentApiTransport;
use Tests\TestCase;

final class AgentMcpTaskPlanningToolsTest extends TestCase
{
    public function test_all_fifteen_methods_use_exact_registered_paths_closed_bodies_and_retry_keys(): void
    {
        $v = str_repeat('a', 64);
        $cases = [
            ['get', 'get', 'GET', 'task', ['w', 't'], null, []],
            ['labelsList', 'labels.list', 'GET', null, ['w', 'archived', 'needle', 7, 'cursor'], null, ['status' => 'archived', 'q' => 'needle', 'limit' => 7, 'cursor' => 'cursor']],
            ['labelsGet', 'labels.get', 'GET', 'label', ['w', 'l'], null, []],
            ['labelsOptions', 'labels.options', 'GET', 'project', ['w', 'p', 'needle', 7, 'cursor'], null, ['q' => 'needle', 'limit' => 7, 'cursor' => 'cursor']],
            ['dependenciesGet', 'dependencies.get', 'GET', 'task', ['w', 't', 7, 'cursor'], null, ['limit' => 7, 'cursor' => 'cursor']],
            ['dependenciesOptions', 'dependencies.options', 'GET', 'task', ['w', 't', 'needle', 7, 'cursor'], null, ['q' => 'needle', 'limit' => 7, 'cursor' => 'cursor']],
            ['contactsGet', 'contacts.get', 'GET', 'task', ['w', 't'], null, []],
            ['contactsOptions', 'contacts.options', 'GET', 'task', ['w', 't', 'needle', 7, 'cursor'], null, ['q' => 'needle', 'limit' => 7, 'cursor' => 'cursor']],
            ['labelsCreate', 'labels.create', 'POST', null, ['w', 'Synthetic', 'key'], ['name' => 'Synthetic'], []],
            ['labelsUpdate', 'labels.update', 'PATCH', 'label', ['w', 'l', 'Synthetic', $v, 'key'], ['name' => 'Synthetic', 'expected_version' => $v], []],
            ['labelsArchive', 'labels.archive', 'DELETE', 'label', ['w', 'l', $v, true, 'key'], ['expected_version' => $v, 'confirm' => true], []],
            ['labelsRestore', 'labels.restore', 'POST', 'label', ['w', 'l', $v, 'key'], ['expected_version' => $v], []],
            ['labelsReplace', 'labels.replace', 'PUT', 'task', ['w', 't', $v, [], 'key'], ['expected_version' => $v, 'label_ids' => []], []],
            ['dependenciesReplace', 'dependencies.replace', 'PUT', 'task', ['w', 't', $v, $v, [], 'key'], ['expected_version' => $v, 'expected_graph_version' => $v, 'prerequisite_ids' => []], []],
            ['contactsReplace', 'contacts.replace', 'PUT', 'task', ['w', 't', $v, [], 'key'], ['expected_version' => $v, 'contact_ids' => []], []],
        ];
        foreach ($cases as [$method, $suffix, $verb, $target, $arguments, $body, $query]) {
            $registry = new OperationRegistry;
            $template = app(OperationRegistry::class)->find($verb === 'GET' ? 'tasks.list' : 'tasks.update');
            $this->assertNotNull($template);
            $names = $target === null ? ['workspace'] : ['workspace', $target];
            $path = '/synthetic-planning/'.implode('/', array_map(fn (string $name): string => '{'.$name.'}', $names));
            $registry->register($template->with(id: 'task_planning.'.$suffix, rest: new RestBinding($verb, $path, pathParameters: $names), mcp: null));
            $transport = new RecordingAgentApiTransport(200, ['data' => ['synthetic' => true], 'meta' => ['next_cursor' => 'opaque']]);
            $tools = new AgentMcpTaskPlanningTools(new AgentMcpRestCall($transport, $registry));
            $this->assertSame(['data' => ['synthetic' => true], 'meta' => ['next_cursor' => 'opaque']], $tools->$method(...$arguments));
            $this->assertSame([['method' => $verb, 'path' => 'synthetic-planning/'.implode('/', array_slice($arguments, 0, count($names))),
                'query' => $query, 'json' => $body, 'headers' => $verb === 'GET' ? [] : ['Idempotency-Key' => 'key']]], $transport->requests, $method);
        }
    }

    public function test_schema_bounds_lists_versions_queries_and_explicit_true_confirmation(): void
    {
        foreach (['get', 'labelsList', 'labelsGet', 'labelsOptions', 'dependenciesGet', 'dependenciesOptions', 'contactsGet', 'contactsOptions',
            'labelsCreate', 'labelsUpdate', 'labelsArchive', 'labelsRestore', 'labelsReplace', 'dependenciesReplace', 'contactsReplace'] as $method) {
            foreach ((new ReflectionMethod(AgentMcpTaskPlanningTools::class, $method))->getParameters() as $parameter) {
                $name = $parameter->getName();
                $attributes = $parameter->getAttributes(Schema::class);
                $this->assertCount(1, $attributes, $method.'/'.$name);
                $schema = $attributes[0]->newInstance();
                if (in_array($name, ['workspace_id', 'task_id', 'label_id', 'project_id'], true)) {
                    $this->assertSame('uuid', $schema->format);
                } elseif (str_starts_with($name, 'expected_')) {
                    $this->assertSame([64, 64], [$schema->minLength, $schema->maxLength]);
                } elseif (str_ends_with($name, '_ids')) {
                    $this->assertSame(match ($name) {
                        'label_ids' => 20, 'contact_ids' => 10, 'prerequisite_ids' => 25
                    }, $schema->maxItems);
                    $this->assertTrue($schema->uniqueItems);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $schema->items);
                    $this->assertFalse($parameter->isOptional());
                } elseif ($name === 'confirm') {
                    $this->assertSame(['type' => 'boolean', 'const' => true], $schema->definition);
                } elseif ($name === 'name' || $name === 'idempotency_key') {
                    $this->assertSame([1, $name === 'name' ? 80 : 255], [$schema->minLength, $schema->maxLength]);
                } elseif ($name === 'limit') {
                    $this->assertSame([1, 25, 25], [$schema->minimum, $schema->maximum, $parameter->getDefaultValue()]);
                } elseif ($name === 'status') {
                    $this->assertSame(['active', 'archived', 'all'], $schema->enum);
                } else {
                    $this->assertSame($name === 'q' ? 100 : 2048, $schema->maxLength);
                }
            }
        }
    }
}
