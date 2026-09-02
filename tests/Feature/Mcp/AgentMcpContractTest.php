<?php

namespace Tests\Feature\Mcp;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Mcp\AgentMcpInputSchemaFactory;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcp\Capability\Discovery\SchemaValidator;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AgentMcpContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tool_uses_a_closed_standalone_openapi_response_component(): void
    {
        config(['agent_api.writes_enabled' => true]);

        foreach ($this->definitions() as $definition) {
            $component = AgentApiResponseSchemaCatalog::operationComponent($definition->operationId());
            $schema = AgentApiResponseSchemaCatalog::schema($component);
            $encoded = json_encode($schema, JSON_THROW_ON_ERROR);

            $this->assertSame('object', $schema['type'] ?? null, $definition->name);
            $this->assertFalse($schema['additionalProperties'] ?? true, $definition->name);
            $this->assertStringNotContainsString('#/components/schemas/', $encoded, $definition->name);
            preg_match_all('~"\$ref":"#/\$defs/([A-Za-z0-9_]+)"~', $encoded, $matches);
            foreach ($matches[1] as $target) {
                $this->assertArrayHasKey($target, $schema['$defs'] ?? [], $definition->name);
            }
        }
    }

    public function test_openapi_inventory_and_scopes_match_every_shipped_agent_route(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $actual = [];
        foreach ($document['paths'] as $path) {
            foreach ($path as $operation) {
                $actual[$operation['operationId']] = $operation['security'][0]['oauth2'];
            }
        }
        ksort($actual);

        $this->assertSame([
            'connections.revoke' => ['mcp:use'],
            'context.get' => ['identity:read'],
            'invoices.create_draft' => ['billing:write'],
            'invoices.discard_draft' => ['billing:write'],
            'invoices.get' => ['billing:read'],
            'invoices.issue' => ['billing:deliver'],
            'invoices.list' => ['billing:read'],
            'invoices.send' => ['billing:deliver'],
            'invoices.update_draft' => ['billing:write'],
            'invoices.void' => ['billing:deliver'],
            'operations.summary' => ['identity:read'],
            'projects.get' => ['projects:read'],
            'projects.list' => ['projects:read'],
            'tasks.create' => ['tasks:write'],
            'tasks.get' => ['tasks:read'],
            'tasks.list' => ['tasks:read'],
            'tasks.update' => ['tasks:write'],
            'time_entries.approve' => ['time:approve'],
            'time_entries.delete' => ['time:write'],
            'time_entries.list' => ['time:read'],
            'time_entries.log' => ['time:write'],
            'time_entries.update' => ['time:write'],
        ], $actual);
    }

    public function test_nested_mutation_input_schemas_advertise_the_rest_constraints(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $definitions = collect($this->definitions())->keyBy('name');
        $factory = app(AgentMcpInputSchemaFactory::class);

        $log = $factory->for($definitions->get('time_entries.log'));
        $this->assertSame(20, $log['properties']['entries']['maxItems']);
        $this->assertSame('#/$defs/TimeLogItem', $log['properties']['entries']['items']['$ref']);
        $this->assertFalse($log['$defs']['TimeLogItem']['additionalProperties']);
        $this->assertSame(1440, $log['$defs']['TimeLogItem']['properties']['minutes']['maximum']);
        $this->assertTrue($log['$defs']['TimeLogItem']['allOf'][0]['if']['properties']['is_visible_to_client']['const']);
        $this->assertSame(['client_visible_description'], $log['$defs']['TimeLogItem']['allOf'][0]['then']['required']);

        $invoice = $factory->for($definitions->get('invoices.create_draft'));
        $this->assertTrue($invoice['properties']['time_entry_ids']['uniqueItems']);
        $this->assertSame('#/$defs/InvoiceManualLine', $invoice['properties']['manual_lines']['items']['$ref']);
        $this->assertFalse($invoice['$defs']['InvoiceManualLine']['additionalProperties']);
        $this->assertSame(['type', 'description', 'quantity', 'unit_amount'], $invoice['$defs']['InvoiceManualLine']['required']);

        $task = $factory->for($definitions->get('tasks.update'));
        $this->assertArrayHasKey('is_visible_to_client', $task['properties']);
        $this->assertSame(['string', 'null'], $task['properties']['description']['type']);

        $time = $factory->for($definitions->get('time_entries.update'));
        foreach (['is_billable', 'is_deferred', 'is_visible_to_client', 'client_visible_description'] as $property) {
            $this->assertArrayHasKey($property, $time['properties']);
        }
        $this->assertTrue($time['allOf'][0]['if']['properties']['is_visible_to_client']['const']);

        $approval = $factory->for($definitions->get('time_entries.approve'));
        $approvalItem = $approval['$defs']['TimeApprovalItem'];
        $this->assertSame(0, $approvalItem['properties']['billing_rate_amount']['minimum']);
        $this->assertSame('#/$defs/Currency', $approvalItem['properties']['currency']['$ref']);
        $this->assertSame(['currency'], $approvalItem['dependentRequired']['billing_rate_amount']);
        $this->assertSame(['billing_rate_amount'], $approvalItem['dependentRequired']['currency']);
        $this->assertFalse($approvalItem['additionalProperties']);
    }

    public function test_every_write_tool_inherits_its_body_contract_from_openapi(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $factory = app(AgentMcpInputSchemaFactory::class);

        foreach ($this->definitions() as $definition) {
            if ($definition->readOnly) {
                continue;
            }
            $body = AgentApiResponseSchemaCatalog::requestForOperation($definition->operationId());
            $input = $factory->for($definition);
            $encoded = json_encode($input, JSON_THROW_ON_ERROR);

            foreach ($body['properties'] as $name => $property) {
                $this->assertSame($property, $input['properties'][$name] ?? null, "{$definition->name}:{$name}");
            }
            foreach ($body['required'] ?? [] as $required) {
                $this->assertContains($required, $input['required'] ?? [], $definition->name);
            }
            $this->assertSame($body['$defs'] ?? null, $input['$defs'] ?? null, $definition->name);
            $this->assertStringNotContainsString('#/components/schemas/', $encoded, $definition->name);
            preg_match_all('~"\$ref":"#/\$defs/([A-Za-z0-9_]+)"~', $encoded, $matches);
            foreach ($matches[1] as $target) {
                $this->assertArrayHasKey($target, $input['$defs'] ?? [], $definition->name);
            }
        }
    }

    public function test_every_write_operation_requires_an_idempotency_header_and_tool_argument(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $factory = app(AgentMcpInputSchemaFactory::class);

        foreach ($this->definitions() as $definition) {
            if ($definition->readOnly) {
                continue;
            }
            $operation = collect($document['paths'])->flatMap(fn (array $path): array => array_values($path))
                ->firstWhere('operationId', $definition->operationId());
            $parameterRefs = collect($operation['parameters'] ?? [])->pluck('$ref');
            $input = $factory->for($definition);

            $this->assertContains('#/components/parameters/IdempotencyKey', $parameterRefs, $definition->name);
            $this->assertContains('idempotency_key', $input['required'] ?? [], $definition->name);
        }
    }

    public function test_unrated_manager_time_conforms_through_rest_and_mcp(): void
    {
        [$user, $workspace, $project] = $this->workspace();
        $entry = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $project->client_company_id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2026-08-23',
            'minutes' => 30,
            'description' => 'Unrated work',
            'billing_rate_amount' => null,
            'currency' => 'USD',
        ]);
        $this->actingAsAgent($user, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_READ]);

        $rest = $this->getJson("/api/v1/workspaces/{$workspace->public_id}/time-entries")
            ->assertOk()->assertJsonPath('data.0.billing_rate_amount', null)->json();
        $errors = (new SchemaValidator)->validateAgainstJsonSchema(
            $rest,
            AgentApiResponseSchemaCatalog::forOperation('time_entries.list'),
        );
        $this->assertSame([], $errors, json_encode($errors, JSON_THROW_ON_ERROR));

        $session = $this->initialize();
        $result = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'time_entries.list', 'arguments' => ['workspace_id' => $workspace->public_id]],
        ], $session)->assertOk()->json('result');
        $this->assertFalse($result['isError']);
        $this->assertSame($entry->public_id, $result['structuredContent']['data'][0]['id']);
        $this->assertNull($result['structuredContent']['data'][0]['billing_rate_amount']);
    }

    public function test_mcp_patch_distinguishes_omitted_fields_from_explicit_null(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$user, $workspace, $project] = $this->workspace();
        $task = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Keep this title',
            'description' => 'Clear this description',
        ]);
        $this->actingAsAgent($user, [AgentApiScopes::MCP_USE, AgentApiScopes::TASKS_WRITE]);

        $session = $this->initialize();
        $result = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
            'params' => ['name' => 'tasks.update', 'arguments' => [
                'workspace_id' => $workspace->public_id,
                'task_id' => $task->public_id,
                'expected_version' => AgentApiVersion::for($task),
                'idempotency_key' => 'mcp-task-update-1',
                'description' => null,
            ]],
        ], $session)->assertOk()->json('result');

        $this->assertFalse($result['isError']);
        $this->assertNull($result['structuredContent']['data']['description']);
        $this->assertDatabaseHas('client_tasks', ['id' => $task->id, 'title' => 'Keep this title', 'description' => null]);
    }

    public function test_server_instructions_match_the_write_flag(): void
    {
        $user = User::factory()->create();
        $this->actingAsAgent($user, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_WRITE]);

        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => false,
        ]);
        $readOnly = $this->mcp($this->initializeMessage())->assertOk()->json('result.instructions');
        $this->assertStringContainsString('read-only', $readOnly);

        config(['agent_api.time_entry_writes_enabled' => true]);
        $writable = $this->mcp($this->initializeMessage())->assertOk()->json('result.instructions');
        $this->assertStringContainsString('write tools are enabled', $writable);
        $this->assertStringNotContainsString('connection is read-only', $writable);
    }

    /** @return list<ToolDefinition> */
    private function definitions(): array
    {
        return app(AgentMcpToolCatalog::class)->definitions(
            app(AgentMcpReadTools::class),
            app(AgentMcpWriteTools::class),
        );
    }

    /** @return array{User, Workspace, ClientProject} */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Contract Workspace', 'slug' => 'contract-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Contract Client', 'slug' => 'contract-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Contract Project']);

        return [$user, $workspace, $project];
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        $this->actingAsMcp($user, $scopes);
    }

    private function initialize(): string
    {
        $response = $this->mcp($this->initializeMessage())->assertOk();
        $session = $response->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        return $session;
    }

    /** @return array<string, mixed> */
    private function initializeMessage(): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC contract test', 'version' => '1']]];
    }

    /** @param array<string, mixed> $message
     * @return TestResponse<Response> */
    private function mcp(array $message, ?string $session = null): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }
}
