<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\Mcp\AgentMcpReadTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentOpenApi;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Capabilities\OperationToolFactory;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Public lookup contracts are built from the live registry, without a database. */
#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentRestDocumentation::class)]
final class TaskAssigneeOperationContractTest extends TestCase
{
    public function test_the_registered_mcp_lookup_resolves_its_handler_and_promises_safe_repeated_reads(): void
    {
        $this->withRegistry(function (OperationRegistry $registry): void {
            $operation = $registry->find('tasks.assignees.list');
            $this->assertNotNull($operation);
            $factory = new OperationToolFactory(AgentApiResponseSchemaCatalog::catalog());
            // Reflecting the real binding also proves that its class and method resolve.
            $tool = $factory->tool($operation);
            $definition = $factory->definition($operation);
            $this->assertSame([AgentMcpReadTools::class, 'taskAssignees'], $definition->handler);
            $this->assertSame('tasks.assignees.list', $definition->name);
            $this->assertTrue($definition->readOnly);
            $this->assertFalse($definition->destructive);
            $this->assertTrue($definition->idempotent, 'A retry of an assignee search must be advertised as safe.');
            $this->assertSame(Effect::Read, $operation->effect);
            $this->assertTrue($tool->annotations->readOnlyHint);
            $this->assertSame(['tasks:read'], $operation->requirement->scopes);
            $this->assertSame(['workspace_id', 'project_id', 'q'], array_keys($tool->inputSchema['properties']));
            $this->assertSame(['workspace_id', 'project_id'], $tool->inputSchema['required']);
            $this->assertFalse($tool->inputSchema['additionalProperties']);
        });
    }

    public function test_generated_rest_lookup_requires_tenant_and_project_and_accepts_only_bounded_text_searches(): void
    {
        $this->withRegistry(function (): void {
            $document = AgentOpenApi::checked()->full();
            $operation = $document['paths']['/workspaces/{workspace}/projects/{project}/task-assignees']['get'];
            $this->assertSame('tasks.assignees.list', $operation['operationId']);
            // The intentional REST sentinel suppresses MCP-only explanatory prose.
            $this->assertArrayNotHasKey('description', $operation);
            $parameters = array_map(static fn (array $parameter): array => isset($parameter['$ref'])
                ? $document['components']['parameters'][basename($parameter['$ref'])] : $parameter, $operation['parameters']);
            $this->assertSame(['workspace', 'project', 'q'], array_column($parameters, 'name'));
            foreach (array_slice($parameters, 0, 2) as $parameter) {
                $this->assertSame('path', $parameter['in']);
                $this->assertTrue($parameter['required']);
                $this->assertSame(['type' => 'string', 'format' => 'uuid'], $parameter['schema']);
            }
            $query = $parameters[2];
            $this->assertSame('query', $query['in']);
            $this->assertFalse($query['required'] ?? false);
            $this->assertSame('string', $query['schema']['type']);
            $schema = json_decode(json_encode($query['schema'], JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
            $validator = new Validator;
            $this->assertTrue($validator->validate(str_repeat('s', 99), $schema)->isValid());
            $this->assertTrue($validator->validate(str_repeat('s', 100), $schema)->isValid());
            $this->assertFalse($validator->validate(str_repeat('s', 101), $schema)->isValid());
            $this->assertFalse($validator->validate(12, $schema)->isValid());
            $this->assertFalse($validator->validate(['synthetic search'], $schema)->isValid());
        });
    }

    private function withRegistry(callable $assertions): void
    {
        $previous = Container::getInstance();
        $application = new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $registry = (new AgentOperationCatalog)->registry();
            $application->instance(OperationRegistry::class, $registry);
            $assertions($registry);
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
