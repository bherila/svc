<?php

namespace Tests\Feature\Tasks\Planning;

use App\Models\ClientCompany;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTaskLabel;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

final class TaskPlanningSchemaTest extends TestCase
{
    use RefreshDatabase;
    use UsesAProbeDatabase;

    public function test_database_refuses_cross_tenant_project_company_duplicate_and_self_references(): void
    {
        [$workspace, $company, $project, $first, $second, $contact, $label] = $this->fixture('local');
        [$foreignWorkspace, $foreignCompany, $foreignProject, $foreignTask, , $foreignContact, $foreignLabel] = $this->fixture('foreign');
        $otherCompany = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic other company', 'slug' => 'synthetic-schema-other-company']);
        $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'name' => 'Synthetic other project']);
        $otherTask = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $otherProject->id, 'title' => 'Synthetic other task']);
        $otherContact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $otherCompany->id, 'first_name' => 'Synthetic other contact']);
        $dependency = ['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'client_task_id' => $first->id, 'prerequisite_task_id' => $second->id];
        $assignment = ['workspace_id' => $workspace->id, 'client_task_id' => $first->id, 'client_task_label_id' => $label->id];
        $responsibility = ['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'client_task_id' => $first->id, 'client_contact_id' => $contact->id];
        DB::table('client_task_dependencies')->insert($dependency);
        DB::table('client_task_label_assignments')->insert($assignment);
        DB::table('client_task_contact_responsibilities')->insert($responsibility);
        $cases = [
            ['client_task_dependencies', $dependency],
            ['client_task_dependencies', array_replace($dependency, ['prerequisite_task_id' => $first->id])],
            ['client_task_dependencies', array_replace($dependency, ['prerequisite_task_id' => $otherTask->id])],
            ['client_task_dependencies', array_replace($dependency, ['prerequisite_task_id' => $foreignTask->id])],
            ['client_task_dependencies', array_replace($dependency, ['client_project_id' => $foreignProject->id])],
            ['client_task_label_assignments', array_replace($assignment, ['client_task_label_id' => $foreignLabel->id])],
            ['client_task_label_assignments', array_replace($assignment, ['client_task_id' => $foreignTask->id])],
            ['client_task_contact_responsibilities', $responsibility],
            ['client_task_contact_responsibilities', array_replace($responsibility, ['client_contact_id' => $otherContact->id])],
            ['client_task_contact_responsibilities', array_replace($responsibility, ['client_contact_id' => $foreignContact->id])],
            ['client_task_contact_responsibilities', array_replace($responsibility, ['client_company_id' => $otherCompany->id, 'client_contact_id' => $otherContact->id])],
            ['client_task_contact_responsibilities', array_replace($responsibility, ['client_project_id' => $otherProject->id])],
            ['client_task_contact_responsibilities', array_replace($responsibility, ['workspace_id' => $foreignWorkspace->id, 'client_company_id' => $foreignCompany->id])],
        ];
        foreach ($cases as [$table, $attributes]) {
            try {
                DB::transaction(fn () => DB::table($table)->insert($attributes));
                $this->fail('The database accepted an invalid '.$table.' reference.');
            } catch (QueryException) {
                $this->assertDatabaseCount($table, 1);
            }
        }
        try {
            DB::transaction(fn () => DB::table('client_task_dependencies')->where('workspace_id', $workspace->id)->where('client_task_id', $first->id)->update(['prerequisite_task_id' => $first->id]));
            $this->fail('The update self-edge guard was absent.');
        } catch (QueryException) {
            $this->assertDatabaseHas('client_task_dependencies', $dependency);
        }
    }

    public static function retainedPlanning(): iterable
    {
        yield 'archived label' => ['180300', 'client_task_labels'];
        yield 'dependency' => ['180310', 'client_task_dependencies'];
        yield 'responsibility' => ['180320', 'client_task_contact_responsibilities'];
    }

    #[DataProvider('retainedPlanning')]
    public function test_schema_rollback_refuses_retained_planning_before_ddl(string $migration, string $table): void
    {
        $original = config('database.default');
        $this->bootProbeDatabase('task_planning_schema');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'task_planning_schema', '--force' => true]));
        config(['database.default' => 'task_planning_schema']);
        try {
            [$workspace, $company, $project, $first, $second, $contact, $label] = $this->fixture('probe');
            if ($table === 'client_task_labels') {
                $label->forceFill(['is_archived' => true])->save();
            } elseif ($table === 'client_task_dependencies') {
                DB::table($table)->insert(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'client_task_id' => $first->id, 'prerequisite_task_id' => $second->id]);
            } else {
                DB::table($table)->insert(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id, 'client_task_id' => $first->id, 'client_contact_id' => $contact->id]);
            }
            try {
                $this->migration($migration)->down();
                $this->fail('Retained planning state was discarded.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Retained task', $exception->getMessage());
            }
            $this->assertTrue(Schema::hasTable($table));
            $this->assertSame(1, DB::table($table)->count());
            $this->assertSame(2, ClientTask::query()->where('workspace_id', $workspace->id)->count());
        } finally {
            config(['database.default' => $original]);
        }
    }

    public function test_empty_planning_schema_round_trip_preserves_legacy_task_and_profile_rows(): void
    {
        $original = config('database.default');
        $this->bootProbeDatabase('task_planning_empty_schema');
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'task_planning_empty_schema', '--force' => true]));
        config(['database.default' => 'task_planning_empty_schema']);
        try {
            [$workspace, , , $task] = $this->fixture('empty');
            ClientTaskLabel::query()->where('workspace_id', $workspace->id)->delete();
            $before = $task->fresh()->getAttributes();
            foreach (['180320', '180310', '180300'] as $id) {
                $this->migration($id)->down();
            }
            foreach (['client_task_labels', 'client_task_dependencies', 'client_task_contact_responsibilities'] as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $this->assertSame($before, $task->fresh()->getAttributes());
            foreach (['180300', '180310', '180320'] as $id) {
                $this->migration($id)->up();
            }
            $this->assertSame($before, $task->fresh()->getAttributes());
            $this->assertDatabaseCount('client_contacts', 1);
        } finally {
            config(['database.default' => $original]);
        }
    }

    private function migration(string $suffix): object
    {
        $paths = glob(database_path('migrations/2026_10_22_'.$suffix.'_*.php'));
        $this->assertCount(1, $paths);

        return require $paths[0];
    }

    private function fixture(string $suffix): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic schema '.$suffix, 'slug' => 'synthetic-schema-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company '.$suffix, 'slug' => 'synthetic-schema-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project '.$suffix]);
        $first = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic first']);
        $second = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic second']);
        $contact = ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'first_name' => 'Synthetic contact']);
        $label = ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'public_id' => (string) Str::uuid(), 'name' => 'Synthetic label', 'name_key' => hash('sha256', 'synthetic label')]);

        return [$workspace, $company, $project, $first, $second, $contact, $label];
    }
}
