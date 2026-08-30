<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    use WritesLegacyCrossTenantRows;

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

    public function test_an_invalid_format_is_refused_rather_than_guessed(): void
    {
        $this->artisan('svc:schema:audit-tenant-fks --format=yaml')->assertExitCode(2);
    }
}
