<?php

namespace Tests\Feature\Tasks\Planning;

use App\Http\Controllers\Api\V1\AgentTaskPlanningController;
use App\Http\Controllers\ClientTaskPlanningController;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\ClientTaskLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Tasks\Planning\TaskPlanningNativePresenter;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Test-only bindings isolate adapter policy from the coordinator's route registration. */
final class TaskPlanningAdaptersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Isolated controller probes are not published API routes; registry tests
        // cover the real /api/v1 bindings and their OpenAPI contracts.
        foreach ([['synthetic-api/planning', AgentTaskPlanningController::class, ['api', 'auth:api']],
            ['synthetic-planning', ClientTaskPlanningController::class, ['web', 'auth:web']]] as [$prefix, $controller, $middleware]) {
            Route::prefix($prefix.'/{workspace}')->middleware($middleware)->group(function () use ($controller, $prefix): void {
                foreach ([['get', '/tasks/{task}', 'get'], ['get', '/labels', 'labels.list'], ['post', '/labels', 'labels.create'],
                    ['get', '/labels/{label}', 'labels.get'], ['patch', '/labels/{label}', 'labels.update'], ['delete', '/labels/{label}', 'labels.archive'],
                    ['post', '/labels/{label}/restore', 'labels.restore'], ['get', '/projects/{project}/labels', 'labels.options'], ['put', '/tasks/{task}/labels', 'labels.replace'],
                    ['get', '/tasks/{task}/dependencies', 'dependencies.get'], ['get', '/tasks/{task}/dependency-options', 'dependencies.options'], ['put', '/tasks/{task}/dependencies', 'dependencies.replace'],
                    ['get', '/tasks/{task}/contacts', 'contacts.get'], ['get', '/tasks/{task}/contact-options', 'contacts.options'], ['put', '/tasks/{task}/contacts', 'contacts.replace']] as [$verb, $uri, $operation]) {
                    $route = Route::$verb($uri, [$controller, $verb === 'get' ? 'show' : 'mutate'])->defaults('task_planning_operation', $operation);
                    if ($prefix === 'synthetic-planning') {
                        $route->name('tasks.planning.'.$operation);
                    }
                }
                if ($prefix === 'synthetic-planning') {
                    Route::get('/catalog', [$controller, 'page'])->name('tasks.planning.labels.page');
                }
            });
        }
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_http_route_owned_targets_closed_inputs_replay_and_stale_refusal(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write', 'clients:read']);
        $base = '/synthetic-api/planning/'.$workspace->public_id;
        $version = $this->getJson($base.'/tasks/'.$task->public_id)->assertOk()->json('data.version');
        $id = $this->withHeader('Idempotency-Key', 'synthetic-label')->postJson($base.'/labels', ['name' => 'Synthetic HTTP label'])->assertCreated()->json('data.id');
        $input = ['expected_version' => $version, 'label_ids' => [$id]];
        $this->withHeader('Idempotency-Key', 'synthetic-attach')->putJson($base.'/tasks/'.$task->public_id.'/labels', $input)->assertOk()->assertExactJson(['data' => ['id' => $task->public_id]]);
        $this->withHeader('Idempotency-Key', 'synthetic-attach')->putJson($base.'/tasks/'.$task->public_id.'/labels', $input)->assertOk();
        $this->assertDatabaseCount('client_task_label_assignments', 1);
        $this->withHeader('Idempotency-Key', 'synthetic-stale')->putJson($base.'/tasks/'.$task->public_id.'/labels', $input)->assertConflict();
        foreach (['task_id', 'workspace_id', 'origin', 'client_id'] as $field) {
            $this->withHeader('Idempotency-Key', 'synthetic-forged-'.$field)->putJson($base.'/tasks/'.$task->public_id.'/labels', $input + [$field => $task->public_id])->assertUnprocessable();
        }
        $this->getJson($base.'/tasks/'.$task->public_id.'?include_contacts=true')->assertUnprocessable();
        $this->getJson($base.'/projects/'.$project->public_id.'/labels?limit=26')->assertUnprocessable();
        $this->getJson($base.'/tasks/'.$task->public_id)->assertOk()->assertJsonMissingPath('data.contacts');
        $this->actingAs($owner, 'web');
        $this->getJson('/synthetic-planning/'.$workspace->public_id.'/tasks/'.$task->public_id)->assertOk()->assertJsonPath('data.labels.0.id', $id);
    }

    public function test_default_off_and_dual_agent_refusals_leave_no_state_but_native_staff_remains_usable(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write', 'clients:read']);
        $base = '/synthetic-api/planning/'.$workspace->public_id;
        config(['task_planning_details.enabled' => false]);
        $this->getJson($base.'/tasks/'.$task->public_id)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-off')->postJson($base.'/labels', ['name' => 'Synthetic refused'])->assertNotFound();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->assertNull(app(TaskPlanningNativePresenter::class)->present($owner, $workspace, $task->public_id));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        config(['task_planning_details.enabled' => true]);
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'user_id' => $owner->id, 'role' => 'client']);
        foreach (['/tasks/'.$task->public_id, '/labels', '/tasks/'.$task->public_id.'/contacts'] as $path) {
            $this->getJson($base.$path)->assertForbidden();
        }
        $this->withHeader('Idempotency-Key', 'synthetic-dual')->postJson($base.'/labels', ['name' => 'Synthetic refused'])->assertForbidden();
        $this->actingAs($owner, 'web');
        $this->getJson('/synthetic-planning/'.$workspace->public_id.'/tasks/'.$task->public_id)->assertOk();
        foreach (['client_task_labels', 'client_task_graph_states', 'agent_mutation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_native_initial_options_are_scoped_bounded_and_contacts_omitted_for_project_managers(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        for ($i = 0; $i < 27; $i++) {
            ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$i, 'name_key' => hash('sha256', 'synthetic '.$i)]);
            ClientContact::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'first_name' => 'Synthetic '.$i]);
        }
        $props = app(TaskPlanningNativePresenter::class)->present($owner, $workspace, $task->public_id);
        $this->assertCount(25, $props['labels']['options']['data']);
        $this->assertCount(25, $props['contacts']['options']['data']);
        $this->assertStringContainsString('cursor=', $props['labels']['options']['next_href']);
        $this->assertSame($task->public_id, $props['dependencies']['data']['task_id']);
        $this->assertSame([], $props['dependencies']['options']['data']);
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $managed = app(TaskPlanningNativePresenter::class)->present($manager, $workspace, $task->public_id);
        $this->assertArrayNotHasKey('contacts', $managed);
        $this->assertNull($managed['label_catalog_href']);
        $this->assertNotNull($managed['labels']['replace_href']);
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_native_option_continuations_are_finished_urls_and_agent_pages_keep_the_public_cursor_contract(): void
    {
        [$workspace, $project, $owner, $task] = $this->fixture();
        for ($i = 0; $i < 27; $i++) {
            ClientTaskLabel::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic label '.$i, 'name_key' => hash('sha256', 'synthetic label '.$i)]);
        }
        $this->actingAs($owner, 'web');
        $base = '/synthetic-planning/'.$workspace->public_id;
        $page = $this->getJson($base.'/projects/'.$project->public_id.'/labels?q=Synthetic&limit=25')->assertOk()
            ->assertJsonCount(25, 'data')->assertJsonMissingPath('meta')->assertJsonPath('previous_href', null);
        $next = $page->json('next_href');
        $this->assertIsString($next);
        $this->assertStringContainsString('q=Synthetic', $next);
        $this->assertStringContainsString('cursor=', $next);
        $this->getJson($next)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('next_href', null);
        $this->getJson($base.'/tasks/'.$task->public_id.'/dependencies')->assertOk()->assertJsonPath('next_href', null)->assertJsonMissingPath('meta');
        $this->actingAsMcp($owner, ['tasks:read']);
        $this->getJson('/synthetic-api/planning/'.$workspace->public_id.'/projects/'.$project->public_id.'/labels')
            ->assertOk()->assertJsonCount(25, 'data')->assertJsonMissingPath('next_href');
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_cli_preview_rolls_back_receipts_catalog_and_associations_while_apply_and_replay_commit(): void
    {
        [$workspace, , $owner, $task] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'svc-planning-test-');
        $this->assertIsString($file);
        chmod($file, 0600);
        try {
            file_put_contents($file, json_encode(['name' => 'Synthetic CLI label'], JSON_THROW_ON_ERROR));
            $args = ['operation' => 'labels.create', 'workspace' => $workspace->public_id, '--actor' => $owner->public_id, '--input' => $file, '--key' => 'synthetic-cli-label'];
            $this->assertSame(0, Artisan::call('svc:tasks:planning', $args));
            $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['dry_run']);
            $this->assertDatabaseCount('client_task_labels', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 0);
            $this->assertSame(0, Artisan::call('svc:tasks:planning', $args + ['--apply' => true]));
            $this->assertSame(0, Artisan::call('svc:tasks:planning', $args + ['--apply' => true]));
            $label = ClientTaskLabel::query()->sole();
            $this->assertDatabaseCount('agent_mutation_receipts', 1);
            file_put_contents($file, json_encode(['label_ids' => [$label->public_id], 'expected_version' => AgentApiVersion::for($task)], JSON_THROW_ON_ERROR));
            $attach = ['operation' => 'labels.replace', 'workspace' => $workspace->public_id, 'target' => $task->public_id, '--actor' => $owner->public_id, '--input' => $file, '--key' => 'synthetic-cli-attach'];
            $this->assertSame(0, Artisan::call('svc:tasks:planning', $attach));
            $this->assertDatabaseCount('client_task_label_assignments', 0);
            $this->assertDatabaseCount('client_task_graph_states', 0);
            $this->assertDatabaseCount('agent_mutation_receipts', 1);
            $this->assertSame(0, Artisan::call('svc:tasks:planning', $attach + ['--apply' => true]));
            $this->assertDatabaseCount('client_task_label_assignments', 1);
            $this->assertDatabaseCount('client_task_graph_states', 1);
            $this->assertSame(0, Artisan::call('svc:tasks:planning', ['operation' => 'get', 'workspace' => $workspace->public_id, 'target' => $task->public_id, '--actor' => $owner->public_id]));
            $this->assertSame($label->public_id, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['data']['labels'][0]['id']);
        } finally {
            unlink($file);
        }
    }

    /** @return array{Workspace,ClientProject,User,ClientTask} */
    private function fixture(): array
    {
        config(['task_planning_details.enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic adapter', 'slug' => 'synthetic-adapter']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company', 'slug' => 'synthetic-adapter-company']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $task = ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic source task']);

        return [$workspace, $project, $owner, $task];
    }
}
