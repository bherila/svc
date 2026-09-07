<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\AgentMcpReadTools;
use App\Services\Mcp\AgentMcpToolCatalog;
use App\Services\Mcp\AgentMcpWriteTools;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Mcp\ToolDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Agent-initiated invoice writes have their own cutover.
 *
 * `AGENT_API_WRITES_ENABLED` gated nine tools in one block: approving time,
 * creating and updating tasks, and the six invoice operations. Creating a task
 * is recoverable bookkeeping; `invoices.issue` allocates approved time
 * irreversibly and `invoices.send` puts a document in front of a paying
 * client. There was no setting that admitted the first group and withheld the
 * second, so an operator who wanted agent-assisted time approval had to accept
 * agent-initiated invoice delivery in the same move (#242).
 *
 * The boundary follows blast radius, and it is **nested** rather than
 * independent: the invoice flag can only ever narrow what the outer cutover
 * already allows. The tests below pin all four corners of that, because a flag
 * that is only ever tested in its two agreeing positions is not shown to be a
 * boundary at all.
 *
 * They also pin the *whole* surface rather than the MCP catalog alone. The
 * flag has to withhold the REST routes, the advertised capabilities and any
 * prompt built on the withheld tools too - a gate that closes one of four
 * doors is not a cutover, and the capability list is what an agent plans
 * against before it makes a single call.
 */
final class AgentInvoiceWriteFlagTest extends TestCase
{
    use RefreshDatabase;

    private const INVOICE_TOOLS = [
        'invoices.create_draft',
        'invoices.update_draft',
        'invoices.discard_draft',
        'invoices.issue',
        'invoices.send',
        'invoices.void',
    ];

    private const WORKFLOW_TOOLS = [
        'time_entries.approve',
        'tasks.create',
        'tasks.update',
    ];

    /**
     * Every combination of the two flags, and what the invoice tools do in it.
     *
     * The third row is the one that matters: the inner flag must not be able to
     * re-open a surface the outer cutover has withdrawn, or
     * `AGENT_API_WRITES_ENABLED=false` stops being the emergency stop it is
     * documented as.
     */
    public static function flagCombinations(): iterable
    {
        yield 'both off' => [false, false, false, false];
        yield 'workflow on, invoices off' => [true, false, true, false];
        yield 'workflow off, invoices on' => [false, true, false, false];
        yield 'both on' => [true, true, true, true];
    }

    #[DataProvider('flagCombinations')]
    public function test_the_catalog_gates_invoice_tools_on_both_flags(
        bool $writes,
        bool $invoiceWrites,
        bool $expectsWorkflowTools,
        bool $expectsInvoiceTools,
    ): void {
        config([
            'agent_api.writes_enabled' => $writes,
            'agent_api.invoice_writes_enabled' => $invoiceWrites,
        ]);

        $names = array_map(
            static fn (ToolDefinition $definition): string => $definition->name,
            app(AgentMcpToolCatalog::class)->definitions(
                app(AgentMcpReadTools::class),
                app(AgentMcpWriteTools::class),
            ),
        );

        foreach (self::WORKFLOW_TOOLS as $tool) {
            $this->assertSame($expectsWorkflowTools, in_array($tool, $names, true), $tool);
        }
        foreach (self::INVOICE_TOOLS as $tool) {
            $this->assertSame($expectsInvoiceTools, in_array($tool, $names, true), $tool);
        }

        // Reading an invoice is not a write and is never gated by either flag.
        $this->assertContains('invoices.list', $names);
        $this->assertContains('invoices.get', $names);
    }

    /**
     * The REST surface closes with the catalog.
     *
     * `routes/api.php` is a second door onto the same controllers. Gating only
     * the MCP catalog would leave an agent holding `billing:deliver` able to
     * issue and send over HTTP while the tool list said it could not - the
     * cutover would read as done and not be.
     */
    public function test_the_rest_invoice_routes_are_withheld_while_the_flag_is_off(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => false]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAsAgent($owner, [
            AgentApiScopes::BILLING_WRITE,
            AgentApiScopes::BILLING_DELIVER,
            AgentApiScopes::TASKS_WRITE,
        ]);
        $base = "/api/v1/workspaces/{$workspace->public_id}";

        $this->withHeader('Idempotency-Key', 'gated-create')
            ->postJson($base.'/invoices', ['company_id' => $company->public_id])
            ->assertNotFound();
        foreach (['issue', 'send', 'void', 'discard'] as $action) {
            $this->withHeader('Idempotency-Key', 'gated-'.$action)
                ->postJson($base.'/invoices/'.Str::uuid()->toString().'/'.$action)
                ->assertNotFound();
        }
        $this->withHeader('Idempotency-Key', 'gated-update')
            ->patchJson($base.'/invoices/'.Str::uuid()->toString(), [])
            ->assertNotFound();

        // The task route shares the outer cutover and is deliberately untouched:
        // this flag withholds invoices, not workflow writes. Not a 404, which is
        // what the invoice routes answer - whatever it validates its way to.
        $tasks = $this->withHeader('Idempotency-Key', 'ungated-task')
            ->postJson($base."/projects/{$project->public_id}/tasks", ['title' => 'Still allowed']);
        $this->assertNotSame(404, $tasks->getStatusCode());
    }

    /**
     * The advertised capabilities close with the surface.
     *
     * `context.get` is what an agent reads before planning anything. Naming
     * `billing:deliver` while every invoice route answers 404 turns a
     * deliberate cutover into a workflow that fails part-way through, after the
     * agent has already told someone it would send the invoice.
     */
    public function test_billing_write_capabilities_are_not_advertised_while_the_flag_is_off(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => false]);
        [$owner, $workspace] = $this->tenant();
        $this->actingAsAgent($owner, [
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TASKS_READ,
            AgentApiScopes::TASKS_WRITE,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_APPROVE,
            AgentApiScopes::BILLING_READ,
            AgentApiScopes::BILLING_WRITE,
            AgentApiScopes::BILLING_DELIVER,
        ]);

        $capabilities = $this->getJson('/api/v1/context')
            ->assertOk()
            ->json('data.workspaces.0.capabilities');

        $this->assertIsArray($capabilities);
        $this->assertContains('billing:read', $capabilities, 'Reading invoices is unaffected');
        $this->assertContains('tasks:write', $capabilities, 'The outer cutover still applies');
        $this->assertContains('time:approve', $capabilities);
        $this->assertNotContains('billing:write', $capabilities);
        $this->assertNotContains('billing:deliver', $capabilities);

        config(['agent_api.invoice_writes_enabled' => true]);
        $enabled = $this->getJson('/api/v1/context')->assertOk()->json('data.workspaces.0.capabilities');
        $this->assertContains('billing:write', $enabled);
        $this->assertContains('billing:deliver', $enabled);
    }

    /**
     * A prompt built on withheld tools is withheld with them.
     *
     * `prepare-invoice-safely` declares `invoices.create_draft` and
     * `invoices.update_draft` in its `requiredCapabilities`, and
     * `AgentMcpServerFactory` drops any capability whose requirements are not
     * all present. So the cascade already exists - this pins it, because
     * offering an agent a prompt that walks it into two tools it cannot call
     * is a worse failure than not offering the prompt at all.
     */
    public function test_the_invoice_prompt_is_withheld_with_the_tools_it_needs(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => false]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Prompt Flag', 'slug' => 'prompt-flag-'.Str::random(8)]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'admin']);
        $scopes = [
            AgentApiScopes::MCP_USE,
            AgentApiScopes::IDENTITY_READ,
            AgentApiScopes::PROJECTS_READ,
            AgentApiScopes::TIME_READ,
            AgentApiScopes::TIME_WRITE,
            AgentApiScopes::BILLING_READ,
            AgentApiScopes::BILLING_WRITE,
        ];
        $this->actingAsMcp($user, $scopes);

        $this->assertSame(['log-time-across-projects'], $this->promptNames());

        config(['agent_api.invoice_writes_enabled' => true]);
        $this->actingAsMcp($user, $scopes);
        $this->assertSame(
            ['log-time-across-projects', 'prepare-invoice-safely'],
            $this->promptNames(),
        );
    }

    /** @return list<string> */
    private function promptNames(): array
    {
        $session = $this->mcp([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'SVC test', 'version' => '1']],
        ])->assertOk()->headers->get('Mcp-Session-Id');
        $this->assertIsString($session);

        $prompts = $this->mcp([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'prompts/list', 'params' => [],
        ], $session)->assertOk()->json('result.prompts');
        $this->assertIsArray($prompts);

        return array_values(array_map(
            static fn (array $prompt): string => (string) $prompt['name'],
            $prompts,
        ));
    }

    /**
     * @param  array<string, mixed>  $message
     * @return TestResponse<Response>
     */
    private function mcp(array $message, ?string $session = null): TestResponse
    {
        $headers = ['Mcp-Protocol-Version' => '2025-06-18'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return $this->postJson('/api/v1/mcp', $message, $headers);
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany,3:ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Invoice Flag', 'slug' => 'invoice-flag-'.Str::random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Flag Client',
            'slug' => 'flag-client-'.Str::random(8),
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Flag Project',
        ]);

        return [$owner, $workspace, $company, $project];
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
