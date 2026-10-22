<?php

namespace Tests\Feature\Tasks\Organization;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\Workspace;
use App\Services\Tasks\Organization\TaskOrganizationRepository;
use App\Services\Tasks\TaskGraphRevisionRepository;
use App\Services\Tasks\WorkspaceTaskRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

final class TaskOrganizationRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_cannot_overwrite_a_revision_advanced_through_the_shared_repository(): void
    {
        [$workspace, $project] = $this->fixture('local');
        DB::transaction(function () use ($workspace, $project): void {
            $workspace = app(WorkspaceTaskRepository::class)->lockWorkspace($workspace);
            $organization = app(TaskOrganizationRepository::class);
            $records = $organization->load($workspace, $project, lock: true, initialize: true);
            $revisions = app(TaskGraphRevisionRepository::class);
            $current = $revisions->read($workspace, $project, current: true);
            $revisions->advance($workspace, $project, $current);
            try {
                $organization->reorderLists($records, []);
                $this->fail('Stale organization records silently overwrote the shared graph revision.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(409, $exception->getStatusCode());
            }
            $this->assertSame(1, $revisions->read($workspace, $project, current: true)->lock_version);
            $this->assertDatabaseCount('client_task_lists', 0);
        });
    }

    public function test_every_organization_load_mode_refuses_a_project_from_another_workspace_before_initialization(): void
    {
        [$workspace] = $this->fixture('local');
        [, $foreignProject] = $this->fixture('foreign');
        foreach ([[false, false], [true, false], [true, true]] as [$lock, $initialize]) {
            try {
                app(TaskOrganizationRepository::class)->load($workspace, $foreignProject, $lock, $initialize);
                $this->fail('An organization load accepted mismatched workspace/project lineage.');
            } catch (HttpExceptionInterface $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
            $this->assertDatabaseCount('client_task_graph_states', 0);
        }
    }

    /** @return array{Workspace,ClientProject} */
    private function fixture(string $suffix): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic organization revision '.$suffix, 'slug' => 'synthetic-organization-revision-'.$suffix]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic revision company '.$suffix, 'slug' => 'synthetic-revision-company-'.$suffix]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic revision project '.$suffix]);

        return [$workspace, $project];
    }
}
