<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\AgentRestDocumentation;
use App\Services\AgentApi\Operations\TaskOrganizationOperations;
use App\Services\Mcp\AgentMcpTaskOrganizationTools;
use App\Services\Mcp\AgentMcpToolSchemas;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\FirstPartySession;
use Bherila\McpLaravelBridge\Capabilities\Effect;
use Bherila\McpLaravelBridge\Capabilities\IdempotencyKey;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TaskOrganizationOperations::class)]
#[CoversClass(AgentMcpToolSchemas::class)]
#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentRestDocumentation::class)]
#[CoversClass(AgentDeploymentFlags::class)]
final class TaskOrganizationOperationContractTest extends TestCase
{
    public function test_organization_management_declares_project_roles_versions_paths_and_nonbypassable_availability(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $root = '/workspaces/{workspace}/projects/{project}/task-organization';
            foreach ([
                'get' => ['get', 'GET', '', null, Effect::Read],
                'lists.create' => ['listsCreate', 'POST', '/lists', 'expected_graph_version', Effect::LocalWrite],
                'lists.update' => ['listsUpdate', 'PATCH', '/lists/{list}', 'expected_graph_version', Effect::LocalWrite],
                'lists.archive' => ['listsArchive', 'DELETE', '/lists/{list}', 'expected_graph_version', Effect::Destructive],
                'lists.restore' => ['listsRestore', 'POST', '/lists/{list}/restore', 'expected_graph_version', Effect::LocalWrite],
                'lists.reorder' => ['listsReorder', 'PUT', '/lists/order', 'expected_container_version', Effect::LocalWrite],
                'tasks.create' => ['tasksCreate', 'POST', '/tasks', 'expected_graph_version', Effect::LocalWrite],
                'tasks.move' => ['tasksMove', 'PATCH', '/tasks/{task}/container', 'expected_graph_version', Effect::LocalWrite],
                'tasks.reorder' => ['tasksReorder', 'PUT', '/tasks/order', 'expected_container_version', Effect::LocalWrite],
            ] as $suffix => [$handler, $method, $path, $version, $effect]) {
                $id = 'task_organization.'.$suffix;
                $write = $suffix !== 'get';
                $operation = $registry->find($id);
                $this->assertNotNull($operation);
                $this->assertFalse($catalog->isManagerOnly($id), 'Project managers must not require workspace administration.');
                $this->assertSame([AgentMcpTaskOrganizationTools::class, $handler], $operation->mcp->handler);
                $this->assertSame($method, $operation->rest->method);
                $this->assertSame($root.$path, $operation->rest->path);
                $this->assertSame($effect, $operation->effect);
                $this->assertSame([$write ? 'tasks:write' : 'tasks:read'], $operation->requirement->scopes);
                $this->assertSame($write, $operation->safety->expectedVersion);
                $this->assertSame($suffix === 'lists.archive', $operation->safety->confirm);
                $this->assertSame($write ? IdempotencyKey::HeaderAndArgument : IdempotencyKey::None, $operation->safety->idempotencyKey);
                $this->assertTrue($operation->isIdempotent());
                $this->assertSame(str_ends_with($suffix, '.create') ? [201] : [200], $operation->rest->successStatuses);
                $this->assertSame([
                    ...($write ? [AgentDeploymentFlags::WRITES] : []), AgentDeploymentFlags::TASK_ORGANIZATION,
                    'mcp', 'mcp.switch:mcp.'.($write ? 'write' : 'read'), 'mcp.switch:'.$id,
                ], $operation->requirement->flags);
                $this->assertSame([
                    ...($write ? ['AGENT_API_WRITES_ENABLED'] : []), 'TASK_ORGANIZATION_WRITES_ENABLED',
                ], $operation->extensions['x-svc-flags']);
                $input = (new AgentMcpToolSchemas)->input($operation);
                $this->assertFalse($input['additionalProperties']);
                foreach (['workspace_id', 'project_id'] as $identity) {
                    $this->assertContains($identity, $input['required']);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties'][$identity]);
                }
                if ($version !== null) {
                    $body = AgentApiResponseSchemaCatalog::requestForOperation($id);
                    $this->assertFalse($body['additionalProperties']);
                    $this->assertContains($version, $body['required']);
                    $this->assertContains('idempotency_key', $input['required']);
                    if (in_array($suffix, ['lists.update', 'lists.archive', 'lists.restore', 'tasks.move'], true)) {
                        $this->assertContains('expected_version', $body['required']);
                    }
                    if (str_starts_with($suffix, 'lists.') && ! in_array($suffix, ['lists.create', 'lists.reorder'], true)) {
                        $this->assertArrayNotHasKey('list_id', $body['properties']);
                        $this->assertContains('list_id', $input['required']);
                    }
                    if ($suffix === 'tasks.move') {
                        $this->assertArrayNotHasKey('task_id', $body['properties']);
                        $this->assertContains('task_id', $input['required']);
                    }
                    if (str_ends_with($suffix, '.reorder')) {
                        $this->assertSame(100, $body['properties']['ordered_ids']['maxItems']);
                        $this->assertTrue($body['properties']['ordered_ids']['uniqueItems']);
                        $this->assertContains('ordered_ids', $body['required']);
                    }
                } else {
                    $this->assertSame(['type' => 'boolean', 'default' => false], $input['properties']['container']);
                    $this->assertStringContainsString('Portal and dual Agent', $operation->description);
                }
                $this->assertSame($write ? 'TaskOrganizationMutationResponse' : 'TaskOrganizationResponse', AgentApiResponseSchemaCatalog::operationComponent($id));
                $document = TaskOrganizationOperations::documentation()[$id];
                $parameters = ['WorkspaceId', 'ProjectId'];
                if (in_array($suffix, ['lists.update', 'lists.archive', 'lists.restore'], true)) {
                    $parameters[] = ['name' => 'list', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
                }
                if ($suffix === 'tasks.move') {
                    $parameters[] = 'TaskId';
                }
                if ($write) {
                    $parameters[] = 'IdempotencyKey';
                } else {
                    $parameters = [...$parameters,
                        ['name' => 'container', 'in' => 'query', 'schema' => ['type' => 'boolean', 'default' => false]],
                        ['name' => 'parent_id', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid']],
                        ['name' => 'list_id', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'uuid']],
                    ];
                }
                $this->assertSame($parameters, $document['parameters']);
                $this->assertSame($method, $document['method']);
                $this->assertSame([$write ? 'tasks:write' : 'tasks:read'], $document['scopes']);
                $this->assertSame($write ? 'TaskOrganizationMutationResponse' : 'TaskOrganizationResponse', $document['response']);
                $this->assertSame([401, 403, 404, 409, 422, 429], array_keys($document['errors']));
                if ($write) {
                    $this->assertSame(match ($suffix) {
                        'lists.create' => 'TaskOrganizationListCreateRequest',
                        'lists.update' => 'TaskOrganizationListUpdateRequest',
                        'lists.archive' => 'TaskOrganizationListArchiveRequest',
                        'lists.restore' => 'TaskOrganizationListRestoreRequest',
                        'lists.reorder' => 'TaskOrganizationListOrderRequest',
                        'tasks.create' => 'TaskOrganizationTaskCreateRequest',
                        'tasks.move' => 'TaskOrganizationTaskMoveRequest',
                        'tasks.reorder' => 'TaskOrganizationTaskOrderRequest',
                    }, $document['request'] ?? null);
                } else {
                    $this->assertArrayNotHasKey('request', $document);
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_the_product_gate_is_independent_of_agent_cutovers_and_never_bypassed_by_browser_authentication(): void
    {
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $settings = new Repository(['agent_api' => ['writes_enabled' => true], 'task_organization' => ['enabled' => false]]);
        $app->instance('config', $settings);
        $request = Request::create('/api/v1/context');
        $request->attributes->set(FirstPartySession::ATTRIBUTE, true);
        $app->instance('request', $request);
        try {
            $flags = AgentDeploymentFlags::forRest();
            $this->assertSame(['TASK_ORGANIZATION_WRITES_ENABLED', 'AGENT_API_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches([AgentDeploymentFlags::TASK_ORGANIZATION, AgentDeploymentFlags::WRITES]));
            $this->assertTrue(AgentDeploymentFlags::isAvailabilityPrecondition(AgentDeploymentFlags::TASK_ORGANIZATION));
            $this->assertNotContains(AgentDeploymentFlags::TASK_ORGANIZATION, AgentDeploymentFlags::cutovers());
            $this->assertFalse($flags->enabled(AgentDeploymentFlags::TASK_ORGANIZATION));
            $settings->set('task_organization.enabled', true);
            $settings->set('agent_api.writes_enabled', false);
            $this->assertTrue($flags->enabled(AgentDeploymentFlags::TASK_ORGANIZATION));
            $settings->set('task_organization.enabled', 'true');
            $this->assertFalse($flags->enabled(AgentDeploymentFlags::TASK_ORGANIZATION));
            $settings->set('task_organization.enabled', null);
            $this->assertFalse($flags->enabled(AgentDeploymentFlags::TASK_ORGANIZATION));
        } finally {
            Container::setInstance($previous);
        }
    }
}
