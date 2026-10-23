<?php

namespace Tests\Feature\Tasks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TaskOrganizationMigrationTest extends TestCase
{
    use RefreshDatabase;
    use UsesAProbeDatabase;

    public function test_additive_reader_upgrade_and_empty_graph_rollback_preserve_legacy_tasks(): void
    {
        $connection = 'task_organization_migration_probe';
        $this->bootProbeDatabase($connection);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
        $this->rollbackProbeTo($connection, '2026_10_22_180150');
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $this->assertFalse($schema->hasColumn('client_tasks', 'parent_task_id'));
        $workspace = $db->table('workspaces')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic upgrade workspace', 'slug' => 'synthetic-upgrade']);
        $company = $db->table('client_companies')->insertGetId(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'name' => 'Synthetic upgrade company', 'slug' => 'synthetic-upgrade-company']);
        $project = $db->table('client_projects')->insertGetId(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'client_company_id' => $company, 'name' => 'Synthetic upgrade project']);
        $task = $db->table('client_tasks')->insertGetId(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'client_project_id' => $project,
            'title' => 'Synthetic upgrade milestone', 'status' => 'completed', 'completed_at' => '2026-01-31 12:00:00', 'milestone_price_amount' => 4200,
            'starts_on' => '2026-01-01', 'due_on' => '2026-01-31', 'lock_version' => 7]);
        $before = (array) $db->table('client_tasks')->where('workspace_id', $workspace)->where('id', $task)->first();

        $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
        $upgraded = (array) $db->table('client_tasks')->where('workspace_id', $workspace)->where('id', $task)->first();
        $this->assertNull($upgraded['parent_task_id']);
        $this->assertNull($upgraded['client_task_list_id']);
        $this->assertSame(0, $upgraded['organization_position']);
        unset($upgraded['parent_task_id'], $upgraded['client_task_list_id'], $upgraded['organization_position']);
        $this->assertSame($before, $upgraded);

        // Rollback is tested only with no structural data. Once graph writers
        // exist the deployed reader release is the minimum application floor.
        $this->rollbackProbeTo($connection, '2026_10_22_180150');
        $this->assertFalse($schema->hasTable('client_task_lists'));
        $this->assertFalse($schema->hasColumn('client_tasks', 'parent_task_id'));
        $this->assertFalse($schema->hasColumn('client_tasks', 'client_task_list_id'));
        $this->assertSame($before, (array) $db->table('client_tasks')->where('workspace_id', $workspace)->where('id', $task)->first());
    }

    public static function retainedOrganization(): iterable
    {
        yield 'parent reference without a list' => ['parent_task_id'];
        yield 'legacy list reference without a list row' => ['client_task_list_id'];
        yield 'private unreferenced list' => ['private_list'];
        yield 'deleted unreferenced list' => ['deleted_list'];
    }

    #[DataProvider('retainedOrganization')]
    public function test_rollback_refuses_retained_organization_before_any_ddl(string $case): void
    {
        $connection = 'task_organization_guard_probe';
        $this->bootProbeDatabase($connection);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true]));
        $db = DB::connection($connection);
        $workspace = $db->table('workspaces')->insertGetId(['public_id' => (string) Str::uuid(), 'name' => 'Synthetic guard workspace', 'slug' => 'synthetic-guard']);
        $company = $db->table('client_companies')->insertGetId(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'name' => 'Synthetic guard company', 'slug' => 'synthetic-guard-company']);
        $project = $db->table('client_projects')->insertGetId(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'client_company_id' => $company, 'name' => 'Synthetic guard project']);
        if (str_ends_with($case, '_list')) {
            $db->table('client_task_lists')->insert(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'client_project_id' => $project,
                'name' => 'Synthetic retained private list', 'is_visible_to_client' => false, 'deleted_at' => $case === 'deleted_list' ? '2026-10-22 12:00:00' : null]);
        } else {
            // This disposable probe also represents malformed retained legacy
            // data. A rollback must preserve its disclosure barrier too.
            $sqlite = $db->getDriverName() === 'sqlite';
            $db->statement($sqlite ? 'pragma foreign_keys = off' : 'set foreign_key_checks = 0');
            try {
                $db->table('client_tasks')->insert(['public_id' => (string) Str::uuid(), 'workspace_id' => $workspace, 'client_project_id' => $project,
                    'title' => 'Synthetic retained reference', $case => 999999]);
            } finally {
                $db->statement($sqlite ? 'pragma foreign_keys = on' : 'set foreign_key_checks = 1');
            }
        }
        try {
            $this->rollbackProbeTo($connection, '2026_10_22_180150');
            $this->fail('Reader rollback discarded retained organization data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('compatible task reader', $exception->getMessage());
        }
        $schema = Schema::connection($connection);
        $this->assertTrue($schema->hasTable('client_task_lists'));
        $this->assertTrue($schema->hasColumn('client_tasks', 'parent_task_id'));
        $this->assertTrue($schema->hasColumn('client_tasks', 'client_task_list_id'));
        $this->assertSame(1, $db->table(str_ends_with($case, '_list') ? 'client_task_lists' : 'client_tasks')->where('workspace_id', $workspace)->count());
    }
}
