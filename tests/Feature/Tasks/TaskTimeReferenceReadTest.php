<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskTimeReferenceReadTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    public function test_portal_time_records_remain_visible_while_private_ancestry_task_references_are_null(): void
    {
        [$workspace, $company, $project, $owner, $viewer] = $this->fixture();
        $parent = $this->task($workspace, $project, false);
        $hidden = $this->task($workspace, $project);
        DB::table('client_tasks')->where('workspace_id', $workspace->id)->where('id', $hidden->id)->update(['parent_task_id' => $parent->id]);
        $visible = $this->task($workspace, $project);
        $hiddenEntry = $this->entry($workspace, $company, $project, $owner, $hidden);
        $visibleEntry = $this->entry($workspace, $company, $project, $owner, $visible);
        $this->actingAsMcp($viewer, ['time:read']);
        $response = $this->getJson('/api/v1/workspaces/'.$workspace->public_id.'/time-entries')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $hiddenEntry->public_id)->assertJsonPath('data.0.task_id', null)
            ->assertJsonPath('data.1.id', $visibleEntry->public_id)->assertJsonPath('data.1.task_id', $visible->public_id);
        $this->assertJsonPayloadOmits($response, [$hidden->public_id, $parent->public_id], $hiddenEntry->public_id);
        $byIds = app(AgentReadService::class)->timeEntriesByIds($viewer, $workspace, [$hiddenEntry->public_id, $visibleEntry->public_id]);
        $this->assertSame([null, $visible->public_id], array_column($byIds, 'task_id'));
        $internal = app(AgentReadService::class)->timeEntriesByIds($owner, $workspace, [$hiddenEntry->public_id]);
        $this->assertSame($hidden->public_id, $internal[0]['task_id']);
        $this->assertSame(12500, $internal[0]['billing_rate_amount']);
        $this->assertSame(45, $internal[0]['minutes']);
        $this->assertSame('approved', $internal[0]['status']);
    }

    public static function malformedReferences(): iterable
    {
        yield 'other workspace task' => ['workspace'];
        yield 'same workspace other project task' => ['project'];
        yield 'legacy task points to other workspace project' => ['project_workspace'];
        yield 'legacy project points to other workspace company' => ['company_workspace'];
    }

    #[DataProvider('malformedReferences')]
    public function test_malformed_task_lineage_never_changes_the_time_record_or_exposes_task_ids(string $case): void
    {
        [$workspace, $company, $project, $owner, $viewer] = $this->fixture();
        [$foreignWorkspace, $foreignCompany, $foreignProject] = $this->fixture();
        if ($case === 'workspace') {
            $task = $this->task($foreignWorkspace, $foreignProject);
        } elseif ($case === 'project') {
            $otherProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
                'name' => 'Synthetic other task project', 'is_visible_to_client' => true]);
            $task = $this->task($workspace, $otherProject);
        } else {
            $task = $this->writingLegacyCrossTenantRows(function () use ($case, $workspace, $foreignProject, $foreignCompany): ClientTask {
                if ($case === 'company_workspace') {
                    $foreignProject = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $foreignCompany->id,
                        'name' => 'Synthetic malformed company lineage', 'is_visible_to_client' => true]);
                }

                return $this->task($workspace, $foreignProject);
            });
        }
        $entry = $this->entry($workspace, $company, $project, $owner, $task);
        foreach ([$owner, $viewer] as $actor) {
            $rows = app(AgentReadService::class)->timeEntriesByIds($actor, $workspace, [$entry->public_id]);
            $this->assertCount(1, $rows);
            $this->assertSame($entry->public_id, $rows[0]['id']);
            $this->assertNull($rows[0]['task_id']);
            $this->assertSame(45, $rows[0]['minutes']);
            $this->assertSame('approved', $rows[0]['status']);
        }
    }

    public static function surfaces(): iterable
    {
        yield 'list page' => [false];
        yield 'receipt projection' => [true];
    }

    #[DataProvider('surfaces')]
    public function test_task_reference_queries_are_bounded_for_time_pages(bool $byIds): void
    {
        [$workspace, $company, $project, $owner, $viewer] = $this->fixture();
        $task = $this->task($workspace, $project, false);
        $ids = [$this->entry($workspace, $company, $project, $owner, $task)->public_id];
        $this->assertQueryCountIndependentOfRows(function () use ($workspace, $viewer, $byIds, &$ids): void {
            $reads = app(AgentReadService::class);
            $rows = $byIds ? $reads->timeEntriesByIds($viewer, $workspace, $ids)
                : $reads->timeEntries($viewer, $workspace, null, null, null, null, 100, null)['data'];
            $this->assertCount(count($ids), $rows);
            $this->assertSame(array_fill(0, count($ids), null), array_column($rows, 'task_id'));
        }, function () use ($workspace, $company, $project, $owner, $task, &$ids): void {
            for ($index = 0; $index < 5; $index++) {
                $ids[] = $this->entry($workspace, $company, $project, $owner, $task)->public_id;
            }
        });
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic time reference workspace', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic time reference company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic time reference project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $viewer = User::factory()->create();
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $viewer->id, 'role' => 'client']);

        return [$workspace, $company, $project, $owner, $viewer];
    }

    private function task(Workspace $workspace, ClientProject $project, bool $visible = true): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id,
            'title' => 'Synthetic referenced task secret', 'is_visible_to_client' => $visible]);
    }

    private function entry(Workspace $workspace, ClientCompany $company, ClientProject $project, User $owner, ClientTask $task): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => $project->id, 'client_task_id' => $task->id, 'user_id' => $owner->id,
            'worked_on' => '2026-10-22', 'minutes' => 45, 'description' => 'Synthetic internal time description',
            'client_visible_description' => 'Synthetic shared time description', 'is_visible_to_client' => true,
            'status' => 'approved', 'billing_rate_amount' => 12500, 'currency' => 'USD']);
    }
}
