<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class AgentTimeParityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_unapprove_reuses_the_web_workflow_with_versions_replay_and_audit(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $path = $this->unapprovePath($workspace, $entry);
        $body = ['expected_version' => $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/time-entries')->assertOk()->json('data.0.version')];
        $this->postJson($path, $body)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'missing-version')->postJson($path, [])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'stale-version')->postJson($path, ['expected_version' => str_repeat('0', 64)])->assertConflict();
        $first = $this->withHeader('Idempotency-Key', 'withdraw-approval')->postJson($path, $body)->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.id', $entry->public_id)
            ->assertJsonPath('data.allocation_state', 'unallocated')->assertJsonPath('data.invoice_id', null)->json('data');
        $this->assertNotSame($body['expected_version'], $first['version']);
        $this->withHeader('Idempotency-Key', 'withdraw-approval')->postJson($path, $body)->assertOk()->assertJsonPath('data.version', $first['version']);
        $this->assertDatabaseHas('client_time_entries', ['id' => $entry->id, 'status' => 'draft', 'approved_by_user_id' => null, 'approved_at' => null, 'billing_rate_amount' => null, 'billing_rate_source' => null, 'lock_version' => 2]);
        $this->withHeader('Idempotency-Key', 'withdraw-approval')->postJson($path, ['expected_version' => $first['version']])->assertConflict();
        $this->withHeader('Idempotency-Key', 'withdraw-draft')->postJson($path, ['expected_version' => $first['version']])->assertConflict();
        foreach (['success', 'replay', 'failed'] as $outcome) {
            $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.unapprove', 'outcome' => $outcome]);
        }
        // Replaying a receipt must still enforce the actor's current role.
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->withHeader('Idempotency-Key', 'withdraw-approval')->postJson($path, $body)->assertForbidden();
    }

    public function test_unapprove_requires_scope_workspace_manager_and_tenant_owned_entry_chain(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $body = ['expected_version' => AgentApiVersion::for($entry)];
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_WRITE]);
        $this->withHeader('Idempotency-Key', 'wrong-scope')->postJson($this->unapprovePath($workspace, $entry), $body)->assertForbidden();
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->actingAsMcp($manager, [AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $this->withHeader('Idempotency-Key', 'project-manager')->postJson($this->unapprovePath($workspace, $entry), $body)->assertForbidden();
        [$otherOwner, $otherWorkspace, $otherCompany, $otherProject] = $this->tenant();
        $foreign = $this->entry($otherOwner, $otherWorkspace, $otherCompany, $otherProject);
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $this->withHeader('Idempotency-Key', 'foreign-entry')->postJson($this->unapprovePath($workspace, $foreign), ['expected_version' => AgentApiVersion::for($foreign)])->assertNotFound();
        $malformed = $this->writingLegacyCrossTenantRows(fn () => $this->entry($owner, $workspace, $company, $otherProject));
        $this->withHeader('Idempotency-Key', 'foreign-project')->postJson($this->unapprovePath($workspace, $malformed), ['expected_version' => AgentApiVersion::for($malformed)])->assertNotFound();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame('approved', $foreign->fresh()->status);
    }

    public function test_unapprove_workspace_refusals_match_with_missing_scopes_and_disabled_writes(): void
    {
        config(['app.debug' => false]);
        [$owner] = $this->tenant();
        [$foreignOwner, $foreign, $company, $project] = $this->tenant();
        $entry = $this->entry($foreignOwner, $foreign, $company, $project);
        $missing = str()->uuid()->toString();
        $body = ['expected_version' => AgentApiVersion::for($entry)];
        foreach ([[['time:approve', 'time:read'], true], [[], true], [['time:approve', 'time:read'], false]] as [$scopes, $writes]) {
            $this->actingAsMcp($owner, $scopes);
            config(['agent_api.writes_enabled' => $writes, 'agent_api.time_entry_writes_enabled' => $writes]);
            $known = $this->postJson($this->unapprovePath($foreign, $entry), $body)->assertNotFound();
            $unknown = $this->postJson('/api/v1/workspaces/'.$missing.'/time-entries/'.$entry->public_id.'/unapprove', $body)->assertNotFound();
            $this->assertSame($known->json(), $unknown->json());
            $this->assertSame($known->headers->get('Cache-Control'), $unknown->headers->get('Cache-Control'));
            $this->assertStringContainsString('no-store', $unknown->headers->get('Cache-Control'));
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public function test_unapprove_requires_readable_revision(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $this->actingAsMcp($owner, ['mcp:use', 'time:approve']);
        $this->postJson($this->unapprovePath($workspace, $entry), ['expected_version' => AgentApiVersion::for($entry)], ['Idempotency-Key' => 'synthetic-unreadable'])->assertForbidden();
        $this->assertNotContains('time_entries.unapprove', array_column($this->mcpCall('tools/list', [])['tools'], 'name'));
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    public static function invoiceStatuses(): iterable
    {
        yield 'issued' => ['issued'];
        yield 'paid' => ['paid'];
        yield 'partially paid' => ['partially_paid'];
        yield 'void' => ['void'];
        yield 'unknown' => ['future_status'];
    }

    #[DataProvider('invoiceStatuses')]
    public function test_unapprove_refuses_every_non_draft_allocation(string $status): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $invoice = $this->allocate($workspace, $company, $entry, $status);
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $this->withHeader('Idempotency-Key', 'frozen-time')->postJson($this->unapprovePath($workspace, $entry), ['expected_version' => AgentApiVersion::for($entry)])->assertConflict();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertTrue($invoice->lines()->firstOrFail()->timeEntries()->whereKey($entry->id)->exists());
    }

    public function test_unapprove_releases_draft_time_and_rebuilds_the_invoice_atomically(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project, ['billing_rate_source' => 'explicit']);
        $invoice = $this->allocate($workspace, $company, $entry, 'draft');
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $this->withHeader('Idempotency-Key', 'release-draft')->postJson($this->unapprovePath($workspace, $entry), ['expected_version' => AgentApiVersion::for($entry)])->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.allocation_state', 'unallocated');
        $this->assertSame(10000, $entry->fresh()->billing_rate_amount);
        $this->assertSame('explicit', $entry->fresh()->billing_rate_source);
        $this->assertSame(0, $invoice->fresh()->total_amount);
        $this->assertSame(0, $entry->invoiceLines()->count());
    }

    public static function cutovers(): iterable
    {
        yield 'both off' => [false, false, false];
        yield 'global off' => [false, true, false];
        yield 'time off' => [true, false, false];
        yield 'both on' => [true, true, true];
    }

    #[DataProvider('cutovers')]
    public function test_unapprove_rest_and_mcp_follow_both_cutovers(bool $writes, bool $timeWrites, bool $enabled): void
    {
        config(['agent_api.writes_enabled' => $writes, 'agent_api.time_entry_writes_enabled' => $timeWrites]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_APPROVE, AgentApiScopes::TIME_READ]);
        $tools = $this->mcpCall('tools/list', [])['tools'];
        $this->assertSame($enabled, in_array('time_entries.unapprove', array_column($tools, 'name'), true));
        $response = $this->withHeader('Idempotency-Key', 'cutover')->postJson($this->unapprovePath($workspace, $entry), ['expected_version' => AgentApiVersion::for($entry)]);
        $enabled ? $response->assertOk() : $response->assertNotFound();
    }

    public function test_mcp_unapprove_and_allocation_listing_use_the_rest_contract(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.time_entry_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project);
        $this->actingAsMcp($owner, [AgentApiScopes::MCP_USE, AgentApiScopes::TIME_READ, AgentApiScopes::TIME_APPROVE]);
        $args = ['workspace_id' => $workspace->public_id, 'entry_id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry), 'idempotency_key' => 'mcp-unapprove'];
        $result = $this->mcpCall('tools/call', ['name' => 'time_entries.unapprove', 'arguments' => $args]);
        $this->assertFalse($result['isError']);
        $this->assertSame('draft', $result['structuredContent']['data']['status']);
        $replay = $this->mcpCall('tools/call', ['name' => 'time_entries.unapprove', 'arguments' => $args]);
        $this->assertFalse($replay['isError']);
        $this->assertSame($result['structuredContent'], $replay['structuredContent']);
        $refused = $this->mcpCall('tools/call', ['name' => 'time_entries.unapprove', 'arguments' => [...$args, 'idempotency_key' => 'mcp-unapprove-draft', 'expected_version' => $result['structuredContent']['data']['version']]]);
        $this->assertTrue($refused['isError']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.unapprove', 'outcome' => 'replay']);
        $ready = $this->entry($owner, $workspace, $company, $project, ['minutes' => 73]);
        $listed = $this->mcpCall('tools/call', ['name' => 'time_entries.list', 'arguments' => ['workspace_id' => $workspace->public_id, 'unallocated' => true, 'is_billable' => true]]);
        $this->assertFalse($listed['isError']);
        $this->assertSame([$ready->public_id], array_column($listed['structuredContent']['data'], 'id'));
        $this->assertSame('unallocated', $listed['structuredContent']['data'][0]['allocation_state']);
    }

    public function test_allocation_metadata_filters_and_summary_agree_on_invoice_ready_time(): void
    {
        [$owner, $workspace, $company, $project] = $this->tenant();
        $ready = $this->entry($owner, $workspace, $company, $project, ['minutes' => 41]);
        $flat = $this->entry($owner, $workspace, $company, $project, ['minutes' => 14, 'billing_rate_amount' => null, 'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD']);
        $this->entry($owner, $workspace, $company, $project, ['subcontractor_billing_mode' => 'direct']);
        $draft = $this->entry($owner, $workspace, $company, $project, ['status' => 'draft']);
        $this->entry($owner, $workspace, $company, $project, ['billing_rate_amount' => null]);
        $this->entry($owner, $workspace, $company, $project, ['is_deferred' => true]);
        $this->entry($owner, $workspace, $company, $project, ['is_billable' => false]);
        $reserved = $this->entry($owner, $workspace, $company, $project, ['minutes' => 17]);
        $draftInvoice = $this->allocate($workspace, $company, $reserved, 'draft');
        $consumed = $this->entry($owner, $workspace, $company, $project, ['minutes' => 19]);
        $paidInvoice = $this->allocate($workspace, $company, $consumed, 'paid');
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_READ, AgentApiScopes::IDENTITY_READ]);
        $base = "/api/v1/workspaces/{$workspace->public_id}";
        $list = $this->getJson($base.'/time-entries')->assertOk()->json('data');
        $rows = collect($list)->keyBy('id');
        $this->assertSame(['unallocated', null], [$rows[$draft->public_id]['allocation_state'], $rows[$draft->public_id]['invoice_id']]);
        $this->assertSame(['reserved', $draftInvoice->public_id], [$rows[$reserved->public_id]['allocation_state'], $rows[$reserved->public_id]['invoice_id']]);
        $this->assertSame(['consumed', $paidInvoice->public_id], [$rows[$consumed->public_id]['allocation_state'], $rows[$consumed->public_id]['invoice_id']]);
        foreach (['reserved' => $reserved, 'consumed' => $consumed] as $state => $allocated) {
            $this->getJson($base.'/time-entries?allocation_state='.$state)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allocated->public_id);
        }
        $invoiceReady = $this->getJson($base.'/time-entries?unallocated=true&is_billable=true')->assertOk()->json('data');
        $this->assertSame([$ready->public_id, $flat->public_id], array_column($invoiceReady, 'id'));
        $summary = $this->getJson($base.'/summary')->assertOk();
        $this->assertSame(array_sum(array_column($invoiceReady, 'minutes')), $summary->json('data.time.approved_billable_unallocated_minutes'));
        $this->assertSame(17, $summary->json('data.time.allocated_to_draft_minutes'));
        $this->getJson($base.'/time-entries?allocation_state=invalid')->assertUnprocessable();
        $this->getJson($base.'/time-entries?is_billable=invalid')->assertUnprocessable();
        $this->getJson($base.'/time-entries?is_billable=false')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_billable', false);
        $page = $this->getJson($base.'/time-entries?allocation_state=unallocated&limit=1')->assertOk();
        $cursor = $page->json('meta.next_cursor');
        $this->assertIsString($cursor);
        $this->getJson($base.'/time-entries?allocation_state=consumed&limit=1&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($base.'/time-entries?allocation_state=unallocated&unallocated=1&limit=1&cursor='.urlencode($cursor))->assertUnprocessable();
    }

    public static function foreignAllocationParts(): iterable
    {
        yield 'line' => ['line'];
        yield 'pivot' => ['pivot'];
        yield 'invoice' => ['invoice'];
        yield 'company' => ['company'];
    }

    #[DataProvider('foreignAllocationParts')]
    public function test_hidden_allocations_do_not_leak_ids_or_change_summary_and_filters(string $part): void
    {
        [$owner, $workspace, $company, $project] = $this->tenant();
        [$foreignOwner, $foreignWorkspace, $foreignCompany, $foreignProject] = $this->tenant();
        $entry = $this->entry($owner, $workspace, $company, $project, ['minutes' => 29]);
        $this->entry($foreignOwner, $foreignWorkspace, $foreignCompany, $foreignProject);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $part === 'invoice' ? $foreignWorkspace->id : $workspace->id, 'client_company_id' => $part === 'invoice' ? $foreignCompany->id : $company->id, 'status' => 'draft', 'currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid(), 'invoice_kind' => 'ad_hoc']);
        // The company-only case remains in this workspace to test that the
        // allocation cannot cross client companies within one tenant either.
        if ($part === 'company') {
            $localOther = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other synthetic client', 'slug' => 'other-synthetic']);
            $this->writingLegacyCrossTenantRows(fn () => $invoice->forceFill(['client_company_id' => $localOther->id])->save());
        }
        $this->writingLegacyCrossTenantRows(function () use ($part, $workspace, $foreignWorkspace, $invoice, $entry): void {
            $line = ClientInvoiceLine::query()->create(['workspace_id' => $part === 'line' ? $foreignWorkspace->id : $workspace->id, 'client_invoice_id' => $invoice->id, 'type' => 'hourly', 'description' => 'Synthetic allocation', 'quantity' => 1, 'unit_amount' => 10000, 'total_amount' => 10000]);
            $line->timeEntries()->attach($entry->id, ['workspace_id' => $part === 'pivot' ? $foreignWorkspace->id : $workspace->id]);
        });
        $this->actingAsMcp($owner, [AgentApiScopes::TIME_READ, AgentApiScopes::IDENTITY_READ]);
        $base = "/api/v1/workspaces/{$workspace->public_id}";
        $this->getJson($base.'/time-entries?unallocated=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $entry->public_id)->assertJsonPath('data.0.invoice_id', null)->assertJsonPath('data.0.allocation_state', 'unallocated');
        $this->getJson($base.'/time-entries?allocation_state=reserved')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/summary')->assertOk()->assertJsonPath('data.time.approved_billable_unallocated_minutes', 29)->assertJsonPath('data.time.allocated_to_draft_minutes', 0);
    }

    /** @return array{User,Workspace,ClientCompany,ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic time workspace', 'slug' => 'synthetic-time-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic time client', 'slug' => 'synthetic-time-client-'.str()->random(8)]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic time project']);

        return [$owner, $workspace, $company, $project];
    }

    /** @param array<string,mixed> $extra */
    private function entry(User $owner, Workspace $workspace, ClientCompany $company, ClientProject $project, array $extra = []): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($extra + ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'user_id' => $owner->id, 'worked_on' => '2026-10-01', 'minutes' => 60, 'description' => 'Synthetic work', 'is_billable' => true, 'status' => 'approved', 'approved_by_user_id' => $owner->id, 'approved_at' => now(), 'billing_rate_amount' => 10000, 'billing_rate_source' => 'agreement', 'currency' => 'USD']);
    }

    private function allocate(Workspace $workspace, ClientCompany $company, ClientTimeEntry $entry, string $status): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'status' => $status, 'currency' => 'USD', 'invoice_number' => 'SYNTHETIC-'.str()->uuid(), 'invoice_kind' => 'ad_hoc', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'balance_amount' => 10000]);
        $line = $invoice->lines()->create(['workspace_id' => $workspace->id, 'type' => 'hourly', 'description' => 'Synthetic hourly work', 'quantity' => 1, 'unit_amount' => 10000, 'total_amount' => 10000]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $workspace->id]);

        return $invoice;
    }

    private function unapprovePath(Workspace $workspace, ClientTimeEntry $entry): string
    {
        return "/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry->public_id}/unapprove";
    }

    /** @param array<string,mixed> $params
     * @return array<string,mixed> */
    private function mcpCall(string $method, array $params): array
    {
        $initialize = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 'initialize', 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic time parity', 'version' => '1']]], ['Mcp-Protocol-Version' => '2025-06-18'])->assertOk();

        return $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 'time-call', 'method' => $method, 'params' => (object) $params], ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $initialize->headers->get('Mcp-Session-Id')])->assertOk()->json('result');
    }
}
