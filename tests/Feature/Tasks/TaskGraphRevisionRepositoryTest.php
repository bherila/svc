<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTaskGraphState;
use App\Models\Workspace;
use App\Services\Tasks\TaskGraphRevisionRepository;
use App\Services\Tasks\WorkspaceTaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TaskGraphRevisionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_ordinary_and_current_reads_never_initialize_absent_state(): void
    {
        [$workspace, $project] = $this->fixture();
        foreach ([false, true] as $current) {
            $state = app(TaskGraphRevisionRepository::class)->read($workspace, $project, $current);
            $this->assertFalse($state->exists);
            $this->assertSame(0, $state->lock_version);
            $this->assertSame($workspace->id, $state->workspace_id);
            $this->assertSame($project->id, $state->client_project_id);
        }
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    public function test_mutations_initialize_once_and_advance_the_exact_scoped_state(): void
    {
        [$workspace, $project] = $this->fixture();
        DB::transaction(function () use ($workspace, $project): void {
            app(WorkspaceTaskRepository::class)->lockWorkspace($workspace);
            $repository = app(TaskGraphRevisionRepository::class);
            $state = $repository->lockForMutation($workspace, $project);
            $this->assertTrue($state->exists);
            $this->assertSame(0, $state->lock_version);
            $this->assertSame($state->id, $repository->lockForMutation($workspace, $project)->id);
            $repository->advance($workspace, $project, $state);
            $this->assertSame(1, $state->lock_version);
            $this->assertSame(1, $repository->read($workspace, $project, true)->lock_version);
            $repository->advance($workspace, $project, $state);
            $this->assertSame(2, $state->lock_version);
        });
        $this->assertDatabaseCount('client_task_graph_states', 1);
        $this->assertDatabaseHas('client_task_graph_states', ['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'lock_version' => 2]);
    }

    public function test_foreign_project_or_state_cannot_advance_another_project(): void
    {
        [$workspace, $project] = $this->fixture();
        [$foreignWorkspace, $foreignProject] = $this->fixture('foreign');
        $repository = app(TaskGraphRevisionRepository::class);
        $foreign = $repository->lockForMutation($foreignWorkspace, $foreignProject);
        foreach (['project', 'state', 'absent'] as $case) {
            try {
                match ($case) {
                    'project' => $repository->read($workspace, $foreignProject, true),
                    'state' => $repository->advance($workspace, $project, $foreign),
                    'absent' => $repository->advance($workspace, $project, $repository->read($workspace, $project)),
                };
                $this->fail('Foreign or absent graph state was accepted.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $this->assertSame(0, $foreign->fresh()->lock_version);
        $this->assertDatabaseCount('client_task_graph_states', 1);
    }

    public function test_stale_or_deleted_state_fails_instead_of_losing_an_increment_or_recreating_it(): void
    {
        [$workspace, $project] = $this->fixture();
        $repository = app(TaskGraphRevisionRepository::class);
        $state = $repository->lockForMutation($workspace, $project);
        $stale = $repository->read($workspace, $project);
        $repository->advance($workspace, $project, $state);
        try {
            $repository->advance($workspace, $project, $stale);
            $this->fail('An old state overwrote a newer revision.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame(1, $state->fresh()->lock_version);
        ClientTaskGraphState::query()->where('workspace_id', $workspace->id)->whereKey($state->id)->delete();
        try {
            $repository->advance($workspace, $project, $state);
            $this->fail('A deleted state was recreated.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('client_task_graph_states', 0);
    }

    /** @return array{Workspace, ClientProject} */
    private function fixture(string $suffix = 'local'): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic graph '.$suffix, 'slug' => 'synthetic-graph-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic company '.$suffix, 'slug' => 'synthetic-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project '.$suffix]);

        return [$workspace, $project];
    }
}
