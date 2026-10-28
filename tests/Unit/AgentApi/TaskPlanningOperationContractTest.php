<?php

namespace Tests\Unit\AgentApi;

use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\AgentApi\Operations\TaskPlanningOperations;
use App\Services\Mcp\AgentMcpTaskPlanningTools;
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

/** Independent compatibility/security expectations from the approved adapter contract. */
#[CoversClass(TaskPlanningOperations::class)]
#[CoversClass(AgentOperationCatalog::class)]
#[CoversClass(AgentDeploymentFlags::class)]
final class TaskPlanningOperationContractTest extends TestCase
{
    // Do not derive these expectations from TaskPlanningOperations or a schema generator.
    // The public contract is the oracle for paths, handlers, authority and wire schemas.
    private const array CONTRACT = [
        'get' => ['get', 'GET', '/tasks/{task}/planning', 'task', false, ['tasks:read'], null, 'TaskPlanningDetailsResponse'],
        'labels.list' => ['labelsList', 'GET', '/task-labels', null, true, ['tasks:read'], null, 'TaskLabelListResponse'],
        'labels.get' => ['labelsGet', 'GET', '/task-labels/{label}', 'label', true, ['tasks:read'], null, 'TaskLabelResponse'],
        'labels.create' => ['labelsCreate', 'POST', '/task-labels', null, true, ['tasks:write'], 'TaskLabelCreateRequest', 'TaskPlanningMutationResponse'],
        'labels.update' => ['labelsUpdate', 'PATCH', '/task-labels/{label}', 'label', true, ['tasks:write'], 'TaskLabelUpdateRequest', 'TaskPlanningMutationResponse'],
        'labels.archive' => ['labelsArchive', 'DELETE', '/task-labels/{label}', 'label', true, ['tasks:write'], 'TaskLabelArchiveRequest', 'TaskPlanningMutationResponse'],
        'labels.restore' => ['labelsRestore', 'POST', '/task-labels/{label}/restore', 'label', true, ['tasks:write'], 'TaskLabelRestoreRequest', 'TaskPlanningMutationResponse'],
        'labels.options' => ['labelsOptions', 'GET', '/projects/{project}/task-label-options', 'project', false, ['tasks:read'], null, 'TaskLabelListResponse'],
        'labels.replace' => ['labelsReplace', 'PUT', '/tasks/{task}/labels', 'task', false, ['tasks:write'], 'TaskLabelReplacementRequest', 'TaskPlanningMutationResponse'],
        'dependencies.get' => ['dependenciesGet', 'GET', '/tasks/{task}/dependencies', 'task', false, ['tasks:read'], null, 'TaskDependenciesResponse'],
        'dependencies.options' => ['dependenciesOptions', 'GET', '/tasks/{task}/dependency-options', 'task', false, ['tasks:read'], null, 'TaskDependencyOptionListResponse'],
        'dependencies.replace' => ['dependenciesReplace', 'PUT', '/tasks/{task}/dependencies', 'task', false, ['tasks:write'], 'TaskDependencyReplacementRequest', 'TaskPlanningMutationResponse'],
        'contacts.get' => ['contactsGet', 'GET', '/tasks/{task}/contact-responsibilities', 'task', true, ['tasks:read', 'clients:read'], null, 'TaskContactResponsibilitiesResponse'],
        'contacts.options' => ['contactsOptions', 'GET', '/tasks/{task}/contact-responsibility-options', 'task', true, ['tasks:read', 'clients:read'], null, 'TaskContactOptionListResponse'],
        'contacts.replace' => ['contactsReplace', 'PUT', '/tasks/{task}/contact-responsibilities', 'task', true, ['tasks:write', 'clients:read'], 'TaskContactResponsibilityReplacementRequest', 'TaskPlanningMutationResponse'],
    ];

    public function test_all_fifteen_operations_preserve_public_paths_scopes_authority_receipts_and_product_availability(): void
    {
        $previous = Container::getInstance();
        new Application(dirname(__DIR__, 3));
        AgentApiResponseSchemaCatalog::flush();
        try {
            $catalog = new AgentOperationCatalog;
            $registry = $catalog->registry();
            $documents = TaskPlanningOperations::documentation();
            $this->assertCount(15, $documents);
            foreach (self::CONTRACT as $suffix => [$handler, $method, $path, $target, $manager, $scopes, $requestSchema, $responseSchema]) {
                $id = 'task_planning.'.$suffix;
                $write = $requestSchema !== null;
                $operation = $registry->find($id);
                $this->assertNotNull($operation, $id);
                $this->assertSame([AgentMcpTaskPlanningTools::class, $handler], $operation->mcp->handler, $id);
                $this->assertSame($method, $operation->rest->method, $id);
                $this->assertSame('/workspaces/{workspace}'.$path, $operation->rest->path, $id);
                $this->assertSame('agent-api.v1.task-planning.'.$suffix, $operation->rest->routeName, $id);
                $this->assertSame($scopes, $operation->requirement->scopes, $id);
                $this->assertSame($manager, $catalog->isManagerOnly($id), $id);
                $this->assertSame($write ? ($suffix === 'labels.archive' ? Effect::Destructive : Effect::LocalWrite) : Effect::Read, $operation->effect, $id);
                $this->assertSame($write && $suffix !== 'labels.create', $operation->safety->expectedVersion, $id);
                $this->assertSame($suffix === 'labels.archive', $operation->safety->confirm, $id);
                $this->assertSame($write ? IdempotencyKey::HeaderAndArgument : IdempotencyKey::None, $operation->safety->idempotencyKey, $id);
                $this->assertTrue($operation->isIdempotent(), $id);
                $this->assertSame($suffix === 'labels.create' ? [201] : [200], $operation->rest->successStatuses, $id);
                $this->assertSame([...($write ? ['agent.writes'] : []), 'tasks.planning', 'mcp', 'mcp.switch:mcp.'.($write ? 'write' : 'read'), 'mcp.switch:'.$id], $operation->requirement->flags, $id);
                $this->assertSame([...($write ? ['AGENT_API_WRITES_ENABLED'] : []), 'TASK_PLANNING_DETAILS_ENABLED'], $operation->extensions['x-svc-flags'], $id);
                $this->assertSame($responseSchema, AgentApiResponseSchemaCatalog::operationComponent($id), $id);
                $input = (new AgentMcpToolSchemas)->input($operation);
                $this->assertFalse($input['additionalProperties'], $id);
                $this->assertContains('workspace_id', $input['required'], $id);
                $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties']['workspace_id'], $id);
                if ($target !== null) {
                    $this->assertContains($target.'_id', $input['required'], $id);
                    $this->assertSame(['type' => 'string', 'format' => 'uuid'], $input['properties'][$target.'_id'], $id);
                }
                foreach (['origin', 'audience', 'company_id', 'include_contacts', 'client_id'] as $forbidden) {
                    $this->assertArrayNotHasKey($forbidden, $input['properties'], $id);
                }
                $document = $documents[$id];
                $this->assertSame($method, $document['method'], $id);
                $this->assertSame($scopes, $document['scopes'], $id);
                $this->assertSame($responseSchema, $document['response'], $id);
                $this->assertSame([401, 403, 404, 409, 422, 429], array_keys($document['errors']), $id);
                $this->assertSame($this->parameters($suffix, $target, $write), $document['parameters'], $id);
                $this->assertStringContainsString('Portal and dual Agent identities are refused', $document['description'], $id);
                $this->assertStringContainsString('Responsibility grants no account access', $document['description'], $id);
                if ($write) {
                    $this->assertSame($requestSchema, $document['request'], $id);
                    $this->assertContains('idempotency_key', $input['required'], $id);
                    $this->assertSame(['type' => 'string', 'minLength' => 1, 'maxLength' => 255], $input['properties']['idempotency_key'], $id);
                    $this->assertWriteInput($suffix, AgentApiResponseSchemaCatalog::requestForOperation($id));
                } else {
                    $this->assertArrayNotHasKey('request', $document, $id);
                    $this->assertArrayNotHasKey('idempotency_key', $input['properties'], $id);
                }
            }
        } finally {
            AgentApiResponseSchemaCatalog::flush();
            Container::setInstance($previous);
        }
    }

    public function test_planning_product_switch_is_strict_independent_and_never_bypassed_by_first_party_sessions(): void
    {
        $previous = Container::getInstance();
        $app = new Application(dirname(__DIR__, 3));
        $settings = new Repository(['agent_api' => ['writes_enabled' => false], 'task_organization' => ['enabled' => true], 'task_planning_details' => ['enabled' => false]]);
        $app->instance('config', $settings);
        $request = Request::create('/api/v1/context');
        $request->attributes->set(FirstPartySession::ATTRIBUTE, true);
        $app->instance('request', $request);
        try {
            $flags = AgentDeploymentFlags::forRest();
            $this->assertSame(['TASK_PLANNING_DETAILS_ENABLED', 'AGENT_API_WRITES_ENABLED'], AgentDeploymentFlags::environmentSwitches(['tasks.planning', 'agent.writes', 'tasks.planning']));
            $this->assertTrue(AgentDeploymentFlags::isAvailabilityPrecondition('tasks.planning'));
            $this->assertNotContains('tasks.planning', AgentDeploymentFlags::cutovers());
            foreach ([false, null, 'true', 1, '1'] as $disabled) {
                $settings->set('task_planning_details.enabled', $disabled);
                $this->assertFalse($flags->enabled('tasks.planning'));
            }
            $settings->set('task_planning_details.enabled', true);
            $settings->set('task_organization.enabled', false);
            $this->assertTrue($flags->enabled('tasks.planning'));
            $this->assertTrue($flags->enabled('agent.writes'), 'Only the existing Agent cutover may use a first-party bypass.');
        } finally {
            Container::setInstance($previous);
        }
    }

    /** @return list<string|array<string,mixed>> */
    private function parameters(string $suffix, ?string $target, bool $write): array
    {
        $parameters = ['WorkspaceId'];
        if ($target !== null) {
            $parameters[] = ['name' => $target, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        }
        if (in_array($suffix, ['labels.list', 'labels.options', 'dependencies.get', 'dependencies.options', 'contacts.options'], true)) {
            $parameters[] = ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 25]];
            $parameters[] = ['name' => 'cursor', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 2048]];
            if ($suffix !== 'dependencies.get') {
                $parameters[] = ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string', 'maxLength' => 100]];
            }
            if ($suffix === 'labels.list') {
                $parameters[] = ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['active', 'archived', 'all'], 'default' => 'active']];
            }
        }
        if ($write) {
            $parameters[] = 'IdempotencyKey';
        }

        return $parameters;
    }

    /** @param array<string,mixed> $input */
    private function assertWriteInput(string $suffix, array $input): void
    {
        $this->assertFalse($input['additionalProperties']);
        $required = match ($suffix) {
            'labels.create' => ['name'], 'labels.update' => ['name', 'expected_version'],
            'labels.archive' => ['expected_version', 'confirm'], 'labels.restore' => ['expected_version'],
            'labels.replace' => ['expected_version', 'label_ids'],
            'dependencies.replace' => ['expected_version', 'expected_graph_version', 'prerequisite_ids'],
            'contacts.replace' => ['expected_version', 'contact_ids'],
        };
        $this->assertEqualsCanonicalizing($required, $input['required']);
        $this->assertEqualsCanonicalizing($required, array_keys($input['properties']));
        foreach (['expected_version', 'expected_graph_version'] as $version) {
            if (in_array($version, $required, true)) {
                $this->assertSame(['$ref' => '#/$defs/Version'], $input['properties'][$version]);
                $this->assertSame(['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'], $input['$defs']['Version']);
            }
        }
        if (in_array('confirm', $required, true)) {
            $this->assertSame(['type' => 'boolean', 'const' => true], $input['properties']['confirm']);
        }
        foreach (['label_ids' => 20, 'prerequisite_ids' => 25, 'contact_ids' => 10] as $field => $cap) {
            if (in_array($field, $required, true)) {
                $this->assertSame(['type' => 'array', 'maxItems' => $cap, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']], $input['properties'][$field]);
            }
        }
    }
}
