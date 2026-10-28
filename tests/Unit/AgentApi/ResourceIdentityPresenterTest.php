<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use App\Support\AgentApi\Presenters\AgentWorkspacePresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AgentProjectPresenter::class)]
#[CoversClass(AgentTaskPresenter::class)]
#[CoversClass(AgentWorkspacePresenter::class)]
final class ResourceIdentityPresenterTest extends TestCase
{
    public function test_workspace_identity_keeps_role_and_capabilities_out_of_native_selector_rows(): void
    {
        $workspace = new Workspace;
        $workspace->setRawAttributes(['id' => 71, 'public_id' => 'synthetic-workspace', 'name' => 'Synthetic 選択肢 </script>',
            'timezone' => 'America/Los_Angeles', 'default_currency' => 'USD']);

        $this->assertSame(['id' => 'synthetic-workspace', 'name' => 'Synthetic 選択肢 </script>'], AgentWorkspacePresenter::identity($workspace));
        $this->assertSame([], $workspace->getRelations());
    }

    public function test_safe_project_identity_needs_no_parent_and_withholds_internal_fields(): void
    {
        $project = new ClientProject;
        $project->setRawAttributes(['id' => 17, 'public_id' => 'synthetic-public-project', 'workspace_id' => 23,
            'client_company_id' => 29, 'name' => 'Synthetic 選択肢 </script>', 'repository' => 'private.example.test/hidden/repository',
            'description' => 'Synthetic private project description', 'is_visible_to_client' => false, 'lock_version' => 7]);

        $this->assertFalse($project->relationLoaded('clientCompany'));
        $this->assertSame(['id' => 'synthetic-public-project', 'name' => 'Synthetic 選択肢 </script>'], AgentProjectPresenter::identity($project));
        $this->assertSame([], $project->getRelations());
    }

    public function test_safe_task_identity_needs_no_project_and_withholds_internal_fields(): void
    {
        $task = new ClientTask;
        $task->setRawAttributes(['id' => 31, 'public_id' => 'synthetic-public-task', 'workspace_id' => 37,
            'client_project_id' => 41, 'title' => 'Synthetic task 選択肢 </script>', 'description' => 'Synthetic private task description',
            'is_visible_to_client' => false, 'lock_version' => 11]);

        $this->assertFalse($task->relationLoaded('project'));
        $this->assertSame(['id' => 'synthetic-public-task', 'title' => 'Synthetic task 選択肢 </script>'], AgentTaskPresenter::identity($task));
        $this->assertSame([], $task->getRelations());
    }

    public function test_shared_project_details_keep_repository_disclosure_at_the_full_presenter(): void
    {
        $company = new ClientCompany;
        $company->setRawAttributes(['public_id' => 'synthetic-company', 'name' => 'Synthetic client']);
        $workspace = new Workspace;
        $workspace->setRawAttributes(['public_id' => 'synthetic-workspace']);
        $project = new ClientProject;
        $project->setRawAttributes(['id' => 19, 'public_id' => 'synthetic-project', 'name' => 'Synthetic project',
            'description' => null, 'status' => 'paused', 'is_visible_to_client' => false,
            'repository' => 'private.example.test/hidden/repository', 'lock_version' => 5]);
        $expected = ['id' => 'synthetic-project', 'name' => 'Synthetic project', 'description' => null,
            'status' => 'paused', 'is_visible_to_client' => false];
        $this->assertSame($expected, AgentProjectPresenter::summary($project));
        $this->assertSame([], $project->getRelations());
        $project->setRelation('clientCompany', $company);
        $presenter = new AgentProjectPresenter;
        $public = $presenter->present($workspace, $project, false);
        $this->assertSame($expected, array_intersect_key($public, $expected));
        $this->assertArrayNotHasKey('repository', $public);
        $this->assertSame('private.example.test/hidden/repository', $presenter->present($workspace, $project, true)['repository']);
        $project->forceFill(['description' => 'Synthetic details', 'is_visible_to_client' => true]);
        $this->assertSame('Synthetic details', AgentProjectPresenter::summary($project)['description']);
        $this->assertTrue(AgentProjectPresenter::summary($project)['is_visible_to_client']);
    }

    public function test_task_core_and_editor_details_share_versions_and_preserve_both_completion_formats(): void
    {
        $company = new ClientCompany;
        $company->setRawAttributes(['public_id' => 'synthetic-company']);
        $project = new ClientProject;
        $project->setRawAttributes(['public_id' => 'synthetic-project']);
        $project->setRelation('clientCompany', $company);
        $workspace = new Workspace;
        $workspace->setRawAttributes(['public_id' => 'synthetic-workspace']);
        $task = new ClientTask;
        $task->setRawAttributes(['id' => 43, 'public_id' => 'synthetic-task', 'title' => 'Synthetic title',
            'description' => 'Synthetic editor details', 'status' => 'completed', 'is_visible_to_client' => false,
            'completed_at' => '2026-10-20 15:16:17', 'lock_version' => 13]);
        $expected = ['id' => 'synthetic-task', 'title' => 'Synthetic title', 'status' => 'completed', 'is_visible_to_client' => false];
        $this->assertSame($expected, AgentTaskPresenter::summary($task));
        $details = AgentTaskPresenter::details($task);
        $this->assertSame('Synthetic editor details', $details['description']);
        $this->assertSame('2026-10-20T15:16:17+00:00', $details['completed_at']);
        $this->assertSame('2026-10-20', AgentTaskPresenter::completedAt($task, dateOnly: true));
        $this->assertSame(AgentApiVersion::for($task), $details['version']);
        $this->assertSame([], $task->getRelations());
        $task->setRelation('project', $project);
        $public = (new AgentTaskPresenter)->present($workspace, $task);
        $this->assertSame($details, array_intersect_key($public, $details));
        $task->forceFill(['completed_at' => null, 'description' => null, 'is_visible_to_client' => true]);
        $this->assertNull(AgentTaskPresenter::completedAt($task));
        $this->assertNull(AgentTaskPresenter::completedAt($task, dateOnly: true));
        $this->assertNull(AgentTaskPresenter::details($task)['description']);
        $this->assertTrue(AgentTaskPresenter::summary($task)['is_visible_to_client']);
    }
}
