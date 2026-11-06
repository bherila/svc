<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\SharedProjectOperations;
use App\Services\Mcp\AgentMcpSharedProjectTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The shared project operations' declarations, pinned against the checked
 * OpenAPI document and the registry: six operations, four reads and two
 * collaboration-cutover writes, each reached by an MCP tool of the same name.
 */
final class SharedProjectOperationContractTest extends TestCase
{
    /** @var array<string, array{string, string, string, list<string>, int, string, string|null, bool}> */
    private const array OPERATIONS = [
        'shared_projects.list' => ['GET', '/workspaces/{workspace}/shared-projects', 'shared-projects.index', ['workspace'], 200, 'SharedProjectListResponse', null, false],
        'shared_projects.get' => ['GET', '/workspaces/{workspace}/shared-projects/{project}', 'shared-projects.show', ['workspace', 'project'], 200, 'SharedProjectResponse', null, false],
        'shared_projects.tasks.list' => ['GET', '/workspaces/{workspace}/shared-projects/{project}/tasks', 'shared-projects.tasks.index', ['workspace', 'project'], 200, 'SharedProjectTaskListResponse', null, false],
        'shared_projects.tasks.assign' => ['PUT', '/workspaces/{workspace}/shared-projects/{project}/tasks/{task}/assignees', 'shared-projects.tasks.assignees.update', ['workspace', 'project', 'task'], 200, 'SharedProjectAssignmentResponse', 'SharedProjectAssignRequest', true],
        'shared_projects.comments.list' => ['GET', '/workspaces/{workspace}/shared-projects/{project}/tasks/{task}/comments', 'shared-projects.comments.index', ['workspace', 'project', 'task'], 200, 'SharedProjectCommentListResponse', null, false],
        'shared_projects.comments.create' => ['POST', '/workspaces/{workspace}/shared-projects/{project}/tasks/{task}/comments', 'shared-projects.comments.store', ['workspace', 'project', 'task'], 201, 'SharedProjectCommentResponse', 'SharedProjectCommentRequest', true],
    ];

    private const array SCOPES = [
        'shared_projects.list' => ['projects:read'],
        'shared_projects.get' => ['projects:read'],
        'shared_projects.tasks.list' => ['tasks:read'],
        'shared_projects.tasks.assign' => ['tasks:write'],
        'shared_projects.comments.list' => ['collaboration:read', 'tasks:read'],
        'shared_projects.comments.create' => ['collaboration:write', 'tasks:read'],
    ];

    /** @return array<string, mixed> */
    private function document(): array
    {
        return json_decode((string) file_get_contents(dirname(__DIR__, 3).'/public/openapi/svc-agent-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_routes_and_definitions_are_pinned(): void
    {
        $this->assertSame(array_keys(self::OPERATIONS), array_keys(SharedProjectOperations::ROUTES));
        $this->assertSame(array_keys(self::OPERATIONS), array_keys(SharedProjectOperations::DEFINITIONS));
        foreach (self::OPERATIONS as $id => [, $path, $route, , $status, $response, $request]) {
            $this->assertSame([$route, $path, [$status]], SharedProjectOperations::ROUTES[$id], $id);
            $this->assertSame($response, SharedProjectOperations::DEFINITIONS[$id][2], $id);
            $this->assertSame($request, SharedProjectOperations::DEFINITIONS[$id][3], $id);
        }
        $this->assertSame(['SharedProjectListResponse', 'SharedProjectResponse', 'SharedProjectTaskListResponse', 'SharedProjectAssignmentResponse', 'SharedProjectAssignRequest',
            'SharedProjectCommentListResponse', 'SharedProjectCommentResponse', 'SharedProjectCommentRequest'], SharedProjectOperations::components());
    }

    public function test_every_component_is_exactly_what_the_checked_document_publishes(): void
    {
        $schemas = $this->document()['components']['schemas'];
        foreach (SharedProjectOperations::components() as $component) {
            $this->assertSame($schemas[$component], SharedProjectOperations::schema($component), $component);
        }
        $this->expectException(InvalidArgumentException::class);
        SharedProjectOperations::schema('SharedProjectNothing');
    }

    public function test_the_documentation_is_what_the_checked_document_publishes(): void
    {
        $document = $this->document();
        $documentation = SharedProjectOperations::documentation();
        $this->assertSame(array_keys(self::OPERATIONS), array_keys($documentation));

        foreach (self::OPERATIONS as $id => [$method, $path, , $pathParameters, $status, $response, $request, $write]) {
            $documented = $documentation[$id];
            $published = $document['paths'][$path][strtolower($method)];
            $this->assertSame($method, $documented['method'], $id);
            $this->assertSame(self::SCOPES[$id], $documented['scopes'], $id);
            $this->assertSame([['oauth2' => self::SCOPES[$id]], ['apiToken' => []]], $published['security'], $id);
            $this->assertSame(SharedProjectOperations::DEFINITIONS[$id][1], $documented['summary'], $id);
            $this->assertSame($documented['description'], $published['description'], $id);
            $this->assertSame($response, $documented['response'], $id);
            $this->assertSame('#/components/schemas/'.$response, $published['responses'][$status]['content']['application/json']['schema']['$ref'], $id);
            $this->assertSame($write, isset($documented['request']), $id);
            $this->assertSame($request, $documented['request'] ?? null, $id);
            if ($request !== null) {
                $this->assertSame('#/components/schemas/'.$request, $published['requestBody']['content']['application/json']['schema']['$ref'], $id);
            }

            $names = array_map(static fn (string|array $parameter): string => is_string($parameter) ? $parameter : $parameter['name'], $documented['parameters']);
            $publishedNames = array_map(static fn (array $parameter): string => $parameter['name'] ?? basename($parameter['$ref']), $published['parameters']);
            $this->assertSame($publishedNames, $names, $id);
            $expected = ['WorkspaceId', ...array_slice($pathParameters, 1)];
            if (str_contains($id, 'tasks.list') || str_contains($id, 'comments.list')) {
                $expected = [...$expected, 'limit', 'cursor'];
            }
            if ($write) {
                $expected[] = 'IdempotencyKey';
            }
            $this->assertSame($expected, $names, $id);

            $codes = array_keys($documented['errors']);
            $this->assertSame($write ? [401, 403, 404, 429, 409, 422] : [401, 403, 404, 429], $codes, $id);
            foreach ($codes as $code) {
                $this->assertSame($documented['errors'][$code], $published['responses'][$code]['description'], "$id $code");
            }
            $this->assertStringContainsString('any other workspace is told 404', $documented['description'], $id);
            $this->assertStringEndsWith('sees only the tasks assigned to them.', $documented['description'], $id);
            $this->assertSame($write, str_contains($documented['description'], 'AGENT_API_COLLABORATION_WRITES_ENABLED'), $id);
        }
        $this->assertStringContainsString('Agent writes also answer 404 while AGENT_API_COLLABORATION_WRITES_ENABLED is off.', $documentation['shared_projects.tasks.assign']['errors'][404]);
        $this->assertStringContainsString('still being processed', $documentation['shared_projects.comments.create']['errors'][409]);
        $this->assertStringContainsString('Idempotency-Key header is missing', $documentation['shared_projects.comments.create']['errors'][422]);
        $limit = $documentation['shared_projects.tasks.list']['parameters'][2];
        $this->assertSame(['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 50]], $limit);
        $this->assertSame(['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 2048]], $documentation['shared_projects.tasks.list']['parameters'][3]);
        $this->assertSame(['name' => 'project', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']], $documentation['shared_projects.get']['parameters'][1]);
        $this->assertSame(['name' => 'task', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']], $documentation['shared_projects.comments.list']['parameters'][2]);
    }

    public function test_each_operation_is_a_rest_operation_its_mcp_tool_reaches(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            foreach (self::OPERATIONS as $id => [$method, $path, $route, $pathParameters, $status, , , $write]) {
                $operation = $catalog->registry()->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame(SharedProjectOperations::DEFINITIONS[$id][1], $operation->title, $id);
                $this->assertSame($write ? Effect::LocalWrite : Effect::Read, $operation->effect, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertFalse($catalog->isManagerOnly($id), $id);
                $this->assertSame(self::SCOPES[$id], $operation->requirement->scopes, $id);
                $this->assertSame(
                    [...($write ? [AgentDeploymentFlags::COLLABORATION] : []), AgentDeploymentFlags::MCP, AgentDeploymentFlags::MCP_SWITCH.($write ? 'mcp.write' : 'mcp.read'), AgentDeploymentFlags::MCP_SWITCH.$id],
                    $operation->requirement->flags,
                    $id,
                );
                $this->assertSame([$method, $path, 'agent-api.v1.'.$route, $pathParameters, [$status]],
                    [$operation->rest?->method, $operation->rest?->path, $operation->rest?->routeName, $operation->rest?->pathParameters, $operation->rest?->successStatuses], $id);
                $this->assertSame([AgentMcpSharedProjectTools::class, SharedProjectOperations::DEFINITIONS[$id][0]], $operation->mcp?->handler, $id);
                $this->assertSame(SharedProjectOperations::documentation()[$id]['description'], $operation->description, $id);
                $this->assertSame($write, $operation->input !== null, $id);
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }
}
