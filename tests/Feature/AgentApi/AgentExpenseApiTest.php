<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Queries\Expenses\WorkspaceExpenseSchedules;
use App\Support\AgentApi\AgentApiResponseSchemaCatalog;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\BillingCadence;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InspectsAgentOperations;
use Tests\TestCase;

final class AgentExpenseApiTest extends TestCase
{
    use InspectsAgentOperations;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);
    }

    public function test_manager_records_idempotently_updates_with_version_and_retries_deletion_without_loading_deleted_row(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->agent($owner);
        $url = $this->url($workspace);
        $payload = ['entries' => [$this->facts() + ['company_id' => $company->public_id, 'project_id' => $project->public_id]]];
        $created = $this->withHeader('Idempotency-Key', 'expense-create')->postJson($url, $payload)->assertCreated();
        $id = $created->json('data.0.id');
        $version = $created->json('data.0.version');
        $created->assertJsonPath('data.0.status_label', 'Draft')->assertJsonPath('data.0.can_edit', true);
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseCount('client_expenses', 1);
        $changed = $payload;
        $changed['entries'][0]['amount'] = 501;
        $this->postJson($url, $changed)->assertConflict();
        $update = $this->facts() + ['expected_version' => $version];
        $update['description'] = 'Synthetic corrected expense';
        $updated = $this->withHeader('Idempotency-Key', 'expense-update')->patchJson($url.'/'.$id, $update)->assertOk();
        $updated->assertJsonPath('data.project_id', $project->public_id)->assertJsonPath('data.description', $update['description']);
        $this->assertNotSame($version, $updated->json('data.version'));
        $this->withHeader('Idempotency-Key', 'expense-stale')->patchJson($url.'/'.$id, $update)->assertConflict();
        $updated = $this->withHeader('Idempotency-Key', 'expense-clear-project')->patchJson($url.'/'.$id, $this->facts() + ['project_id' => null, 'expected_version' => $updated->json('data.version')])->assertOk()->assertJsonPath('data.project_id', null);
        $deletion = ['expected_version' => $updated->json('data.version')];
        $this->withHeader('Idempotency-Key', 'expense-delete')->deleteJson($url.'/'.$id, $deletion)->assertOk()->assertJsonPath('data.deleted_id', $id);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $this->deleteJson($url.'/'.$id, $deletion)->assertOk();
        $this->assertSame([], array_values(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'client_expenses'))));
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->deleteJson($url.'/'.$id, $deletion)->assertForbidden();
    }

    public function test_batch_is_atomic_and_tenant_references_never_cross_workspace_or_company(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        [$foreign, $foreignCompany, $foreignProject] = $this->fixture();
        $this->agent($owner);
        $valid = $this->facts() + ['company_id' => $company->public_id];
        $this->withHeader('Idempotency-Key', 'expense-foreign-batch')->postJson($this->url($workspace), ['entries' => [$valid, $this->facts() + ['company_id' => $foreignCompany->public_id]]])->assertNotFound();
        $this->assertDatabaseCount('client_expenses', 0);
        $this->withHeader('Idempotency-Key', 'expense-foreign-project')->postJson($this->url($workspace), ['entries' => [$valid + ['project_id' => $foreignProject->public_id]]])->assertNotFound();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Other', 'slug' => 'synthetic-'.Str::uuid()]);
        $this->withHeader('Idempotency-Key', 'expense-wrong-company')->postJson($this->url($workspace), ['entries' => [$this->facts() + ['company_id' => $otherCompany->public_id, 'project_id' => $project->public_id]]])->assertNotFound();
        $record = $this->record($foreign, $foreignCompany, $foreignProject);
        $this->withHeader('Idempotency-Key', 'expense-foreign-update')->patchJson($this->url($workspace).'/'.$record->public_id, $this->facts() + ['expected_version' => AgentApiVersion::for($record)])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'expense-foreign-delete')->deleteJson($this->url($workspace).'/'.$record->public_id, ['expected_version' => AgentApiVersion::for($record)])->assertNotFound();
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_finance_reference_is_creation_only_provenance_bound_to_the_existing_retry_key(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        $reference = '00000000-0000-4000-8000-000000000001';
        $entry = $this->facts() + ['company_id' => $company->public_id, 'external_finance_transaction_uuid' => $reference];
        $payload = ['entries' => [$entry]];
        $url = $this->url($workspace);
        $created = $this->withHeader('Idempotency-Key', 'finance-expense-create')->postJson($url, $payload)->assertCreated();
        $id = $created->json('data.0.id');
        $record = ClientExpense::query()->where('public_id', $id)->sole();
        $this->assertSame($reference, $record->external_finance_transaction_uuid);
        $this->assertArrayNotHasKey('external_finance_transaction_uuid', $record->toArray());
        $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.0.id', $id);
        $this->assertDatabaseCount('client_expenses', 1);
        $changed = $entry;
        $changed['external_finance_transaction_uuid'] = '00000000-0000-4000-8000-000000000002';
        $this->postJson($url, ['entries' => [$changed]])->assertConflict();
        $this->withHeader('Idempotency-Key', 'finance-expense-distinct-request')->postJson($url, $payload)->assertCreated();
        $this->assertSame(2, ClientExpense::query()->count(), 'The reference is not a second idempotency namespace.');

        $update = $this->facts() + ['expected_version' => $created->json('data.0.version')];
        $this->withHeader('Idempotency-Key', 'finance-expense-reference-edit')->patchJson($url.'/'.$id, $update + ['external_finance_transaction_uuid' => $reference])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'finance-expense-facts-edit')->patchJson($url.'/'.$id, $update)->assertOk();
        $this->assertSame($reference, $record->refresh()->external_finance_transaction_uuid);
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'member']);
        $this->withHeader('Idempotency-Key', 'finance-expense-create')->postJson($url, $payload)->assertForbidden();
    }

    public function test_recurring_occurrences_do_not_inherit_an_existing_expenses_finance_reference(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-07 12:00:00 UTC'));
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        $reference = '00000000-0000-4000-8000-000000000004';
        $this->withHeader('Idempotency-Key', 'finance-expense-original')->postJson($this->url($workspace), [
            'entries' => [$this->facts() + ['company_id' => $company->public_id, 'external_finance_transaction_uuid' => $reference]],
        ])->assertCreated();
        $original = ClientExpense::query()->sole();
        $schedules = new WorkspaceExpenseSchedules($workspace);
        $schedule = $schedules->create($company, null, new NewExpense($original->spent_on, $original->amount, $original->currency, $original->description), BillingCadence::Monthly);
        $this->assertSame(1, $schedules->generate($schedule->public_id, $owner));
        $occurrence = ClientExpense::query()->where('client_expense_schedule_id', $schedule->id)->sole();
        $this->assertNull($occurrence->external_finance_transaction_uuid);
        $this->assertSame($reference, $original->refresh()->external_finance_transaction_uuid);
        $this->assertSame(0, $schedules->generate($schedule->public_id, $owner));
        $this->assertDatabaseCount('client_expenses', 2);
    }

    public function test_invalid_finance_reference_refuses_the_whole_batch_and_old_requests_stay_valid(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->agent($owner);
        $entry = $this->facts() + ['company_id' => $company->public_id];
        $this->withHeader('Idempotency-Key', 'finance-expense-invalid')->postJson($this->url($workspace), [
            'entries' => [$entry, $entry + ['external_finance_transaction_uuid' => 'not-a-uuid']],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('client_expenses', 0);
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
        $this->withHeader('Idempotency-Key', 'finance-expense-legacy')->postJson($this->url($workspace), ['entries' => [$entry]])->assertCreated();
        $this->assertNull(ClientExpense::query()->sole()->external_finance_transaction_uuid);
    }

    public static function financeReferenceReaders(): iterable
    {
        yield 'owner' => ['owner'];
        yield 'administrator' => ['admin'];
    }

    #[DataProvider('financeReferenceReaders')]
    public function test_only_workspace_managers_read_finance_provenance_even_when_writes_are_disabled(string $role): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => $role]);
        $this->agent($owner);
        $reference = '00000000-0000-4000-8000-000000000003';
        $this->withHeader('Idempotency-Key', 'finance-expense-private')->postJson($this->url($workspace), [
            'entries' => [$this->facts() + ['company_id' => $company->public_id, 'project_id' => $project->public_id, 'external_finance_transaction_uuid' => $reference]],
        ])->assertCreated()->assertJsonPath('data.0.external_finance_transaction_uuid', $reference);
        $this->record($workspace, $company, $project);

        $this->agent($owner, ['expenses:read']);
        config(['agent_api.writes_enabled' => false, 'agent_api.expense_writes_enabled' => false]);
        $page = $this->getJson($this->url($workspace))->assertOk();
        $page->assertJsonPath('data.0.external_finance_transaction_uuid', $reference)->assertJsonPath('data.0.can_edit', false);
        $this->assertArrayHasKey('external_finance_transaction_uuid', $page->json('data.1'));
        $page->assertJsonPath('data.1.external_finance_transaction_uuid', null);

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => 'contributor']);
        $this->agent($member, ['expenses:read']);
        $memberPage = $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(2, 'data');
        foreach ($memberPage->json('data') as $expense) {
            $this->assertArrayHasKey('external_finance_transaction_uuid', $expense);
            $this->assertNull($expense['external_finance_transaction_uuid']);
        }
        $this->assertStringNotContainsString($reference, $memberPage->getContent());
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($memberPage->json(), AgentApiResponseSchemaCatalog::forOperation('expenses.list')));
    }

    public function test_expense_response_schema_requires_the_nullable_finance_reference_key(): void
    {
        [$workspace, $company, , $owner] = $this->fixture();
        $this->record($workspace, $company);
        $this->agent($owner, ['expenses:read']);
        $page = $this->getJson($this->url($workspace))->assertOk()->json();
        $schema = AgentApiResponseSchemaCatalog::forOperation('expenses.list');
        $validator = new SchemaValidator;
        $this->assertArrayHasKey('external_finance_transaction_uuid', $page['data'][0]);
        $this->assertNull($page['data'][0]['external_finance_transaction_uuid']);
        $this->assertSame([], $validator->validateAgainstJsonSchema($page, $schema));
        unset($page['data'][0]['external_finance_transaction_uuid']);
        $this->assertNotSame([], $validator->validateAgainstJsonSchema($page, $schema));
    }

    public function test_browser_mutations_advance_revision_and_both_deletions_require_drafts(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $record = $this->record($workspace, $company, $project);
        $version = AgentApiVersion::for($record);
        $expenses = new WorkspaceExpenses($workspace);
        $approved = $expenses->approve($record, $owner);
        $draft = $expenses->unapprove($approved);
        $this->assertNotSame($version, AgentApiVersion::for($draft));
        $this->agent($owner);
        $this->withHeader('Idempotency-Key', 'expense-browser-stale')->patchJson($this->url($workspace).'/'.$record->public_id, $this->facts() + ['expected_version' => $version])->assertConflict();
        $approved = $expenses->approve($draft, $owner);
        $this->withHeader('Idempotency-Key', 'expense-approved-delete')->deleteJson($this->url($workspace).'/'.$record->public_id, ['expected_version' => AgentApiVersion::for($approved)])->assertConflict();
        $expenses->discard($expenses->unapprove($approved));
        $this->assertSoftDeleted('client_expenses', ['id' => $record->id]);
    }

    public static function frozenStatuses(): iterable
    {
        yield ['approved'];
        yield ['invoiced'];
        yield ['legacy_unknown'];
    }

    #[DataProvider('frozenStatuses')]
    public function test_non_draft_status_is_readable_but_not_editable_or_deletable(string $status): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $record = $this->record($workspace, $company, $project);
        $record->forceFill(['status' => $status])->save();
        $this->agent($owner);
        $this->getJson($this->url($workspace))->assertOk()->assertJsonPath('data.0.can_edit', false)->assertJsonPath('data.0.can_delete', false)->assertJsonPath('data.0.status_label', $status === 'legacy_unknown' ? 'Unknown' : ucfirst($status));
        $this->withHeader('Idempotency-Key', 'frozen-update')->patchJson($this->url($workspace).'/'.$record->public_id, $this->facts() + ['expected_version' => AgentApiVersion::for($record)])->assertConflict();
        $this->withHeader('Idempotency-Key', 'frozen-delete')->deleteJson($this->url($workspace).'/'.$record->public_id, ['expected_version' => AgentApiVersion::for($record)])->assertConflict();
    }

    public function test_member_reads_only_assigned_project_expenses_and_never_writes(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => 'contributor']);
        $visible = $this->record($workspace, $company, $project);
        $this->record($workspace, $company);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Other Project']);
        $this->record($workspace, $company, $otherProject);
        $this->agent($member);
        $this->getJson($this->url($workspace))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $visible->public_id)->assertJsonPath('data.0.can_edit', false);
        $this->withHeader('Idempotency-Key', 'member-log')->postJson($this->url($workspace), ['entries' => [$this->facts() + ['company_id' => $company->public_id]]])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'member-edit')->patchJson($this->url($workspace).'/'.$visible->public_id, $this->facts() + ['expected_version' => AgentApiVersion::for($visible)])->assertForbidden();
        $this->withHeader('Idempotency-Key', 'member-delete')->deleteJson($this->url($workspace).'/'.$visible->public_id, ['expected_version' => AgentApiVersion::for($visible)])->assertForbidden();
    }

    public function test_cursor_is_bounded_and_bound_to_workspace_and_filters_and_scope_is_required(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $first = $this->record($workspace, $company, $project);
        $second = $this->record($workspace, $company, $project);
        $this->agent($owner, ['expenses:read']);
        $response = $this->getJson($this->url($workspace).'?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->public_id)->assertJsonPath('data.0.can_edit', false);
        $cursor = urlencode($response->json('meta.next_cursor'));
        $this->getJson($this->url($workspace).'?limit=1&cursor='.$cursor)->assertOk()->assertJsonPath('data.0.id', $second->public_id)->assertJsonPath('meta.next_cursor', null);
        $this->getJson($this->url($workspace).'?limit=101')->assertUnprocessable();
        $this->getJson($this->url($workspace).'?limit=1&status=draft&cursor='.$cursor)->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'missing-write-scope')->postJson($this->url($workspace), ['entries' => []])->assertForbidden();
        $this->agent($owner, ['expenses:write']);
        $this->getJson($this->url($workspace))->assertForbidden();
    }

    public static function flags(): iterable
    {
        yield [false, false];
        yield [true, false];
        yield [false, true];
        yield [true, true];
    }

    #[DataProvider('flags')]
    public function test_both_flags_gate_catalog_routes_and_capabilities(bool $outer, bool $inner): void
    {
        config(['agent_api.writes_enabled' => $outer, 'agent_api.expense_writes_enabled' => $inner]);
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->agent($owner, ['identity:read', 'expenses:read', 'expenses:write']);
        $names = $this->deployedToolNames();
        $this->assertContains('expenses.list', $names);
        foreach (['expenses.log', 'expenses.update', 'expenses.delete', 'expenses.approve', 'expenses.unapprove', 'expenses.receipts.upload_url', 'expense_schedules.create', 'expense_schedules.update', 'expense_schedules.generate'] as $name) {
            $this->assertSame($outer && $inner, in_array($name, $names, true));
        }
        $summary = $this->getJson('/api/v1/context')->assertOk();
        $this->assertSame($outer && $inner, in_array('expenses:write', $summary->json('data.workspaces.0.capabilities'), true));
        $response = $this->withHeader('Idempotency-Key', 'flag-create')->postJson($this->url($workspace), ['entries' => [$this->facts() + ['company_id' => $company->public_id]]]);
        $response->assertStatus($outer && $inner ? 201 : 404);
        if (! ($outer && $inner)) {
            $record = $this->record($workspace, $company, $project);
            $this->patchJson($this->url($workspace).'/'.$record->public_id, $this->facts() + ['expected_version' => AgentApiVersion::for($record)])->assertNotFound();
            $this->deleteJson($this->url($workspace).'/'.$record->public_id, ['expected_version' => AgentApiVersion::for($record)])->assertNotFound();
        }
    }

    public function test_entire_expense_http_flow_scopes_every_tenant_read_and_write(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->agent($owner);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $response = $this->withHeader('Idempotency-Key', 'scoped-create')->postJson($this->url($workspace), ['entries' => [$this->facts() + ['company_id' => $company->public_id, 'project_id' => $project->public_id]]])->assertCreated();
        $id = $response->json('data.0.id');
        $updated = $this->withHeader('Idempotency-Key', 'scoped-update')->patchJson($this->url($workspace).'/'.$id, [...$this->facts(), 'description' => 'Synthetic changed facts', 'expected_version' => $response->json('data.0.version')])->assertOk();
        $this->getJson($this->url($workspace))->assertOk();
        $this->withHeader('Idempotency-Key', 'scoped-delete')->deleteJson($this->url($workspace).'/'.$id, ['expected_version' => $updated->json('data.version')])->assertOk();
        $touched = [];
        foreach ($queries as $query) {
            $sql = str_replace(['`', '"', '[', ']'], '', strtolower($query));
            preg_match_all('/\b(?:from|into|update|join)\s+([a-z0-9_]+)/i', $sql, $matches);
            foreach (array_unique($matches[1]) as $table) {
                // People and workspaces are global roots, not tenant-owned rows.
                if (in_array($table, ['users', 'workspaces'], true)) {
                    continue;
                }
                $touched[$table] = true;
                $predicate = str_starts_with($sql, 'insert') ? $sql : strstr($sql, ' where ');
                $this->assertIsString($predicate, $sql);
                $this->assertStringContainsString('workspace_id', $predicate, $sql);
            }
        }
        foreach (['client_expenses', 'client_companies', 'client_projects', 'agent_mutation_receipts', 'agent_mutation_audits'] as $table) {
            $this->assertArrayHasKey($table, $touched);
        }
    }

    public function test_admin_can_record_but_invalid_dates_unknown_fields_and_oversized_batches_are_rejected(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $workspace->memberships()->where('user_id', $owner->id)->update(['role' => 'admin']);
        $this->agent($owner);
        $facts = $this->facts() + ['company_id' => $company->public_id];
        $this->withHeader('Idempotency-Key', 'admin-create')->postJson($this->url($workspace), ['entries' => [$facts]])->assertCreated();
        foreach (['09/07/2026', '2026-02-30', '2026-9-7', '2026-09-07T12:00:00Z'] as $index => $date) {
            $invalid = $facts;
            $invalid['spent_on'] = $date;
            $this->withHeader('Idempotency-Key', 'invalid-date-'.$index)->postJson($this->url($workspace), ['entries' => [$invalid]])->assertUnprocessable();
        }
        $this->withHeader('Idempotency-Key', 'too-many')->postJson($this->url($workspace), ['entries' => array_fill(0, 21, $facts)])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'status-injection')->postJson($this->url($workspace), ['entries' => [$facts + ['status' => 'approved']]])->assertUnprocessable();
        $this->assertDatabaseCount('client_expenses', 1);
    }

    public function test_mcp_records_lists_and_replays_the_same_rest_contract(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'expenses:read', 'expenses:write']);
        $session = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'Synthetic expense test', 'version' => '1']]])->assertOk()->headers->get('Mcp-Session-Id');
        $headers = ['Mcp-Protocol-Version' => '2025-06-18', 'Mcp-Session-Id' => $session];
        $arguments = ['workspace_id' => $workspace->public_id, 'entries' => [$this->facts() + ['company_id' => $company->public_id]], 'idempotency_key' => 'mcp-expense-record'];
        $message = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'expenses.log', 'arguments' => $arguments]];
        $created = $this->postJson('/api/v1/mcp', $message, $headers)->assertOk()->assertJsonPath('result.structuredContent.data.0.status_label', 'Draft');
        $id = $created->json('result.structuredContent.data.0.id');
        $this->postJson('/api/v1/mcp', $message, $headers)->assertOk()->assertJsonPath('result.structuredContent.data.0.id', $id);
        $list = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'expenses.list', 'arguments' => ['workspace_id' => $workspace->public_id]]], $headers)->assertOk()->json('result.structuredContent');
        $this->assertSame($this->getJson($this->url($workspace))->assertOk()->json(), $list);
        $this->assertSame([], (new SchemaValidator)->validateAgainstJsonSchema($list, AgentApiResponseSchemaCatalog::forOperation('expenses.list')));
        $updated = $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'expenses.update', 'arguments' => $this->facts() + ['workspace_id' => $workspace->public_id, 'expense_id' => $id, 'expected_version' => $created->json('result.structuredContent.data.0.version'), 'idempotency_key' => 'mcp-expense-update', 'project_id' => $project->public_id]]], $headers)->assertOk()->assertJsonPath('result.structuredContent.data.project_id', $project->public_id);
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'expenses.delete', 'arguments' => ['workspace_id' => $workspace->public_id, 'expense_id' => $id, 'expected_version' => $updated->json('result.structuredContent.data.version'), 'idempotency_key' => 'mcp-expense-delete']]], $headers)->assertOk()->assertJsonPath('result.structuredContent.data.deleted_id', $id);
        config(['agent_api.writes_enabled' => false]);
        $this->postJson('/api/v1/mcp', $message, $headers)->assertOk()->assertJsonPath('error.code', -32601);
        $this->assertDatabaseCount('client_expenses', 1);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Expense Workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Expense Client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic Expense Project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    /** @param list<string> $scopes */
    private function agent(User $user, array $scopes = ['expenses:read', 'expenses:write']): void
    {
        $this->actingAsMcp($user, $scopes);
    }

    private function url(Workspace $workspace): string
    {
        return '/api/v1/workspaces/'.$workspace->public_id.'/expenses';
    }

    /** @return array{spent_on:string,amount:int,currency:string,description:string} */
    private function facts(): array
    {
        return ['spent_on' => '2026-09-07', 'amount' => 500, 'currency' => 'USD', 'description' => 'Synthetic expense'];
    }

    private function record(Workspace $workspace, ClientCompany $company, ?ClientProject $project = null): ClientExpense
    {
        return (new WorkspaceExpenses($workspace))->record($company, $project, new NewExpense(CarbonImmutable::parse('2026-09-07'), 500, 'USD', 'Synthetic expense'));
    }
}
