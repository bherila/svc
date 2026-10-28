<?php

namespace Tests\Unit\AgentApi;

use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Support\AgentApi\Presenters\AgentProjectPresenter;
use App\Support\AgentApi\Presenters\AgentTaskPresenter;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AgentProjectPresenter::class)]
#[CoversClass(AgentTaskPresenter::class)]
final class ResourceIdentityPresenterTest extends TestCase
{
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
}
