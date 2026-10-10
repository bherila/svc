<?php

namespace Tests\Feature\AgentApi;

use App\Http\Middleware\EnsureAgentWorkspaceVisible;
use App\Http\Middleware\EnsureOperationDeployed;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\Operations\AgentDeploymentFlags;
use App\Services\AgentApi\Operations\AgentOperationCatalog;
use App\Services\Mcp\McpFeatureFlags;
use App\Support\AgentApi\FirstPartySession;
use Bherila\McpLaravelBridge\Capabilities\OperationRegistry;
use Bherila\McpLaravelBridge\Http\GateOperation;
use Bherila\McpLaravelBridge\Testing\OperationRegistryAssertions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Laravel\Passport\Http\Middleware\CheckToken;
use Tests\TestCase;

/**
 * The shared operation registry (#408): one declaration per agent operation,
 * from which the REST routes and their gate are derived.
 */
final class AgentOperationRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const string SCOPE_INVENTORY = 'tests/Fixtures/AgentApi/operation-scopes.json';

    public function test_the_registry_is_sound_and_every_declared_route_exists(): void
    {
        OperationRegistryAssertions::assertOperationRegistryContract(app(OperationRegistry::class), null, ['mcp:use']);
        $this->assertSame(app(AgentOperationCatalog::class)->registry(), app(OperationRegistry::class));
    }

    /** A scope or switch change to any operation is a reviewed diff. */
    public function test_each_operation_requirement_matches_the_reviewed_inventory(): void
    {
        OperationRegistryAssertions::assertScopeInventory(app(OperationRegistry::class), base_path(self::SCOPE_INVENTORY));
    }

    /**
     * Every agent REST route is an operation route: the registry's gate and the
     * deployment check, named for its own operation, and no scope middleware
     * restating what the declaration already says.
     */
    public function test_every_agent_rest_route_is_bound_from_its_operation(): void
    {
        $registry = app(OperationRegistry::class);
        $bound = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            $name = (string) $route->getName();
            if (! str_starts_with($name, 'agent-api.v1.') || in_array($name, ['agent-api.v1.mcp', 'agent-api.v1.mcp.options'], true)) {
                continue;
            }
            $middleware = app('router')->gatherRouteMiddleware($route);
            $gates = array_values(array_filter($middleware, static fn (string $entry): bool => str_starts_with($entry, GateOperation::class.':')));
            $this->assertCount(1, $gates, $name);
            $operationId = substr($gates[0], strlen(GateOperation::class.':'));
            $operation = $registry->find($operationId);
            $this->assertNotNull($operation, $name);
            $this->assertSame($operation->rest?->routeName, $name);
            $this->assertContains(EnsureOperationDeployed::class.':'.$operationId, $middleware, $name);
            $this->assertSame([], array_values(array_filter($middleware, static fn (string $entry): bool => str_starts_with($entry, CheckToken::class))), $name);

            // Concealment, then the deployment check, then the gate.
            $deployed = array_search(EnsureOperationDeployed::class.':'.$operationId, $middleware, true);
            $gate = array_search($gates[0], $middleware, true);
            $this->assertLessThan($gate, $deployed, $name);
            $visible = array_search(EnsureAgentWorkspaceVisible::class, $middleware, true);
            if ($visible !== false) {
                $this->assertLessThan($deployed, $visible, $name);
            }
            $bound[] = $operationId;
        }
        $restOperations = array_map(static fn ($operation): string => $operation->id, array_filter($registry->all(), static fn ($operation): bool => $operation->rest !== null));
        sort($bound);
        sort($restOperations);
        $this->assertSame(array_values($restOperations), $bound);
    }

    /** Where the document records an operation's cutovers, the declaration agrees. */
    public function test_declared_cutovers_agree_with_the_documented_flags(): void
    {
        $document = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $names = [
            'AGENT_API_WRITES_ENABLED' => AgentDeploymentFlags::WRITES,
            'AGENT_API_INVOICE_WRITES_ENABLED' => AgentDeploymentFlags::INVOICES,
            'AGENT_API_CLIENT_WRITES_ENABLED' => AgentDeploymentFlags::CLIENTS,
        ];
        $checked = 0;
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $documented) {
                if (! is_array($documented) || ! array_key_exists('x-svc-flags', $documented)) {
                    continue;
                }
                $operation = app(OperationRegistry::class)->find($documented['operationId']);
                $this->assertNotNull($operation);
                $declared = array_values(array_filter($operation->requirement->flags, static fn (string $flag): bool => ! AgentDeploymentFlags::isMcpSwitch($flag)));
                // The document lists the outer cutover too; the declaration names
                // the innermost one, which nests inside it.
                $flags = $documented['x-svc-flags'];
                $expected = $flags === [] ? [] : [$names[$flags[array_key_last($flags)]]];
                $this->assertSame($expected, $declared, $documented['operationId']);
                $checked++;
            }
        }
        $this->assertSame(22, $checked);
    }

    public function test_an_under_scoped_rest_call_is_refused_by_the_gate_with_its_reason(): void
    {
        [$owner, $workspace] = $this->owner();
        $this->actingAsMcp($owner, ['identity:read']);

        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")
            ->assertForbidden()
            ->assertJsonPath('operation', 'invoices.list')
            ->assertJsonPath('reason', 'missing_scope')
            ->assertJsonPath('detail', 'billing:read')
            ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="billing:read"');
        $this->actingAsMcp($owner, ['billing:read']);
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")->assertOk();
    }

    /** A switched-off cutover answers 404 and never names the switch, even to an under-scoped caller. */
    public function test_a_switched_off_cutover_is_concealed_as_not_found(): void
    {
        [$owner, $workspace] = $this->owner();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => false]);

        foreach ([['billing:write'], ['identity:read']] as $scopes) {
            $this->actingAsMcp($owner, $scopes);
            $response = $this->withHeader('Idempotency-Key', 'synthetic-'.Str::random(8))
                ->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices", [])
                ->assertNotFound();
            $this->assertStringNotContainsString('agent.writes', (string) $response->getContent());
            $this->assertStringNotContainsString('INVOICE', (string) $response->getContent());
        }
    }

    /** The MCP kill switches belong to MCP: REST keeps serving when they are thrown. */
    public function test_mcp_switches_never_withhold_rest(): void
    {
        [$owner, $workspace] = $this->owner();
        config(['agent_api.mcp_enabled' => false, 'agent_api.mcp_feature_flags' => ['mcp.read' => false, 'context.get' => false, 'invoices.list' => false]]);
        $this->actingAsMcp($owner, ['identity:read', 'billing:read']);

        $this->getJson('/api/v1/context')->assertOk();
        $this->getJson("/api/v1/workspaces/{$workspace->public_id}/invoices")->assertOk();
    }

    public function test_the_signed_in_website_passes_cutovers_for_rest_but_agents_are_never_offered_them(): void
    {
        config(['agent_api.writes_enabled' => false, 'agent_api.invoice_writes_enabled' => false, 'agent_api.time_entry_writes_enabled' => false]);
        $rest = AgentDeploymentFlags::forRest();
        $agents = AgentDeploymentFlags::forAgents(app(McpFeatureFlags::class));
        foreach (AgentDeploymentFlags::cutovers() as $flag) {
            if ($flag === AgentDeploymentFlags::TIME_ENTRIES) {
                continue;
            }
            $this->assertFalse($rest->enabled($flag), $flag);
        }

        $request = Request::create('/api/v1/context');
        $request->attributes->set(FirstPartySession::ATTRIBUTE, true);
        $this->app->instance('request', $request);

        foreach (AgentDeploymentFlags::cutovers() as $flag) {
            $this->assertTrue($rest->enabled($flag), $flag);
            $this->assertFalse($agents->enabled($flag), $flag);
        }
        // Drift in a declaration is never opened by the bypass.
        $this->assertFalse($rest->enabled('agent.writes.unknown'));
    }

    public function test_inner_cutovers_never_reopen_what_the_outer_one_withdrew(): void
    {
        $flags = AgentDeploymentFlags::forRest();
        foreach (AgentDeploymentFlags::cutovers() as $flag) {
            config(['agent_api.writes_enabled' => false, 'agent_api.time_entry_writes_enabled' => true]);
            foreach (['invoice', 'payment', 'expense', 'client', 'project', 'proposal', 'workspace', 'file'] as $inner) {
                config(["agent_api.{$inner}_writes_enabled" => true]);
            }
            $this->assertSame($flag === AgentDeploymentFlags::TIME_ENTRIES, $flags->enabled($flag), $flag);
        }
    }

    /** @return array{User, Workspace} */
    private function owner(): array
    {
        $owner = User::factory()->create(['email' => 'owner-'.Str::lower(Str::random(6)).'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic registry workspace', 'slug' => 'synthetic-registry-'.Str::lower(Str::random(8))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$owner, $workspace];
    }
}
