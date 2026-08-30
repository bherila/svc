<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

/**
 * The guarantee is the database's, not the application's.
 *
 * Every cross-tenant defect this repo has fixed was fixed at a call site: a
 * scoped query, an ownership check, an isolation test. This asserts the property
 * one level down - that the write is refused even when no application code is
 * involved at all - so a future call site cannot reintroduce the class.
 *
 * Each case writes through `DB::table()` on purpose. Going through a model would
 * test the model.
 *
 * ## Both engines
 *
 * SQLite enforces composite foreign keys when `PRAGMA foreign_keys` is on, which
 * Laravel's connection sets by default and `assertForeignKeysAreEnforced()`
 * verifies rather than assumes. So these assertions hold on both lanes and this
 * test is never skipped. That is worth stating because it is the exception here:
 * SQLite's usual role in this suite is to miss what MariaDB catches, and a test
 * that quietly passed by not running would be worse than no test.
 */
final class CompositeTenantForeignKeyTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_the_database_refuses_a_project_whose_company_is_in_another_workspace(): void
    {
        $this->assertForeignKeysAreEnforced();

        [$home, $foreign] = $this->twoWorkspaces();
        $foreignCompany = $this->company($foreign, 'foreign');

        $this->expectException(QueryException::class);

        DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Smuggled project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_the_same_row_is_accepted_inside_one_workspace(): void
    {
        [$home] = $this->twoWorkspaces();
        $homeCompany = $this->company($home, 'home');

        DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $homeCompany->id,
            'name' => 'Legitimate project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseCount('client_projects', 1);
    }

    public function test_the_database_refuses_an_invoice_line_billed_to_another_workspaces_invoice(): void
    {
        $this->assertForeignKeysAreEnforced();

        [$home, $foreign] = $this->twoWorkspaces();
        $foreignInvoice = $this->invoice($foreign, $this->company($foreign, 'foreign'));

        $this->expectException(QueryException::class);

        DB::table('client_invoice_lines')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_invoice_id' => $foreignInvoice->id,
            'type' => 'manual',
            'description' => 'Smuggled charge',
            'quantity' => 1,
            'unit_amount' => 5000,
            'tax_amount' => 0,
            'total_amount' => 5000,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The pivot had no foreign key on `client_time_entry_id` at all before #113,
     * so a billed-time row could name any entry in the database. This is the case
     * the composite key was worth adding for.
     */
    public function test_the_database_refuses_billing_another_workspaces_time_entry(): void
    {
        $this->assertForeignKeysAreEnforced();

        [$home, $foreign] = $this->twoWorkspaces();
        $homeCompany = $this->company($home, 'home');
        $homeLine = $this->invoiceLine($home, $this->invoice($home, $homeCompany));
        $foreignCompany = $this->company($foreign, 'foreign');
        $foreignEntry = $this->timeEntry($foreign, $foreignCompany, $this->project($foreign, $foreignCompany));

        $this->expectException(QueryException::class);

        DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $home->id,
            'client_invoice_line_id' => $homeLine->id,
            'client_time_entry_id' => $foreignEntry->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * `client_company_memberships` carried no workspace at all before #113: a
     * membership was reachable only on its company's authority.
     */
    public function test_the_database_refuses_a_portal_membership_pointing_at_another_workspaces_company(): void
    {
        $this->assertForeignKeysAreEnforced();

        [$home, $foreign] = $this->twoWorkspaces();
        $foreignCompany = $this->company($foreign, 'foreign');
        $portalUser = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('client_company_memberships')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'user_id' => $portalUser->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_portal_membership_derives_its_workspace_from_the_company(): void
    {
        [$home] = $this->twoWorkspaces();
        $company = $this->company($home, 'home');
        $portalUser = User::factory()->create();

        $membership = ClientCompanyMembership::query()->create([
            'client_company_id' => $company->id,
            'user_id' => $portalUser->id,
            'role' => 'client',
        ]);

        $this->assertSame($home->id, $membership->workspaceId());
    }

    /**
     * `WritesLegacyCrossTenantRows` is only safe if it puts enforcement back.
     *
     * If it did not, a test that used it once would run every later write of its
     * own without a constraint, and would go on passing while proving less than
     * it claims - the failure mode this repo has already paid for twice.
     */
    public function test_suspending_enforcement_for_a_fixture_restores_it_afterwards(): void
    {
        [$home, $foreign] = $this->twoWorkspaces();
        $foreignCompany = $this->company($foreign, 'foreign');

        $this->writingLegacyCrossTenantRows(fn () => DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Legacy project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertDatabaseCount('client_projects', 1);

        $this->expectException(QueryException::class);

        DB::table('client_projects')->insert([
            'public_id' => (string) Str::uuid(),
            'workspace_id' => $home->id,
            'client_company_id' => $foreignCompany->id,
            'name' => 'Second smuggled project',
            'status' => 'active',
            'is_visible_to_client' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertForeignKeysAreEnforced(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver !== 'sqlite') {
            return;
        }

        $this->assertSame(
            1,
            (int) DB::selectOne('pragma foreign_keys')->foreign_keys,
            'SQLite foreign key enforcement is off, so this test would pass without proving anything.',
        );
    }

    /** @return array{0: Workspace, 1: Workspace} */
    private function twoWorkspaces(): array
    {
        return [
            Workspace::query()->create(['name' => 'Home', 'slug' => 'home']),
            Workspace::query()->create(['name' => 'Foreign', 'slug' => 'foreign']),
        ];
    }

    private function company(Workspace $workspace, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => ucfirst($slug).' Client',
            'slug' => $slug.'-client',
        ]);
    }

    private function project(Workspace $workspace, ClientCompany $company): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => 'Project '.$workspace->slug,
        ]);
    }

    private function invoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'invoice_number' => 'INV-'.$workspace->id,
            'status' => 'draft',
            'currency' => 'USD',
        ]);
    }

    private function invoiceLine(Workspace $workspace, ClientInvoice $invoice): ClientInvoiceLine
    {
        return ClientInvoiceLine::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'type' => 'manual',
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_amount' => 10000,
            'tax_amount' => 0,
            'total_amount' => 10000,
        ]);
    }

    private function timeEntry(Workspace $workspace, ClientCompany $company, ClientProject $project): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'user_id' => User::factory()->create()->id,
            'worked_on' => '2026-08-15',
            'minutes' => 60,
            'description' => 'Synthetic work',
            'is_billable' => true,
            'is_deferred' => false,
            'status' => 'approved',
        ]);
    }
}
