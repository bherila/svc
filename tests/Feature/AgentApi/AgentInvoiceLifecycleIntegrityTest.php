<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvoiceCounter;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class AgentInvoiceLifecycleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_updating_a_draft_releases_removed_time_for_another_invoice(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $firstTime = $this->approvedTime($owner, $workspace, $company, $project, 'First allocation');
        $replacementTime = $this->approvedTime($owner, $workspace, $company, $project, 'Replacement allocation');
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE]);

        $draft = $this->createDraft($workspace, $company, ['time_entry_ids' => [$firstTime->public_id], 'notes' => 'Clear me'], 'draft-first');
        $updateBody = [
            'expected_version' => $draft['version'],
            'time_entry_ids' => [$replacementTime->public_id],
            'manual_lines' => [],
            'notes' => null,
        ];
        $updated = $this->withHeader('Idempotency-Key', 'draft-update')->patchJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}",
            $updateBody,
        )->assertOk()->assertJsonPath('data.linked_time_state', 'reserved')->json('data');
        $this->withHeader('Idempotency-Key', 'draft-update')->patchJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}",
            $updateBody,
        )->assertOk();

        $this->assertNotSame($draft['version'], $updated['version']);
        $this->assertSame(0, $firstTime->invoiceLines()->count());
        $this->assertSame(1, $replacementTime->invoiceLines()->count());
        $this->assertDatabaseHas('client_invoices', ['public_id' => $draft['id'], 'notes' => null]);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.update_draft', 'outcome' => 'success']);
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.updated')->sole();
        $this->assertSame($owner->id, $activity->actor_user_id);
        $this->assertSame($draft['id'], $activity->subject_public_id);

        $second = $this->createDraft($workspace, $company, ['time_entry_ids' => [$firstTime->public_id]], 'draft-reuse-removed');
        $this->assertNotSame($draft['id'], $second['id']);
        $this->assertSame(1, $firstTime->invoiceLines()->count());
    }

    public function test_discarding_a_draft_releases_time_and_allows_reinvoicing(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->approvedTime($owner, $workspace, $company, $project, 'Discarded allocation');
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE]);
        $draft = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'discard-create');

        $this->withHeader('Idempotency-Key', 'discard-draft')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}/discard",
            ['expected_version' => $draft['version'], 'reason' => 'Draft prepared in error', 'confirm' => true],
        )->assertOk()->assertJsonPath('data.status', 'void')->assertJsonPath('data.linked_time_state', 'released');

        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(0, $entry->invoiceLines()->count());
        $this->assertDatabaseHas('client_invoices', ['public_id' => $draft['id'], 'void_reason' => 'Draft prepared in error']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'invoices.discard_draft', 'outcome' => 'success']);
        $replacement = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'discard-reinvoice');
        $this->assertNotSame($draft['id'], $replacement['id']);
    }

    public function test_voiding_an_unissued_draft_also_releases_time(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->approvedTime($owner, $workspace, $company, $project, 'Draft void allocation');
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $draft = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'draft-void-create');

        $this->withHeader('Idempotency-Key', 'draft-void')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}/void",
            ['expected_version' => $draft['version'], 'reason' => 'Void unissued draft', 'confirm' => true],
        )->assertOk()->assertJsonPath('data.linked_time_state', 'released');

        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(0, $entry->invoiceLines()->count());
        $replacement = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'draft-void-reinvoice');
        $this->assertNotSame($draft['id'], $replacement['id']);
    }

    public function test_voiding_an_unpaid_issued_invoice_restores_and_releases_time(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $entry = $this->approvedTime($owner, $workspace, $company, $project, 'Issued allocation');
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $draft = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'void-create');
        $issued = $this->withHeader('Idempotency-Key', 'void-issue')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}/issue",
            ['expected_version' => $draft['version'], 'confirm' => true],
        )->assertOk()->assertJsonPath('data.linked_time_state', 'consumed')->json('data');
        $this->assertSame('invoiced', $entry->fresh()->status);

        $this->withHeader('Idempotency-Key', 'void-issued')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}/void",
            ['expected_version' => $issued['version'], 'reason' => 'Replace unpaid invoice', 'confirm' => true],
        )->assertOk()->assertJsonPath('data.linked_time_state', 'released');

        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(0, $entry->invoiceLines()->count());
        $replacement = $this->createDraft($workspace, $company, ['time_entry_ids' => [$entry->public_id]], 'void-reinvoice');
        $this->assertNotSame($draft['id'], $replacement['id']);
    }

    public function test_manual_line_cannot_attribute_another_clients_project(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company] = $this->tenant();
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other Client', 'slug' => 'other-client-'.Str::random(6)]);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Other Project']);
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE]);

        $response = $this->withHeader('Idempotency-Key', 'cross-client-line')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices",
            [
                'company_id' => $company->public_id,
                'manual_lines' => [[
                    'project_id' => $otherProject->public_id,
                    'type' => 'service',
                    'description' => 'Wrong client',
                    'quantity' => '1',
                    'unit_amount' => 1000,
                ]],
            ],
        )->assertUnprocessable()->assertJsonPath('message', 'The selected project is not available for this invoice.');

        $this->assertStringNotContainsString($otherProject->public_id, $response->getContent());
        $this->assertDatabaseCount('client_invoices', 0);
    }

    public function test_workspace_counter_survives_legacy_numbers_and_failed_drafts(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SVC-00009', 'status' => 'draft', 'currency' => 'USD']);
        ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'LEGACY-LATEST', 'status' => 'draft', 'currency' => 'USD']);
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE]);

        $this->withHeader('Idempotency-Key', 'number-rollback')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices",
            ['company_id' => $company->public_id, 'time_entry_ids' => [(string) Str::uuid()]],
        )->assertUnprocessable();

        $first = $this->createDraft($workspace, $company, ['manual_lines' => [$this->manualLine($project)]], 'number-first');
        $second = $this->createDraft($workspace, $company, ['manual_lines' => [$this->manualLine($project)]], 'number-second');
        $this->assertSame('SVC-00010', $first['invoice_number']);
        $this->assertSame('SVC-00011', $second['invoice_number']);
        $this->assertSame(12, WorkspaceInvoiceCounter::query()->whereKey($workspace->id)->sole()->next_number);
    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    /**
     * Every tenant-owned write the invoice lifecycle issues names its workspace.
     *
     * From review on #230, which found three of these one at a time. The model
     * hooks this PR adds cannot see a builder write - a relation `update()`,
     * `detach()`, or `Model::query()->...->update()` never touches a model
     * instance - so those have to name the workspace at the call site, and a
     * test that names call sites would only ever find the ones somebody
     * thought of.
     *
     * This asserts the property instead: drive a draft through the rewrite and
     * issue paths, which between them cover the line delete, the pivot detach,
     * the time-entry status update and the revision bump, and refuse any
     * update or delete against a table that has a `workspace_id` and does not
     * mention it. The tables come from the schema, so a write added tomorrow
     * against a tenant-owned table is in this test the day it appears.
     */
    public function test_every_tenant_owned_write_in_the_lifecycle_names_the_workspace(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $time = $this->approvedTime($owner, $workspace, $company, $project, 'Original allocation');
        $replacement = $this->approvedTime($owner, $workspace, $company, $project, 'Replacement allocation');
        // Issuing is a delivery scope, not a write scope.
        $this->actingAsAgent($owner, [AgentApiScopes::BILLING_WRITE, AgentApiScopes::BILLING_DELIVER]);
        $draft = $this->createDraft($workspace, $company, ['time_entry_ids' => [$time->public_id]], 'scoped-writes-create');

        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            // Both quoting styles: MariaDB writes `table`, SQLite writes
            // "table", and a pattern that knew only one would match nothing on
            // the other lane and leave this test asserting over an empty list.
            if (preg_match('/^(?:update|delete\s+from)\s+["`\[]?([a-z_]+)["`\]]?/i', ltrim($query->sql), $matches) === 1) {
                $writes[] = [strtolower($matches[1]), $query->sql];
            }
        });

        $updated = $this->withHeader('Idempotency-Key', 'scoped-writes-update')->patchJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}",
            [
                'expected_version' => $draft['version'],
                'time_entry_ids' => [$replacement->public_id],
                'manual_lines' => [],
            ],
        )->assertOk()->json('data');

        $this->withHeader('Idempotency-Key', 'scoped-writes-issue')->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices/{$draft['id']}/issue",
            ['expected_version' => $updated['version'], 'confirm' => true],
        )->assertOk();

        $unscoped = [];
        $touched = [];

        foreach ($writes as [$table, $sql]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
                continue;
            }

            $touched[$table] = true;

            // The predicate, not the assignment: `set workspace_id = ?` on an
            // insert-like update would satisfy a naive search of the whole
            // statement while scoping nothing.
            $where = strstr(strtolower($sql), ' where ');

            if ($where === false || ! str_contains($where, 'workspace_id')) {
                $unscoped[] = $sql;
            }
        }

        $this->assertSame([], $unscoped, "These tenant-owned writes name no workspace:\n".implode("\n", $unscoped));

        // The flow has to have written to the tables this is about, or the
        // assertion above passed by inspecting nothing.
        //
        // `client_invoice_line_time_entries` is deliberately not among them:
        // `client_invoice_line_id` cascades on delete, so clearing the lines
        // takes the pivot rows with them and issues no statement of its own.
        // A detach that does run is still caught by the loop above, which asks
        // the schema rather than this list.
        foreach (['client_invoices', 'client_invoice_lines', 'client_time_entries'] as $table) {
            $this->assertArrayHasKey($table, $touched, $table.' was never written to, so this test proved nothing about it.');
        }

        // And the work still happened: a predicate naming the wrong workspace
        // would leave every one of these statements matching no row while the
        // requests still returned 200.
        $this->assertSame(0, $time->invoiceLines()->count());
        $this->assertSame(1, $replacement->invoiceLines()->count());
        $this->assertSame('approved', $time->fresh()->status);
        $this->assertSame('invoiced', $replacement->fresh()->status);
    }

    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Invoice Integrity', 'slug' => 'invoice-integrity-'.Str::random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Invoice Client', 'slug' => 'invoice-client-'.Str::random(8)]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Invoice Project']);

        return [$owner, $workspace, $company, $project];
    }

    private function approvedTime(User $user, Workspace $workspace, ClientCompany $company, ClientProject $project, string $description): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => $user->id,
            'worked_on' => '2026-08-23',
            'minutes' => 60,
            'description' => $description,
            'is_billable' => true,
            'is_deferred' => false,
            'billing_rate_amount' => 10000,
            'currency' => 'USD',
            'status' => 'approved',
        ]);
    }

    /** @param array<string, mixed> $extra
     * @return array<string, mixed> */
    private function createDraft(Workspace $workspace, ClientCompany $company, array $extra, string $key): array
    {
        return $this->withHeader('Idempotency-Key', $key)->postJson(
            "/api/v1/workspaces/{$workspace->public_id}/invoices",
            ['company_id' => $company->public_id, ...$extra],
        )->assertCreated()->assertJsonPath('data.linked_time_state', 'reserved')->json('data');
    }

    /** @return array<string, mixed> */
    private function manualLine(ClientProject $project): array
    {
        return [
            'project_id' => $project->public_id,
            'type' => 'service',
            'description' => 'Manual service',
            'quantity' => '1',
            'unit_amount' => 1000,
        ];
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
