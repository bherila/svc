<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Mcp\AgentWithheldTools;
use App\Support\AgentApi\AgentApiScopes;
use Bherila\McpLaravelBridge\Capabilities\Operation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CallsMcp;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

final class AgentMcpInventoryTest extends TestCase
{
    use CallsMcp;
    use InspectsAgentOperations;
    use RefreshDatabase;

    // Exact integrated inventory: extend only when the corresponding handlers and contracts land.
    private const array TOOLS = [
        'agreements.activate',
        'agreements.create',
        'agreements.get',
        'agreements.list',
        'agreements.terminate',
        'agreements.update',
        'attachments.delete',
        'attachments.download_url',
        'attachments.get',
        'attachments.list',
        'attachments.upload_url',
        'billing.audit_missing_billed_overage',
        'billing.audit_opening_rollover',
        'billing.audit_undated_collectible_invoices',
        'billing.audit_unplaceable_invoices',
        'billing_audit.stale_and_missing',
        'billing_schedules.create',
        'billing_schedules.generate',
        'billing_schedules.get',
        'billing_schedules.list',
        'capacity_ledger.get',
        'clients.archive',
        'clients.create',
        'clients.get',
        'clients.list',
        'clients.restore',
        'clients.update',
        'context.get',
        'expense_schedules.create',
        'expense_schedules.generate',
        'expense_schedules.list',
        'expense_schedules.update',
        'expenses.approve',
        'expenses.delete',
        'expenses.list',
        'expenses.log',
        'expenses.receipts.download',
        'expenses.receipts.list',
        'expenses.receipts.upload_url',
        'expenses.unapprove',
        'expenses.update',
        'invoices.add_time',
        'invoices.correct',
        'invoices.create_draft',
        'invoices.discard_draft',
        'invoices.generate_period',
        'invoices.get',
        'invoices.hold_delivery',
        'invoices.issue',
        'invoices.list',
        'invoices.pdf',
        'invoices.release_delivery',
        'invoices.send',
        'invoices.update_details',
        'invoices.update_draft',
        'invoices.void',
        'operations.summary',
        'payments.correct',
        'payments.list',
        'payments.record',
        'projects.archive',
        'projects.create',
        'projects.get',
        'projects.list',
        'projects.members.list',
        'projects.members.update',
        'projects.update',
        'proposals.accept',
        'proposals.create',
        'proposals.get',
        'proposals.list',
        'proposals.send',
        'search',
        'tasks.create',
        'tasks.get',
        'tasks.list',
        'tasks.update',
        'time_entries.approve',
        'time_entries.delete',
        'time_entries.list',
        'time_entries.log',
        'time_entries.unapprove',
        'time_entries.update',
        'workspaces.create',
    ];

    /** @return array<string, list<string>> */
    private static function gatedTools(): array
    {
        return [
            'time_entry_writes_enabled' => ['time_entries.log', 'time_entries.update', 'time_entries.delete', 'time_entries.unapprove'],
            'invoice_writes_enabled' => ['billing_schedules.create', 'billing_schedules.generate', 'invoices.hold_delivery', 'invoices.release_delivery', 'invoices.add_time', 'invoices.generate_period', 'invoices.create_draft', 'invoices.update_draft', 'invoices.update_details', 'invoices.correct', 'invoices.discard_draft', 'invoices.issue', 'invoices.send', 'invoices.void'],
            'expense_writes_enabled' => ['expenses.log', 'expenses.update', 'expenses.delete', 'expenses.approve', 'expenses.unapprove', 'expense_schedules.create', 'expense_schedules.update', 'expense_schedules.generate', 'expenses.receipts.upload_url'],
            'payment_writes_enabled' => ['payments.record', 'payments.correct'],
            'client_writes_enabled' => ['clients.create', 'clients.update', 'clients.archive', 'clients.restore', 'agreements.create', 'agreements.update', 'agreements.activate', 'agreements.terminate'],
            'project_writes_enabled' => ['projects.create', 'projects.update', 'projects.archive', 'projects.members.update'],
            'proposal_writes_enabled' => ['proposals.create', 'proposals.send', 'proposals.accept'],
            'workspace_writes_enabled' => ['workspaces.create'],
            'file_writes_enabled' => ['attachments.upload_url', 'attachments.delete'],
        ];
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function disabledCutovers(): iterable
    {
        foreach (self::gatedTools() as $flag => $names) {
            yield $flag => [$flag, $names];
        }
        $outer = ['time_entries.approve', 'time_entries.unapprove', 'tasks.create', 'tasks.update'];
        foreach (self::gatedTools() as $flag => $names) {
            if ($flag !== 'time_entry_writes_enabled') {
                $outer = [...$outer, ...$names];
            }
        }
        yield 'outer workflow' => ['writes_enabled', $outer];
    }

    /** @param list<string> $disabled */
    #[DataProvider('disabledCutovers')]
    public function test_inventory_retains_every_tool_when_a_cutover_is_disabled(string $flag, array $disabled): void
    {
        $this->enableAll();
        $this->assertSame(self::TOOLS, $this->inventory());
        $this->assertSame(self::TOOLS, $this->deployed());
        config(['agent_api.'.$flag => false]);
        $this->assertSame(self::TOOLS, $this->inventory());
        $expected = array_values(array_diff(self::TOOLS, $disabled));
        $this->assertSame($expected, $this->deployed());
        sort($disabled);
        $this->assertSame(array_map(fn (string $name): array => ['name' => $name, 'reason' => 'deployment_disabled'], $disabled),
            app(AgentWithheldTools::class)->for(fn (string $scope): bool => true, true));
    }

    public function test_inventory_retains_all_84_tools_when_every_cutover_is_disabled(): void
    {
        $this->enableAll();
        $disabled = ['time_entries.approve', 'tasks.create', 'tasks.update'];
        foreach (self::gatedTools() as $flag => $names) {
            config(['agent_api.'.$flag => false]);
            $disabled = [...$disabled, ...$names];
        }
        config(['agent_api.writes_enabled' => false]);
        $disabled = array_values(array_unique($disabled));
        sort($disabled);
        $this->assertCount(84, self::TOOLS);
        $this->assertSame(self::TOOLS, $this->inventory());
        $this->assertSame(array_values(array_diff(self::TOOLS, $disabled)), $this->deployed());
        $this->assertSame(array_map(fn (string $name): array => ['name' => $name, 'reason' => 'deployment_disabled'], $disabled),
            app(AgentWithheldTools::class)->for(fn (string $scope): bool => true, true));
    }

    public function test_actual_mcp_discovery_and_context_partition_all_84_tools_after_every_cutover_is_disabled(): void
    {
        $this->enableAll();
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Inventory Workspace', 'slug' => 'synthetic-inventory']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, array_keys(AgentApiScopes::descriptions()));
        $all = $this->toolNames($this->initialize());
        sort($all);
        $this->assertSame(self::TOOLS, $all);
        foreach (array_keys(self::gatedTools()) as $flag) {
            config(['agent_api.'.$flag => false]);
        }
        config(['agent_api.writes_enabled' => false]);
        $enabled = $this->toolNames($this->initialize());
        sort($enabled);
        $this->assertSame($this->deployed(), $enabled);
        $withheld = $this->getJson('/api/v1/context')->assertOk()->json('data.withheld_tools');
        $this->assertSame(app(AgentWithheldTools::class)->for(fn (string $scope): bool => true, true), $withheld);
        foreach ($withheld as $tool) {
            $this->assertSame('deployment_disabled', $tool['reason']);
            $this->assertNotContains($tool['name'], $enabled);
        }
        $partition = [...$enabled, ...array_column($withheld, 'name')];
        sort($partition);
        $this->assertSame(self::TOOLS, $partition);
    }

    public function test_inventory_and_openapi_map_every_integrated_tool_with_matching_scopes(): void
    {
        $this->enableAll();
        // These historical read tools predate REST parity and are outside the
        // remaining operations named in #386. Every added tool is mapped.
        $legacy = ['capacity_ledger.get', 'billing.audit_unplaceable_invoices', 'billing.audit_undated_collectible_invoices', 'billing.audit_missing_billed_overage', 'billing.audit_opening_rollover'];
        $spec = json_decode((string) file_get_contents(public_path('openapi/svc-agent-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $mapped = [];
        $definitions = collect($this->mcpTools())->keyBy(fn (Operation $operation): string => (string) $operation->mcpName());
        foreach ($spec['paths'] as $operations) {
            foreach ($operations as $operation) {
                if (! is_array($operation)) {
                    continue;
                }
                $name = $operation['x-mcp-tool'] ?? $operation['operationId'] ?? null;
                if (! is_string($name) || ! $definitions->has($name)) {
                    continue;
                }
                $this->assertSame($name, $operation['operationId']);
                $this->assertArrayNotHasKey($name, $mapped, 'An MCP tool must have one canonical REST operation.');
                $this->assertTrue($definitions->has($name), $name);
                $this->assertSame($operation['security'][0]['oauth2'], $definitions[$name]->requirement->scopes, $name);
                $mapped[$name] = true;
            }
        }
        $actual = array_keys($mapped);
        sort($actual);
        $this->assertSame(array_values(array_diff(self::TOOLS, $legacy)), $actual);
    }

    public function test_missing_scopes_precede_role_and_every_tool_reports_its_required_scope(): void
    {
        $this->enableAll();
        $withheld = collect(app(AgentWithheldTools::class)->for(fn (string $scope): bool => false, false))->keyBy('name');
        $this->assertSame(self::TOOLS, $withheld->keys()->all());
        foreach ($this->mcpTools() as $definition) {
            $this->assertNotEmpty($definition->requirement->scopes, $definition->id);
            $this->assertSame(['name' => $definition->mcpName(), 'reason' => 'scope_not_granted', 'scope' => $definition->requirement->scopes[0]], $withheld[$definition->mcpName()]);
        }
    }

    public function test_new_manager_operations_report_role_while_ordinary_reads_remain_available(): void
    {
        $this->enableAll();
        $withheld = collect(app(AgentWithheldTools::class)->for(fn (string $scope): bool => true, false))->keyBy('name');
        foreach (['clients.list', 'clients.get', 'agreements.list', 'agreements.get',
            'invoices.hold_delivery', 'invoices.release_delivery', 'invoices.add_time', 'invoices.generate_period',
            'billing_audit.stale_and_missing', 'billing_schedules.list', 'billing_schedules.get', 'billing_schedules.create', 'billing_schedules.generate',
            'projects.create', 'projects.update', 'projects.archive', 'projects.members.list', 'projects.members.update',
            'proposals.create', 'proposals.send', 'time_entries.unapprove',
            'attachments.list', 'attachments.get', 'attachments.download_url', 'attachments.upload_url', 'attachments.delete',
            'expense_schedules.list', 'expense_schedules.create', 'expense_schedules.update', 'expense_schedules.generate',
            'expenses.approve', 'expenses.unapprove', 'expenses.receipts.list', 'expenses.receipts.download', 'expenses.receipts.upload_url'] as $name) {
            $this->assertSame(['name' => $name, 'reason' => 'role'], $withheld[$name], $name);
        }
        foreach (['context.get', 'projects.list', 'projects.get', 'tasks.list', 'tasks.get', 'time_entries.list', 'invoices.list', 'invoices.get', 'invoices.pdf', 'proposals.list', 'proposals.get', 'proposals.accept'] as $name) {
            $this->assertArrayNotHasKey($name, $withheld->all(), $name);
        }
    }

    public function test_invoice_and_schedule_writes_report_the_missing_public_revision_read_scope(): void
    {
        $this->enableAll();
        $withheld = collect(app(AgentWithheldTools::class)->for(fn (string $scope): bool => $scope !== 'billing:read', true))->keyBy('name');
        foreach (['invoices.hold_delivery', 'invoices.release_delivery', 'invoices.add_time', 'invoices.generate_period', 'billing_schedules.create', 'billing_schedules.generate'] as $name) {
            $this->assertSame(['name' => $name, 'reason' => 'scope_not_granted', 'scope' => 'billing:read'], $withheld[$name]);
        }
    }

    public function test_global_mcp_disable_reports_every_tool_before_scope_or_role(): void
    {
        $this->enableAll();
        config(['agent_api.mcp_enabled' => false]);
        $this->assertSame(array_map(fn (string $name): array => ['name' => $name, 'reason' => 'deployment_disabled'], self::TOOLS),
            app(AgentWithheldTools::class)->for(fn (string $scope): bool => false, false));
    }

    private function enableAll(): void
    {
        $configuration = ['agent_api.writes_enabled' => true, 'agent_api.mcp_enabled' => true, 'agent_api.mcp_feature_flags' => []];
        foreach (array_keys(self::gatedTools()) as $name) {
            $configuration['agent_api.'.$name] = true;
        }
        config($configuration);
    }

    /**
     * Every MCP tool the registry declares, whatever is switched off.
     *
     * @return list<string>
     */
    private function inventory(): array
    {
        $names = array_map(static fn (Operation $operation): string => (string) $operation->mcpName(), $this->mcpTools());
        $this->assertSame($names, array_values(array_unique($names)));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function deployed(): array
    {
        $names = $this->deployedToolNames();
        sort($names);

        return $names;
    }
}
