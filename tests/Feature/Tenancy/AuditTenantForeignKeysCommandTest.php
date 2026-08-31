<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The audit is the gate that runs before the migration, against real data.
 *
 * Two things therefore have to be true of it, and neither is obvious from
 * reading it: it has to fail when there is something to find, and it has to say
 * nothing about what it found. A gate that passes on a database it could not
 * read, or that prints a client's row into a deploy log, is worse than no gate.
 */
final class AuditTenantForeignKeysCommandTest extends TestCase
{
    use RefreshDatabase;
    use UsesAProbeDatabase;
    use WritesLegacyCrossTenantRows;

    private const PROBE = 'audit_pre_migration_probe';

    public function test_a_consistent_database_passes(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Audit', 'slug' => 'audit']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Audit Client',
            'slug' => 'audit-client',
        ]);
        ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Consistent',
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')
            ->assertExitCode(0);
    }

    public function test_it_fails_closed_on_a_single_cross_tenant_row(): void
    {
        $home = Workspace::query()->create(['name' => 'Home', 'slug' => 'home']);
        $foreign = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Foreign Client',
            'slug' => 'foreign-client',
        ]);

        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Smuggled project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->artisan('svc:schema:audit-tenant-fks')
            ->assertExitCode(1);
    }

    /**
     * The command runs against databases of client and billing records, and its
     * output ends up in deploy logs and issue threads.
     */
    public function test_the_report_prints_counts_and_never_row_contents(): void
    {
        $home = Workspace::query()->create(['name' => 'Home', 'slug' => 'home']);
        $foreign = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Distinctive Client Name',
            'slug' => 'distinctive-client-name',
        ]);
        $publicId = (string) Str::uuid();

        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_projects')->insert([
            'public_id' => $publicId,
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Distinctive Project Name',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $exitCode = Artisan::call('svc:schema:audit-tenant-fks', ['--format' => 'json']);
        $report = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString('Distinctive Client Name', $report);
        $this->assertStringNotContainsString('Distinctive Project Name', $report);
        $this->assertStringNotContainsString($publicId, $report);
        $this->assertStringNotContainsString('distinctive-client-name', $report);

        $decoded = json_decode($report, true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['violating_rows']);
        $this->assertSame(1, $decoded['summary']['violating_references']);
        $this->assertSame(0, $decoded['summary']['pending']);
    }

    /**
     * `client_time_entries.split_from_time_entry_id` names its own table.
     *
     * Correlating an unaliased subquery against the same table binds both sides
     * to the subquery's copy, so every legitimately split entry reads as a
     * violation and the command fails on a database with nothing wrong with it.
     * A gate that cries wolf gets ignored, which is the same as not having one.
     */
    public function test_a_self_referencing_column_does_not_report_a_false_violation(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Splits', 'slug' => 'splits']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Split Client',
            'slug' => 'split-client',
        ]);
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Split Project',
        ]);
        $root = ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-15',
            'minutes' => 120,
            'description' => 'Root',
            'status' => 'approved',
        ]);
        ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-15',
            'minutes' => 60,
            'description' => 'Fragment',
            'status' => 'approved',
            'split_from_time_entry_id' => $root->id,
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(0);
    }

    public function test_an_invalid_format_is_refused_rather_than_guessed(): void
    {
        $this->artisan('svc:schema:audit-tenant-fks --format=yaml')->assertExitCode(2);
    }

    /**
     * A reference the schema cannot answer is not a reference that passed.
     *
     * Run before `2026_08_31_000000`, `client_company_memberships.workspace_id`
     * does not exist and two references report `pending`. Exiting zero there would
     * tell a deployment that a schema nobody finished inspecting is safe to
     * migrate - the same "cannot tell passed from did not run" shape that let
     * `rehearse-generation` call a failed workspace safe.
     */
    public function test_it_fails_when_a_reference_could_not_be_inspected(): void
    {
        $this->withoutMembershipWorkspaceColumn(function (): void {
            $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(1);
        });
    }

    public function test_allow_pending_is_the_deliberate_way_to_inspect_a_pre_migration_schema(): void
    {
        $this->withoutMembershipWorkspaceColumn(function (): void {
            $this->artisan('svc:schema:audit-tenant-fks --allow-pending')->assertExitCode(0);
        });
    }

    public function test_allow_pending_still_fails_on_a_real_violation(): void
    {
        $home = Workspace::query()->create(['name' => 'Home', 'slug' => 'home']);
        $foreign = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Foreign Client',
            'slug' => 'foreign-client',
        ]);

        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Smuggled project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->artisan('svc:schema:audit-tenant-fks --allow-pending')->assertExitCode(1);
    }

    /**
     * A populated parent with no workspace of its own is nobody's row.
     *
     * `stripe_payment_method_states` may legitimately hold an unresolved event -
     * every tenant column null, because the webhook arrived before anything here
     * knew whose it was. It may not name a company while claiming no workspace.
     * Nothing in the schema stops that: these references are exempt from the
     * composite keys, so this audit is their only detector.
     */
    public function test_a_populated_parent_with_no_workspace_is_counted(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Stripe', 'slug' => 'stripe']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Stripe Client',
            'slug' => 'stripe-client',
        ]);

        // The legitimate unresolved state: no tenant columns at all.
        DB::table('stripe_payment_method_states')->insert([
            'provider_id_hash' => hash('sha256', 'unresolved'),
            'state' => 'unknown',
            'provider_created_at' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(0);

        // Named company, no workspace: ownership nothing can decide.
        DB::table('stripe_payment_method_states')->insert([
            'provider_id_hash' => hash('sha256', 'half-written'),
            'workspace_id' => null,
            'client_company_id' => $company->id,
            'state' => 'unknown',
            'provider_created_at' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(1);
    }

    /**
     * A lineage column outliving what it names is the documented behaviour.
     *
     * `client_invoices.client_agreement_id` carries no foreign key precisely so
     * the invoice survives the agreement's deletion. Counting the absent parent
     * would leave the audit permanently red after a deletion the schema allows,
     * and a gate that cannot be cleared is a gate that gets waved through.
     */
    public function test_a_deleted_lineage_parent_is_not_a_violation(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Lineage', 'slug' => 'lineage']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Lineage Client',
            'slug' => 'lineage-client',
        ]);
        $agreement = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Retainer',
            'status' => 'active',
            'currency' => 'USD',
        ]);
        ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_agreement_id' => $agreement->id,
            'invoice_number' => 'SVC-LINEAGE-1',
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(0);

        $agreement->delete();

        $this->assertDatabaseHas('client_invoices', ['client_agreement_id' => $agreement->id]);
        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(0);
    }

    /**
     * The exemption is not a blind spot: an agreement that still exists in
     * another workspace is still counted.
     */
    public function test_a_lineage_parent_in_another_workspace_is_counted(): void
    {
        $home = Workspace::query()->create(['name' => 'Home', 'slug' => 'home']);
        $foreign = Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $homeCompany = ClientCompany::query()->create([
            'workspace_id' => $home->id,
            'name' => 'Home Client',
            'slug' => 'home-client',
        ]);
        $foreignCompany = ClientCompany::query()->create([
            'workspace_id' => $foreign->id,
            'name' => 'Foreign Client',
            'slug' => 'foreign-client',
        ]);
        $foreignAgreement = ClientAgreement::query()->create([
            'workspace_id' => $foreign->id,
            'client_company_id' => $foreignCompany->id,
            'title' => 'Theirs',
            'status' => 'active',
            'currency' => 'USD',
        ]);

        ClientInvoice::query()->create([
            'workspace_id' => $home->id,
            'client_company_id' => $homeCompany->id,
            'client_agreement_id' => $foreignAgreement->id,
            'invoice_number' => 'SVC-BORROWED-1',
            'status' => 'draft',
            'currency' => 'USD',
        ]);

        $this->artisan('svc:schema:audit-tenant-fks')->assertExitCode(1);
    }

    /**
     * Run the assertions against a real schema that has not been migrated yet.
     *
     * A throwaway database rather than the suite's own: reproducing the state by
     * dropping the column back off would be DDL, which is an implicit commit on
     * MariaDB and would escape `RefreshDatabase` into every later test.
     */
    private function withoutMembershipWorkspaceColumn(callable $assertions): void
    {
        $this->bootProbeDatabase(self::PROBE);

        Artisan::call('migrate', ['--database' => self::PROBE, '--force' => true]);
        Artisan::call('migrate:rollback', ['--database' => self::PROBE, '--step' => 3, '--force' => true]);

        $this->assertFalse(
            Schema::connection(self::PROBE)->hasColumn('client_company_memberships', 'workspace_id'),
            'The probe schema was supposed to predate the membership workspace column.',
        );

        $default = DB::getDefaultConnection();
        DB::setDefaultConnection(self::PROBE);

        try {
            $assertions();
        } finally {
            DB::setDefaultConnection($default);
        }
    }
}
