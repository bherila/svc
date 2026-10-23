<?php

namespace Tests\Feature\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskGraphState;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TaskOrganizationMigrationTest extends TestCase
{
    use UsesAProbeDatabase;

    public function test_tenant_fk_and_retained_revision_or_order_barriers_precede_any_destructive_ddl(): void
    {
        $this->bootProbeDatabase('organization_schema');
        Artisan::call('migrate', ['--database' => 'organization_schema', '--force' => true]);
        $original = config('database.default');
        config(['database.default' => 'organization_schema']);
        try {
            $workspace = Workspace::query()->create(['name' => 'Synthetic organization schema', 'slug' => 'synthetic-organization-schema']);
            $foreign = Workspace::query()->create(['name' => 'Synthetic foreign schema', 'slug' => 'synthetic-foreign-schema']);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic schema company', 'slug' => 'synthetic-schema-company']);
            $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic schema project']);
            $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic retained task']);
            try {
                ClientTaskGraphState::query()->create(['workspace_id' => $foreign->id, 'client_project_id' => $project->id]);
                $this->fail('A graph state referenced another tenant project.');
            } catch (QueryException) {
                $this->assertDatabaseCount('client_task_graph_states', 0);
            }
            $migration = require database_path('migrations/2026_10_22_180160_add_task_organization_writers.php');
            $state = ClientTaskGraphState::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id]);
            try {
                $migration->down();
                $this->fail('Schema rollback discarded a retained revision.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Retain this migration.', $exception->getMessage());
                $this->assertTrue(Schema::hasTable('client_task_graph_states'));
                $this->assertTrue(Schema::hasColumn('client_tasks', 'organization_position'));
                $this->assertTrue(Schema::hasColumn('client_task_lists', 'organization_position'));
                $this->assertDatabaseHas('client_task_graph_states', ['id' => $state->id]);
            }
            $state->delete();
            DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $task->id)->update(['organization_position' => 1]);
            try {
                $migration->down();
                $this->fail('Schema rollback discarded retained ordering.');
            } catch (RuntimeException) {
                $this->assertTrue(Schema::hasTable('client_task_graph_states'));
                $this->assertDatabaseHas('client_tasks', ['id' => $task->id, 'organization_position' => 1]);
            }
            DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $task->id)->update(['organization_position' => 0]);
            $migration->down();
            $this->assertFalse(Schema::hasTable('client_task_graph_states'));
            $this->assertFalse(Schema::hasColumn('client_tasks', 'organization_position'));
            $this->assertFalse(Schema::hasColumn('client_task_lists', 'organization_position'));
            $this->assertDatabaseHas('client_tasks', ['id' => $task->id, 'workspace_id' => $workspace->id, 'title' => 'Synthetic retained task']);
            $this->assertTrue(Schema::hasColumn('client_tasks', 'parent_task_id'));
        } finally {
            config(['database.default' => $original]);
        }
    }
}
