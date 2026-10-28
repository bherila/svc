<?php

namespace Tests\Unit\Mcp;

use App\Services\Mcp\AgentMcpRestCall;
use App\Services\Mcp\AgentMcpTaskPlanningTools;
use App\Services\Mcp\AgentMcpToolSchemas;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\RestBinding;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Concerns\RecordingAgentApiTransport;
use Tests\TestCase;

#[CoversClass(AgentMcpTaskPlanningTools::class)]
#[CoversClass(AgentMcpRestCall::class)]
final class AgentMcpTaskPlanningToolsTest extends TestCase
{
    public function test_all_fifteen_methods_use_exact_registered_paths_closed_bodies_and_retry_keys(): void
    {
        $this->assertDispatchBindings();
    }

    private function assertDispatchBindings(): void
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

    public function test_the_actual_published_schemas_enforce_route_query_and_authoritative_body_contracts(): void
    {
        // Execute the real adapters in this test as well: mutation coverage must
        // select the assertions for the methods whose signature lines it changes.
        $this->assertDispatchBindings();
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $workspace = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $token = str_repeat('a', 64);
        $validator = new SchemaValidator;
        $schemas = new AgentMcpToolSchemas;
        $contract = [
            'get' => ['task', null], 'labels.list' => [null, null], 'labels.get' => ['label', null],
            'labels.options' => ['project', null], 'dependencies.get' => ['task', null],
            'dependencies.options' => ['task', null], 'contacts.get' => ['task', null], 'contacts.options' => ['task', null],
            'labels.create' => [null, ['name' => str_repeat('é', 80)]],
            'labels.update' => ['label', ['name' => 'Synthetic label', 'expected_version' => $token]],
            'labels.archive' => ['label', ['expected_version' => $token, 'confirm' => true]],
            'labels.restore' => ['label', ['expected_version' => $token]],
            'labels.replace' => ['task', ['expected_version' => $token, 'label_ids' => []]],
            'dependencies.replace' => ['task', ['expected_version' => $token, 'expected_graph_version' => $token, 'prerequisite_ids' => []]],
            'contacts.replace' => ['task', ['expected_version' => $token, 'contact_ids' => []]],
        ];
        foreach ($contract as $suffix => [$target, $body]) {
            $operation = app(OperationRegistry::class)->find('task_planning.'.$suffix);
            $this->assertNotNull($operation);
            $schema = $schemas->input($operation);
            $valid = ['workspace_id' => $workspace] + ($target === null ? [] : [$target.'_id' => $workspace])
                + ($body === null ? [] : $body + ['idempotency_key' => 'synthetic-receipt']);
            $this->assertSame([], $validator->validateAgainstJsonSchema($valid, $schema), $suffix);
            $this->assertFalse($schema['additionalProperties']);
            $this->assertNotEmpty($validator->validateAgainstJsonSchema($valid + ['origin' => 'internal'], $schema));
            foreach (array_filter(['workspace_id', $target === null ? null : $target.'_id']) as $name) {
                $this->assertSame($uuid, $schema['properties'][$name], $suffix.'/'.$name);
                $this->assertContains($name, $schema['required']);
                $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, [$name => 'foreign-or-malformed']), $schema));
                $missing = $valid;
                unset($missing[$name]);
                $this->assertNotEmpty($validator->validateAgainstJsonSchema($missing, $schema));
            }
            if ($body !== null) {
                $this->assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], $schema['properties']['idempotency_key']);
                $this->assertSame([], $validator->validateAgainstJsonSchema(array_replace($valid, ['idempotency_key' => str_repeat('k', 255)]), $schema));
                foreach (['', str_repeat('k', 256)] as $bad) {
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, ['idempotency_key' => $bad]), $schema));
                }
                foreach ($body as $name => $value) {
                    $this->assertContains($name, $schema['required']);
                    $missing = $valid;
                    unset($missing[$name]);
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema($missing, $schema), $suffix.'/'.$name);
                    if (str_starts_with($name, 'expected_')) {
                        foreach ([str_repeat('a', 63), str_repeat('a', 65), str_repeat('g', 64), null] as $bad) {
                            $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, [$name => $bad]), $schema));
                        }
                    } elseif ($name === 'name') {
                        foreach (['', str_repeat('é', 81), null] as $bad) {
                            $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, [$name => $bad]), $schema));
                        }
                    } elseif ($name === 'confirm') {
                        $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, [$name => false]), $schema));
                    } else {
                        $cap = match ($name) {
                            'label_ids' => 20, 'prerequisite_ids' => 25, 'contact_ids' => 10
                        };
                        $ids = array_map(static fn (int $index): string => sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', $index), range(1, $cap + 1));
                        $this->assertSame([], $validator->validateAgainstJsonSchema(array_replace($valid, [$name => array_slice($ids, 0, $cap)]), $schema));
                        foreach ([$ids, [$workspace, $workspace], ['not-a-uuid'], null] as $bad) {
                            $this->assertNotEmpty($validator->validateAgainstJsonSchema(array_replace($valid, [$name => $bad]), $schema));
                        }
                    }
                }
            }
            $paged = in_array($suffix, ['labels.list', 'labels.options', 'dependencies.get', 'dependencies.options', 'contacts.options'], true);
            if ($paged) {
                $this->assertSame(['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 25], $schema['properties']['limit']);
                $this->assertSame(['type' => ['null', 'string'], 'default' => null, 'maxLength' => 2048], $schema['properties']['cursor']);
                foreach ([1, 25] as $limit) {
                    $this->assertSame([], $validator->validateAgainstJsonSchema($valid + ['limit' => $limit, 'cursor' => str_repeat('c', 2048)], $schema));
                }
                foreach ([0, 26, '25'] as $limit) {
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema($valid + ['limit' => $limit], $schema));
                }
                $this->assertNotEmpty($validator->validateAgainstJsonSchema($valid + ['cursor' => str_repeat('c', 2049)], $schema));
                if ($suffix !== 'dependencies.get') {
                    $this->assertSame(['type' => 'string', 'default' => '', 'maxLength' => 100], $schema['properties']['q']);
                    $this->assertSame([], $validator->validateAgainstJsonSchema($valid + ['q' => str_repeat('é', 100)], $schema));
                    $this->assertNotEmpty($validator->validateAgainstJsonSchema($valid + ['q' => str_repeat('é', 101)], $schema));
                }
            }
            if ($suffix === 'labels.list') {
                $this->assertSame(['type' => 'string', 'default' => 'active', 'enum' => ['active', 'archived', 'all']], $schema['properties']['status']);
                foreach (['active', 'archived', 'all'] as $status) {
                    $this->assertSame([], $validator->validateAgainstJsonSchema($valid + ['status' => $status], $schema));
                }
                $this->assertNotEmpty($validator->validateAgainstJsonSchema($valid + ['status' => 'hidden'], $schema));
            }
        }
    }
}
