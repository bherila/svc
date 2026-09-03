<?php

namespace Tests\Feature\Mcp;

use App\Events\McpCapabilityInvoked;
use App\Models\ClientAgreement;
use App\Models\ClientBillingSchedule;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiCursor;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Token;
use Mcp\Capability\Discovery\SchemaValidator;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AgentMcpReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_mcp_exposes_only_the_fixed_read_catalog_and_uses_rest_authorization(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'MCP Workspace', 'slug' => 'mcp-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'MCP Client', 'slug' => 'mcp-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'MCP Project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'MCP Agreement',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
            'retainer_minutes' => 120,
        ]);
        $schedule = ClientBillingSchedule::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'cadence' => 'monthly',
            'next_run_on' => '2026-09-01',
            'due_days' => 30,
            'currency' => 'USD',
            'is_active' => true,
            'line_template' => [['type' => 'service', 'description' => 'MCP service', 'quantity' => '1', 'unit_amount' => 10000]],
        ]);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::BILLING_READ,
        ]);

        $initialization = $this->mcp($this->initializeMessage())->assertOk();
        $capabilities = $initialization->json('result.capabilities');
        $this->assertIsArray($capabilities);
        $this->assertArrayHasKey('tools', $capabilities);
        $this->assertArrayHasKey('resources', $capabilities);
        $this->assertArrayNotHasKey('subscribe', $capabilities['resources']);
        $this->assertArrayNotHasKey('listChanged', $capabilities['resources']);
        $this->assertArrayNotHasKey('logging', $capabilities);
        $this->assertArrayNotHasKey('completions', $capabilities);
        $session = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame(['context.get', 'operations.summary', 'projects.list', 'projects.get', 'tasks.list', 'tasks.get', 'time_entries.list', 'invoices.list', 'invoices.get', 'agreements.list', 'agreements.get', 'billing_schedules.list', 'billing_schedules.get', 'capacity_ledger.get', 'billing.audit_unplaceable_invoices', 'billing.audit_undated_collectible_invoices', 'billing.audit_missing_billed_overage'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['destructiveHint']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
            $this->assertFalse($tool['outputSchema']['additionalProperties']);
        }

        $resources = $this->mcp(['jsonrpc' => '2.0', 'id' => 20, 'method' => 'resources/list', 'params' => []], $session)
            ->assertOk()->json('result.resources');
        $this->assertSame(['svc://context'], array_column($resources, 'uri'));
        $contextResource = $this->mcp(['jsonrpc' => '2.0', 'id' => 21, 'method' => 'resources/read', 'params' => ['uri' => 'svc://context']], $session)
            ->assertOk()->json('result.contents.0');
        $this->assertSame('svc://context', $contextResource['uri']);
        $this->assertSame('application/json', $contextResource['mimeType']);
        $contextData = json_decode($contextResource['text'], true, flags: JSON_THROW_ON_ERROR);
        $contextErrors = (new SchemaValidator)->validateAgainstJsonSchema(
            $contextData,
            AgentApiResponseSchemaCatalog::forOperation('context.get'),
        );
        $this->assertSame([], $contextErrors, json_encode($contextErrors, JSON_THROW_ON_ERROR));

        $templates = $this->mcp(['jsonrpc' => '2.0', 'id' => 22, 'method' => 'resources/templates/list', 'params' => []], $session)
            ->assertOk()->json('result.resourceTemplates');
        $this->assertSame([
            [
                'uriTemplate' => 'svc://workspaces/{workspace_id}/agreements/{agreement_id}',
                'name' => 'agreement',
                'title' => 'Agreement',
                'description' => 'Read one canonical agreement representation visible to a workspace manager.',
                'mimeType' => 'application/json',
            ],
        ], $templates);
        $agreementResource = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'resources/read',
            'params' => ['uri' => "svc://workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}"],
        ], $session)->assertOk()->json('result.contents.0');
        $this->assertSame("svc://workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}", $agreementResource['uri']);
        $this->assertSame('application/json', $agreementResource['mimeType']);
        $this->assertSame($agreement->public_id, json_decode($agreementResource['text'], true, flags: JSON_THROW_ON_ERROR)['data']['id']);
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 24,
            'method' => 'resources/subscribe',
            'params' => ['uri' => "svc://workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}"],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32601)
            ->assertJsonPath('error.message', 'Resource subscriptions are not supported.')
            ->assertJsonMissingPath('result');
        foreach ([
            ['method' => 'logging/setLevel', 'params' => ['level' => 'debug']],
            [
                'method' => 'completion/complete',
                'params' => [
                    'ref' => ['type' => 'ref/resource', 'uri' => 'svc://workspaces/{workspace_id}/agreements/{agreement_id}'],
                    'argument' => ['name' => 'workspace_id', 'value' => ''],
                ],
            ],
        ] as $index => $unsupported) {
            $this->mcp([
                'jsonrpc' => '2.0',
                'id' => 25 + $index,
                ...$unsupported,
            ], $session)
                ->assertOk()
                ->assertJsonPath('error.code', -32601)
                ->assertJsonPath('error.message', 'This optional MCP feature is not supported.')
                ->assertJsonMissingPath('result');
        }

        $response = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'projects.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($response['isError']);
        $this->assertSame($project->public_id, $response['structuredContent']['data']['id']);

        $agreementResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'agreements.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($agreementResponse['isError']);
        $this->assertSame('MCP Agreement', $agreementResponse['structuredContent']['data']['title']);
        $this->assertSame('monthly', $agreementResponse['structuredContent']['data']['effective_billing_cadence']);
        $this->assertSame('prorate_hours', $agreementResponse['structuredContent']['data']['effective_first_cycle_proration']);
        $this->assertSame(120, $agreementResponse['structuredContent']['data']['retainer_minutes_per_month']);

        $scheduleResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'billing_schedules.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'schedule_id' => $schedule->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($scheduleResponse['isError']);
        $this->assertSame($agreement->public_id, $scheduleResponse['structuredContent']['data']['agreement_id']);

        $ledgerResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'capacity_ledger.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'agreement_id' => $agreement->public_id, 'months' => 1]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($ledgerResponse['isError']);
        $this->assertSame($agreement->public_id, $ledgerResponse['structuredContent']['data']['agreement_id']);
        $this->assertLessThanOrEqual(1, count($ledgerResponse['structuredContent']['data']['months']));

        $auditResponse = $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'billing.audit_unplaceable_invoices', 'arguments' => ['workspace_id' => $workspace->public_id]]], $session)
            ->assertOk()->json('result');
        $this->assertFalse($auditResponse['isError']);
        $this->assertSame(0, $auditResponse['structuredContent']['data']['invoices']);
        $this->assertArrayNotHasKey('invoice_number', $auditResponse['structuredContent']['data']);
    }

    public function test_mcp_initialization_and_prompts_self_document_safe_workflows(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $initialization = $this->mcp($this->initializeMessage())->assertOk();
        $instructions = $initialization->json('result.instructions');
        $this->assertIsString($instructions);
        $this->assertStringContainsString('missing tools are not authorized', $instructions);
        $this->assertStringNotContainsString('time_entries.log', $instructions);
        $limitedSession = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($limitedSession);
        $limitedPrompts = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list', 'params' => [],
        ], $limitedSession)->assertOk()->json('result.prompts');
        $this->assertSame([], $limitedPrompts);
        $limitedResources = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/list', 'params' => [],
        ], $limitedSession)->assertOk()->json('result.resources');
        $this->assertSame([], $limitedResources);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'resources/read',
            'params' => ['uri' => 'svc://context'],
        ], $limitedSession)->assertOk()->assertJsonPath('error.code', -32002);
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'prompts/get',
            'params' => ['name' => 'log-time-across-projects', 'arguments' => []],
        ], $limitedSession)->assertOk()->assertJsonPath('error.code', -32002);

        config(['agent_api.writes_enabled' => true]);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::BILLING_READ,
            AgentApiScopes::BILLING_WRITE,
        ]);
        $initialization = $this->mcp($this->initializeMessage())->assertOk();
        $instructions = $initialization->json('result.instructions');
        $this->assertIsString($instructions);
        $firstDecisionWindow = substr($instructions, 0, 512);
        $this->assertStringContainsString('First call context.get', $firstDecisionWindow);
        $this->assertStringContainsString('time_entries.log', $firstDecisionWindow);
        $this->assertStringContainsString('never approve time unless the user asks', $firstDecisionWindow);

        $session = $initialization->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);
        $prompts = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'prompts/list',
            'params' => [],
        ], $session)->assertOk()->json('result.prompts');
        $this->assertSame(
            ['log-time-across-projects', 'prepare-invoice-safely'],
            array_column($prompts, 'name'),
        );

        $guide = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'prompts/get',
            'params' => ['name' => 'log-time-across-projects', 'arguments' => []],
        ], $session)->assertOk()->json('result.messages.0.content.text');
        $this->assertIsString($guide);
        $this->assertStringContainsString('context.get', $guide);
        $this->assertStringContainsString('stable, task-specific idempotency_key', $guide);
        $this->assertStringContainsString('Do not approve time', $guide);
    }

    public function test_empty_billing_audit_balances_are_json_objects(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Empty Audit Workspace', 'slug' => 'empty-audit-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ]);
        $session = $this->initialize();

        $response = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'billing.audit_undated_collectible_invoices',
                'arguments' => ['workspace_id' => $workspace->public_id],
            ],
        ], $session)
            ->assertOk()
            ->assertJsonPath('result.isError', false);

        $wire = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $data = $wire->result->structuredContent->data;
        $this->assertIsObject($data->undated_balances);
        $this->assertIsObject($data->would_become_overdue_balances);
    }

    public function test_manager_only_read_capabilities_are_not_discovered_by_a_non_manager(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Member Workspace', 'slug' => 'member-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ]);

        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()
            ->json('result.tools');
        $names = array_column($tools, 'name');

        $this->assertNotContains('agreements.list', $names);
        $this->assertNotContains('billing_schedules.list', $names);
        $this->assertNotContains('capacity_ledger.get', $names);
        $this->assertNotContains('billing.audit_unplaceable_invoices', $names);

        $templates = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/templates/list', 'params' => [],
        ], $session)->assertOk()->json('result.resourceTemplates');
        $this->assertSame([], $templates);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'agreements.list',
                'arguments' => ['workspace_id' => $workspace->public_id],
            ],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32601)
            ->assertJsonMissingPath('result');

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'resources/read',
            'params' => ['uri' => "svc://workspaces/{$workspace->public_id}/agreements/".(string) str()->uuid()],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32002)
            ->assertJsonMissingPath('result');
    }

    public function test_agreement_resource_template_scopes_the_object_to_the_selected_workspace(): void
    {
        $user = User::factory()->create();
        $selected = Workspace::query()->create(['name' => 'Selected Workspace', 'slug' => 'selected-workspace']);
        $other = Workspace::query()->create(['name' => 'Other Workspace', 'slug' => 'other-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $selected->id, 'user_id' => $user->id, 'role' => 'admin']);
        WorkspaceMembership::query()->create(['workspace_id' => $other->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $other->id, 'name' => 'Other Client', 'slug' => 'other-client']);
        $project = ClientProject::query()->create(['workspace_id' => $other->id, 'client_company_id' => $company->id, 'name' => 'Other Project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $other->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Other Agreement',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::BILLING_READ]);
        $session = $this->initialize();

        $response = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => ['uri' => "svc://workspaces/{$selected->public_id}/agreements/{$agreement->public_id}"],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32002)
            ->assertJsonMissingPath('result');

        $this->assertStringNotContainsString($agreement->public_id, (string) $response->getContent());
        $this->assertStringNotContainsString('Other Agreement', (string) $response->getContent());

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/read',
            'params' => ['uri' => 'svc://workspaces/'.(string) str()->uuid()."/agreements/{$agreement->public_id}"],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32002)
            ->assertJsonMissingPath('result');
    }

    public function test_mcp_capability_rate_limits_return_a_safe_tool_error(): void
    {
        config(['agent_api.mcp_rate_limits' => ['mcp-read' => 1, 'mcp-write' => 20]]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Rate Limited Workspace', 'slug' => 'rate-limited-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();
        $message = ['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'projects.list', 'arguments' => ['workspace_id' => $workspace->public_id]]];

        $this->mcp(['id' => 2, ...$message], $session)->assertOk()->assertJsonPath('result.isError', false);
        $limited = $this->mcp(['id' => 3, ...$message], $session)->assertOk()->json('result');

        $this->assertTrue($limited['isError']);
        $this->assertSame('This operation is temporarily rate limited. Please retry later.', $limited['content'][0]['text']);
    }

    public function test_mcp_capability_rate_limits_apply_to_resources_and_prompts(): void
    {
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.mcp_rate_limits' => ['mcp-read' => 1, 'mcp-write' => 20],
        ]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
        ]);
        $session = $this->initialize();

        foreach ([
            ['method' => 'resources/read', 'params' => ['uri' => 'svc://context']],
            ['method' => 'prompts/get', 'params' => ['name' => 'log-time-across-projects', 'arguments' => []]],
        ] as $index => $message) {
            $this->mcp(['jsonrpc' => '2.0', 'id' => ($index * 2) + 2, ...$message], $session)
                ->assertOk()
                ->assertJsonMissingPath('error');
            $this->mcp(['jsonrpc' => '2.0', 'id' => ($index * 2) + 3, ...$message], $session)
                ->assertOk()
                ->assertJsonPath('error.code', -32000)
                ->assertJsonPath('error.message', 'This operation is temporarily rate limited. Please retry later.')
                ->assertJsonMissingPath('result');
        }
    }

    public function test_mcp_capability_concurrency_limits_return_a_safe_tool_error(): void
    {
        config(['agent_api.mcp_concurrency_limit' => 1, 'agent_api.mcp_concurrency_lock_seconds' => 60]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Concurrency Limited Workspace', 'slug' => 'concurrency-limited-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $credentialId = Token::query()->where('user_id', $user->id)->sole()->id;
        $lock = Cache::lock('mcp:concurrency:'.hash('sha256', 'projects.list|'.$credentialId).':1', 60);
        $this->assertTrue($lock->get());
        $session = $this->initialize();

        try {
            $result = $this->mcp([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'projects.list', 'arguments' => ['workspace_id' => $workspace->public_id]],
            ], $session)->assertOk()->json('result');

            $this->assertTrue($result['isError']);
            $this->assertSame('This operation is temporarily busy. Please retry later.', $result['content'][0]['text']);
        } finally {
            $lock->release();
        }
    }

    public function test_mcp_capability_results_are_bounded_for_tools_resources_and_prompts(): void
    {
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.mcp_max_result_bytes' => 1,
        ]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Result Bound Workspace', 'slug' => 'result-bound-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
        ]);
        $session = $this->initialize();

        $tool = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'projects.list', 'arguments' => ['workspace_id' => $workspace->public_id]],
        ], $session)->assertOk()->json('result');
        $this->assertTrue($tool['isError']);
        $this->assertSame('This operation produced an unexpectedly large response.', $tool['content'][0]['text']);

        foreach ([
            ['method' => 'resources/read', 'params' => ['uri' => 'svc://context']],
            ['method' => 'prompts/get', 'params' => ['name' => 'log-time-across-projects', 'arguments' => []]],
        ] as $index => $message) {
            $this->mcp(['jsonrpc' => '2.0', 'id' => $index + 3, ...$message], $session)
                ->assertOk()
                ->assertJsonPath('error.code', -32000)
                ->assertJsonPath('error.message', 'This operation produced an unexpectedly large response.')
                ->assertJsonMissingPath('result');
        }
    }

    public function test_mcp_time_entry_collection_is_bounded_and_cursor_continuable_at_high_volume(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'High Volume Workspace', 'slug' => 'high-volume-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'High Volume Client', 'slug' => 'high-volume-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'High Volume Project']);
        for ($index = 0; $index < 105; $index++) {
            ClientTimeEntry::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'user_id' => $user->id,
                'worked_on' => '2026-08-20',
                'minutes' => 60,
                'description' => "Synthetic high-volume entry {$index}",
                'billing_rate_amount' => 15000,
                'currency' => 'USD',
            ]);
        }
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_READ]);
        $session = $this->initialize();

        $first = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'time_entries.list', 'arguments' => ['workspace_id' => $workspace->public_id, 'limit' => 100]],
        ], $session)->assertOk()->json('result');
        $this->assertFalse($first['isError']);
        $this->assertCount(100, $first['structuredContent']['data']);
        $cursor = $first['structuredContent']['meta']['next_cursor'];
        $this->assertIsString($cursor);

        $second = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'time_entries.list', 'arguments' => ['workspace_id' => $workspace->public_id, 'limit' => 100, 'cursor' => $cursor]],
        ], $session)->assertOk()->json('result');
        $this->assertFalse($second['isError']);
        $this->assertCount(5, $second['structuredContent']['data']);
        $this->assertNull($second['structuredContent']['meta']['next_cursor']);
        $this->assertSame([], array_values(array_intersect(
            array_column($first['structuredContent']['data'], 'id'),
            array_column($second['structuredContent']['data'], 'id'),
        )));
    }

    public function test_mcp_read_schema_rejects_out_of_bounds_and_unknown_arguments_before_lookup(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Schema Workspace', 'slug' => 'schema-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();

        foreach ([
            ['limit' => 0],
            ['limit' => 101],
            ['query' => str_repeat('x', 201)],
            ['cursor' => str_repeat('x', 2049)],
            ['unrecognized' => 'value'],
        ] as $index => $invalid) {
            $this->mcp([
                'jsonrpc' => '2.0',
                'id' => $index + 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'projects.list',
                    'arguments' => ['workspace_id' => $workspace->public_id, ...$invalid],
                ],
            ], $session)
                ->assertOk()
                ->assertJsonPath('error.code', -32602)
                ->assertJsonMissingPath('result');
        }
    }

    public function test_mcp_cursor_query_mismatch_is_a_tool_validation_error(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Cursor Workspace', 'slug' => 'cursor-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();
        $cursor = AgentApiCursor::encode(1, $workspace->public_id, 'projects|status=active|search=');

        $result = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'projects.list',
                'arguments' => ['workspace_id' => $workspace->public_id, 'cursor' => $cursor],
            ],
        ], $session)->assertOk()->json('result');

        $this->assertTrue($result['isError']);
        $this->assertSame('The pagination cursor is not valid for this request.', $result['content'][0]['text']);
    }

    public function test_mcp_client_cannot_read_a_hidden_task_directly(): void
    {
        $client = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Portal Task Workspace', 'slug' => 'portal-task-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Portal Task Client', 'slug' => 'portal-task-client']);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Portal Task Project',
            'is_visible_to_client' => true,
        ]);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client']);
        $hidden = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Hidden task must stay private',
            'is_visible_to_client' => false,
        ]);
        $visible = ClientTask::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'title' => 'Visible task',
            'is_visible_to_client' => true,
        ]);
        $this->actingAsMcp($client, [AgentApiScopes::MCP_USE, AgentApiScopes::TASKS_READ]);
        $session = $this->initialize();

        $hiddenResponse = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'tasks.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'task_id' => $hidden->public_id]],
        ], $session)->assertOk();
        $this->assertSame(-32603, $hiddenResponse->json('error.code'));
        $this->assertStringNotContainsString($hidden->title, $hiddenResponse->getContent());

        $visibleResponse = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'tasks.get', 'arguments' => ['workspace_id' => $workspace->public_id, 'task_id' => $visible->public_id]],
        ], $session)->assertOk();
        $this->assertFalse($visibleResponse->json('result.isError'));
        $this->assertSame($visible->title, $visibleResponse->json('result.structuredContent.data.title'));
    }

    public function test_mcp_fails_closed_when_the_capability_rate_limiter_is_unavailable(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Limiter Failure Workspace', 'slug' => 'limiter-failure-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();
        $originalLimiter = app(RateLimiter::class);
        $limiter = \Mockery::mock(RateLimiter::class);
        $limiter->shouldReceive('tooManyAttempts')->once()->andReturnFalse()->ordered();
        $limiter->shouldReceive('hit')->once()->andReturn(1)->ordered();
        $limiter->shouldReceive('tooManyAttempts')->once()->andThrow(new \RuntimeException('cache backend details must not escape'))->ordered();
        $limiter->shouldReceive('retriesLeft')->once()->andReturn(59);

        try {
            app()->instance(RateLimiter::class, $limiter);
            $result = $this->mcp([
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'projects.list', 'arguments' => ['workspace_id' => $workspace->public_id]],
            ], $session)->assertOk()->json('result');

            $this->assertTrue($result['isError']);
            $this->assertSame('This operation is temporarily unavailable. Please retry later.', $result['content'][0]['text']);
            $this->assertStringNotContainsString('cache backend details', $result['content'][0]['text']);
        } finally {
            app()->instance(RateLimiter::class, $originalLimiter);
        }
    }

    public function test_mcp_capability_audit_event_contains_only_attribution_metadata(): void
    {
        $audit = new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $entries = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        app()->instance(LoggerInterface::class, $audit);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Audited Workspace', 'slug' => 'audited-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Audited Client', 'slug' => 'audited-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Audited Project']);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Audited Agreement',
            'status' => 'active',
            'starts_on' => '2026-01-01',
            'currency' => 'USD',
            'billing_cadence' => 'monthly',
        ]);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::BILLING_READ,
        ]);
        $session = $this->initialize();
        Event::fake([McpCapabilityInvoked::class]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'projects.list',
                'arguments' => ['workspace_id' => $workspace->public_id, 'query' => 'must-not-be-audited'],
            ],
        ], $session)->assertOk()->assertJsonPath('result.isError', false);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/read',
            'params' => ['uri' => 'svc://context'],
        ], $session)->assertOk();
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'prompts/get',
            'params' => ['name' => 'log-time-across-projects', 'arguments' => []],
        ], $session)->assertOk();
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'resources/read',
            'params' => ['uri' => "svc://workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}"],
        ], $session)->assertOk();
        $unknownResourceUri = 'svc://unknown-resource?credential=must-not-be-audited';
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'resources/read',
            'params' => ['uri' => $unknownResourceUri],
        ], $session)->assertOk()->assertJsonPath('error.code', -32002);
        $unknownToolName = 'unknown.tool?credential=must-not-be-audited';
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => ['name' => $unknownToolName, 'arguments' => ['query' => 'must-not-be-audited']],
        ], $session)->assertOk()->assertJsonPath('error.code', -32601);
        $unknownPromptName = 'unknown.prompt?credential=must-not-be-audited';
        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'prompts/get',
            'params' => ['name' => $unknownPromptName, 'arguments' => []],
        ], $session)->assertOk()->assertJsonPath('error.code', -32002);

        $events = array_values(array_filter(
            $audit->entries,
            static fn (array $entry): bool => $entry['message'] === 'mcp.capability.executed',
        ));
        $this->assertCount(7, $events);
        $event = $events[0];
        $this->assertSame('info', $event['level']);
        $this->assertSame([
            'request_id',
            'capability',
            'rate_limit_bucket',
            'audit_classification',
            'outcome',
            'duration_ms',
            'subject_id',
            'credential_fingerprint',
            'client_fingerprint',
        ], array_keys($event['context']));
        $this->assertSame('projects.list', $event['context']['capability']);
        $this->assertSame('agent_api.read', $event['context']['audit_classification']);
        $this->assertSame($user->public_id, $event['context']['subject_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event['context']['credential_fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $event['context']['client_fingerprint']);
        $this->assertNotContains('must-not-be-audited', $event['context']);
        $this->assertArrayNotHasKey('arguments', $event['context']);
        $this->assertArrayNotHasKey('result', $event['context']);
        $this->assertArrayNotHasKey('headers', $event['context']);

        $resourceEvent = $events[1];
        $this->assertSame('svc://context', $resourceEvent['context']['capability']);
        $this->assertSame('agent_api.read', $resourceEvent['context']['audit_classification']);
        $this->assertSame('success', $resourceEvent['context']['outcome']);
        $this->assertArrayNotHasKey('uri', $resourceEvent['context']);

        $promptEvent = $events[2];
        $this->assertSame('log-time-across-projects', $promptEvent['context']['capability']);
        $this->assertSame('agent_api.prompt', $promptEvent['context']['audit_classification']);
        $this->assertSame('success', $promptEvent['context']['outcome']);
        $this->assertArrayNotHasKey('arguments', $promptEvent['context']);

        $templateEvent = $events[3];
        $this->assertSame('svc://workspaces/{workspace_id}/agreements/{agreement_id}', $templateEvent['context']['capability']);
        $this->assertSame('agent_api.read', $templateEvent['context']['audit_classification']);
        $this->assertSame('success', $templateEvent['context']['outcome']);
        $this->assertArrayNotHasKey('uri', $templateEvent['context']);
        $this->assertNotContains($workspace->public_id, $templateEvent['context']);
        $this->assertNotContains($agreement->public_id, $templateEvent['context']);

        $unknownResourceEvent = $events[4];
        $this->assertSame('mcp.unknown_resource', $unknownResourceEvent['context']['capability']);
        $this->assertSame('mcp-unknown', $unknownResourceEvent['context']['rate_limit_bucket']);
        $this->assertSame('mcp.unknown', $unknownResourceEvent['context']['audit_classification']);
        $this->assertSame('error', $unknownResourceEvent['context']['outcome']);
        $this->assertStringNotContainsString($unknownResourceUri, json_encode($unknownResourceEvent['context'], JSON_THROW_ON_ERROR));

        $unknownEvent = $events[5];
        $this->assertSame('mcp.unknown_tool', $unknownEvent['context']['capability']);
        $this->assertSame('mcp-unknown', $unknownEvent['context']['rate_limit_bucket']);
        $this->assertSame('mcp.unknown', $unknownEvent['context']['audit_classification']);
        $this->assertSame('error', $unknownEvent['context']['outcome']);
        $this->assertArrayNotHasKey('arguments', $unknownEvent['context']);
        $this->assertStringNotContainsString($unknownToolName, json_encode($unknownEvent['context'], JSON_THROW_ON_ERROR));

        $unknownPromptEvent = $events[6];
        $this->assertSame('mcp.unknown_prompt', $unknownPromptEvent['context']['capability']);
        $this->assertSame('mcp-unknown', $unknownPromptEvent['context']['rate_limit_bucket']);
        $this->assertSame('mcp.unknown', $unknownPromptEvent['context']['audit_classification']);
        $this->assertSame('error', $unknownPromptEvent['context']['outcome']);
        $this->assertStringNotContainsString($unknownPromptName, json_encode($unknownPromptEvent['context'], JSON_THROW_ON_ERROR));

        Event::assertDispatchedTimes(McpCapabilityInvoked::class, 7);
        Event::assertDispatched(McpCapabilityInvoked::class, static fn (McpCapabilityInvoked $event): bool => $event->capability === 'projects.list'
            && $event->auditClassification === 'agent_api.read'
            && $event->subjectId === $user->public_id
            && preg_match('/^[a-f0-9]{64}$/', $event->credentialFingerprint) === 1
            && preg_match('/^[a-f0-9]{64}$/', $event->clientFingerprint) === 1);
    }

    public function test_mcp_requires_connection_scope_but_allows_preflight(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['http://localhost']]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::PROJECTS_READ]);

        $this->mcp($this->initializeMessage())->assertForbidden();
        $this->options('/api/v1/mcp', [], [
            'Origin' => 'http://localhost',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type, mcp-protocol-version, mcp-session-id',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost')
            ->assertHeader('Access-Control-Allow-Methods', 'POST, DELETE, OPTIONS');
    }

    public function test_unapproved_browser_origin_gets_no_cors_authorization_and_is_rejected(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['https://approved.example']]);

        $this->options('/api/v1/mcp', [], [
            'Origin' => 'https://unapproved.example',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'authorization, content-type',
        ])->assertNoContent()->assertHeaderMissing('Access-Control-Allow-Origin');

        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);
        $this->withHeader('Origin', 'https://unapproved.example')
            ->postJson('/api/v1/mcp', $this->initializeMessage(), ['Mcp-Protocol-Version' => '2025-06-18'])
            ->assertForbidden()
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_approved_browser_origin_can_initialize_and_receives_cors_headers(): void
    {
        config(['agent_api.mcp_allowed_origins' => ['https://approved.example']]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $this->withHeaders([
            'Origin' => 'https://approved.example',
            'Mcp-Protocol-Version' => '2025-06-18',
        ])->postJson('/api/v1/mcp', $this->initializeMessage())
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', 'https://approved.example')
            ->assertHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, Mcp-Protocol-Version');
    }

    public function test_originless_native_cli_request_remains_supported(): void
    {
        config(['agent_api.mcp_allowed_origins' => []]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);

        $this->mcp($this->initializeMessage())->assertOk()->assertHeader('Mcp-Session-Id');
    }

    public function test_unauthenticated_mcp_request_returns_an_oauth_resource_challenge(): void
    {
        $this->mcp($this->initializeMessage())
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', sprintf(
                'Bearer resource_metadata="%s"',
                url('/.well-known/oauth-protected-resource/api/v1/mcp'),
            ));
    }

    public function test_mcp_rejects_query_string_credentials_before_authentication(): void
    {
        $this->call('POST', '/api/v1/mcp?access_token=do-not-log-this', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18',
        ], json_encode($this->initializeMessage(), JSON_THROW_ON_ERROR))
            ->assertBadRequest()
            ->assertJsonPath('message', 'MCP credentials must not be sent in the query string.')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_mcp_session_cannot_be_reused_under_another_credential(): void
    {
        $firstUser = User::factory()->create();
        $this->actingAsMcp($firstUser, [AgentApiScopes::MCP_USE]);
        $session = $this->initialize();

        $secondUser = User::factory()->create();
        $this->actingAsMcp($secondUser, [AgentApiScopes::MCP_USE]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $session)
            ->assertNotFound()
            ->assertJsonPath('error.message', 'Session not found or has expired.');
    }

    public function test_mcp_session_preserves_compatible_per_call_authorized_workspace_selection(): void
    {
        $user = User::factory()->create();
        $first = Workspace::query()->create(['name' => 'First MCP Workspace', 'slug' => 'first-mcp-workspace']);
        $second = Workspace::query()->create(['name' => 'Second MCP Workspace', 'slug' => 'second-mcp-workspace']);
        $projectIds = [];
        foreach ([$first, $second] as $workspace) {
            WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
            $company = ClientCompany::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $workspace->name.' Client',
                'slug' => $workspace->slug.'-client',
            ]);
            $project = ClientProject::query()->create([
                'workspace_id' => $workspace->id,
                'client_company_id' => $company->id,
                'name' => $workspace->name.' Project',
            ]);
            $projectIds[$workspace->public_id] = $project->public_id;
        }
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();

        foreach ([$first, $second] as $index => $workspace) {
            $response = $this->mcp([
                'jsonrpc' => '2.0',
                'id' => $index + 2,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'projects.list',
                    'arguments' => ['workspace_id' => $workspace->public_id],
                ],
            ], $session)->assertOk()->json('result');

            $this->assertFalse($response['isError']);
            $this->assertCount(1, $response['structuredContent']['data']);
            $this->assertSame($projectIds[$workspace->public_id], $response['structuredContent']['data'][0]['id']);
        }
    }

    public function test_mcp_session_rechecks_credential_revocation_before_execution(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE]);
        $session = $this->initialize();
        Token::query()->where('user_id', $user->id)->update(['revoked' => true]);

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ], $session)->assertUnauthorized();
    }

    public function test_time_write_tools_are_absent_when_the_time_cutoff_is_disabled(): void
    {
        config([
            'agent_api.writes_enabled' => true,
            'agent_api.time_entry_writes_enabled' => false,
        ]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TIME_WRITE,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->json('result.tools');

        $this->assertNotContains('time_entries.log', array_column($tools, 'name'));
    }

    public function test_time_entry_cutover_exposes_only_draft_time_mutations(): void
    {
        config([
            'agent_api.writes_enabled' => false,
            'agent_api.time_entry_writes_enabled' => true,
        ]);
        $user = User::factory()->create();
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');

        $this->assertSame(
            ['time_entries.log', 'time_entries.update', 'time_entries.delete'],
            array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'time_entries.'))),
        );
        $this->assertNotContains('tasks.create', $names);
        $this->assertNotContains('time_entries.approve', $names);
        $this->assertNotContains('invoices.create_draft', $names);
    }

    public function test_tool_discovery_omits_operations_outside_the_current_token_scopes(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');

        $this->assertSame(['projects.list', 'projects.get'], array_column($tools, 'name'));
    }

    public function test_per_capability_kill_switches_remove_tools_and_the_global_switch_refuses_service(): void
    {
        $user = User::factory()->create();
        $this->actingAsMcp($user, [AgentApiScopes::MCP_USE, AgentApiScopes::PROJECTS_READ]);

        $session = $this->initialize();
        config(['agent_api.mcp_feature_flags' => ['projects.list' => false]]);
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)
            ->assertOk()->json('result.tools');
        $this->assertSame(['projects.get'], array_column($tools, 'name'));

        $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'projects.list', 'arguments' => ['workspace_id' => (string) str()->uuid()]],
        ], $session)
            ->assertOk()
            ->assertJsonPath('error.code', -32601)
            ->assertJsonMissingPath('result');

        config(['agent_api.mcp_enabled' => false]);
        $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list', 'params' => []], $session)
            ->assertServiceUnavailable()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Retry-After', '60')
            ->assertExactJson(['message' => 'The SVC MCP service is temporarily unavailable.'])
            ->assertJsonMissingPath('result');
    }

    public function test_write_catalog_is_conditionally_registered_after_cutover(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'MCP write workspace', 'slug' => 'mcp-write-workspace']);
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'admin']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'MCP write client', 'slug' => 'mcp-write-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'MCP write project']);
        $this->actingAsMcp($user, [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_WRITE,
            AgentApiScopes::BILLING_DELIVER,
        ]);
        $session = $this->initialize();
        $tools = $this->mcp(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $session)->assertOk()->json('result.tools');
        if (! is_array($tools)) {
            throw new \LogicException('The MCP tool list must be an array.');
        }
        $byName = [];
        foreach ($tools as $tool) {
            if (! is_array($tool) || ! isset($tool['name']) || ! is_string($tool['name'])) {
                throw new \LogicException('Every MCP tool must have a string name.');
            }
            $byName[$tool['name']] = $tool;
        }

        $this->assertFalse($byName['time_entries.log']['annotations']['readOnlyHint']);
        $this->assertTrue($byName['time_entries.delete']['annotations']['destructiveHint']);
        $this->assertTrue($byName['invoices.discard_draft']['annotations']['destructiveHint']);
        $this->assertFalse($byName['tasks.create']['inputSchema']['additionalProperties']);

        $createdTask = $this->mcp(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'tasks.create', 'arguments' => ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'title' => 'MCP task', 'idempotency_key' => 'mcp-task-create-1']]], $session)->assertOk()->json('result');
        $this->assertFalse($createdTask['isError']);
        $task = $createdTask['structuredContent']['data'];
        $updatedTask = $this->mcp(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'tasks.update', 'arguments' => ['workspace_id' => $workspace->public_id, 'task_id' => $task['id'], 'expected_version' => $task['version'], 'idempotency_key' => 'mcp-task-update-1', 'status' => 'completed']]], $session)->assertOk()->json('result');
        $this->assertFalse($updatedTask['isError']);
        $this->assertSame('completed', $updatedTask['structuredContent']['data']['status']);

        $result = $this->mcp(['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'time_entries.log', 'arguments' => ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'mcp-log-1', 'entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'MCP work']]]]], $session)->assertOk()->json('result');
        $this->assertFalse($result['isError']);
        $this->assertSame('MCP work', $result['structuredContent']['data'][0]['description']);

        $entry = $result['structuredContent']['data'][0];
        $updated = $this->mcp(['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'time_entries.update', 'arguments' => ['workspace_id' => $workspace->public_id, 'entry_id' => $entry['id'], 'expected_version' => $entry['version'], 'idempotency_key' => 'mcp-update-1', 'description' => 'MCP work revised']]], $session)->assertOk()->json('result');
        $this->assertFalse($updated['isError']);
        $this->assertSame('MCP work revised', $updated['structuredContent']['data']['description']);

        $deleted = $this->mcp(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'time_entries.delete', 'arguments' => ['workspace_id' => $workspace->public_id, 'entry_id' => $entry['id'], 'expected_version' => $updated['structuredContent']['data']['version'], 'idempotency_key' => 'mcp-delete-1']]], $session)->assertOk()->json('result');
        $this->assertFalse($deleted['isError']);
        $this->assertSame($entry['id'], $deleted['structuredContent']['data']['deleted_id']);

        WorkspaceMembership::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->delete();

        $replay = $this->mcp(['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'time_entries.log', 'arguments' => ['workspace_id' => $workspace->public_id, 'idempotency_key' => 'mcp-log-1', 'entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'MCP work']]]]], $session)->assertOk();
        $this->assertNull($replay->json('result'));
        $this->assertIsArray($replay->json('error'));
        $this->assertDatabaseCount('client_time_entries', 1);
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
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']]];
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
