<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

final class TaskMutationParityTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    }

    public function test_web_and_agent_preserve_distinct_visibility_defaults_and_share_completion_rules(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $return = '/workspaces/'.$workspace->public_id.'/clients/'.$company->public_id.'/tasks';
        $this->actingAs($owner)->from($return)->post($this->createPath($workspace, $project, web: true), ['title' => 'Synthetic web task'])
            ->assertRedirect($return)->assertSessionHas('status', 'Task created.');
        $webTask = ClientTask::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($webTask->is_visible_to_client);
        $this->assertSame('open', $webTask->status);
        $this->assertNull($webTask->completed_at);
        $this->actingAsMcp($owner, ['tasks:write']);
        $created = $this->withHeader('Idempotency-Key', 'synthetic-task-create')->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic agent task'])->assertCreated();
        $created->assertJsonPath('data.is_visible_to_client', false)->assertJsonPath('data.status', 'open');
        $agentTask = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->actingAs($owner)->from($return)->patch($this->updatePath($workspace, $webTask, true), ['status' => 'completed'])
            ->assertRedirect($return)->assertSessionHas('status', 'Task updated.');
        $this->actingAsMcp($owner, ['tasks:write']);
        $completed = $this->withHeader('Idempotency-Key', 'synthetic-task-complete')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $created->json('data.version'), 'status' => 'completed'])->assertOk();
        $this->assertSame('2026-10-08 12:00:00', $webTask->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($webTask->fresh()->completed_at->equalTo($agentTask->fresh()->completed_at));
        $this->assertTrue($webTask->fresh()->is_visible_to_client);
        $this->assertFalse($agentTask->fresh()->is_visible_to_client);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
        $edited = $this->withHeader('Idempotency-Key', 'synthetic-task-title')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $completed->json('data.version'), 'title' => 'Revised synthetic agent task', 'description' => null])->assertOk();
        $this->assertSame('2026-10-08 12:00:00', $agentTask->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->actingAs($owner)->patch($this->updatePath($workspace, $webTask, true), ['status' => 'in_progress', 'is_visible_to_client' => false])->assertRedirect();
        $this->actingAsMcp($owner, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'synthetic-task-reopen')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $edited->json('data.version'), 'status' => 'open', 'is_visible_to_client' => true])->assertOk();
        $this->assertNull($webTask->fresh()->completed_at);
        $this->assertNull($agentTask->fresh()->completed_at);
        $this->assertFalse($webTask->fresh()->is_visible_to_client);
        $this->assertTrue($agentTask->fresh()->is_visible_to_client);
    }

    public function test_web_form_validation_and_agent_limits_keep_their_existing_contracts(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $this->actingAs($owner)->postJson($this->createPath($workspace, $project, true), ['title' => str_repeat('x', 201)])->assertUnprocessable();
        $this->actingAs($owner)->postJson($this->createPath($workspace, $project, true), ['title' => 'Synthetic web task', 'description' => str_repeat('x', 5001)])->assertUnprocessable();
        $this->actingAsMcp($owner, ['tasks:write']);
        $created = $this->withHeader('Idempotency-Key', 'long-agent-task')->postJson($this->createPath($workspace, $project), ['title' => str_repeat('x', 255), 'description' => str_repeat('x', 10000)])->assertCreated();
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->actingAs($owner)->patchJson($this->updatePath($workspace, $task, true), ['is_visible_to_client' => true])->assertUnprocessable();
        $this->actingAsMcp($owner, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'partial-agent-task')->patchJson($this->updatePath($workspace, $task), ['expected_version' => $created->json('data.version'), 'description' => null])->assertOk()->assertJsonPath('data.description', null);
    }

    #[DataProvider('roles')]
    public function test_shared_task_policy_preserves_web_manager_gate_and_agent_project_roles(string $workspaceRole, ?string $projectRole, bool $agentAllowed, bool $webAllowed): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $actor = $workspaceRole === 'owner' ? $owner : User::factory()->create();
        if ($workspaceRole === 'portal') {
            ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'client']);
        } elseif ($workspaceRole !== 'owner') {
            $workspace->memberships()->create(['user_id' => $actor->id, 'role' => $workspaceRole]);
        }
        if ($projectRole !== null) {
            ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $actor->id, 'role' => $projectRole]);
        }
        $task = $this->task($workspace, $project);
        $this->actingAs($actor)->postJson($this->createPath($workspace, $project, true), ['title' => 'Synthetic web role task'])->assertStatus($webAllowed ? 302 : 403);
        $this->actingAs($actor)->patchJson($this->updatePath($workspace, $task, true), ['status' => 'in_progress'])->assertStatus($webAllowed ? 302 : 403);
        $this->actingAsMcp($actor, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'role-create')->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic agent role task'])->assertStatus($agentAllowed ? 201 : 403);
        $this->withHeader('Idempotency-Key', 'role-update')->patchJson($this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task->fresh()), 'title' => 'Revised synthetic role task'])->assertStatus($agentAllowed ? 200 : 403);
    }

    public static function roles(): array
    {
        return [['owner', null, true, true], ['admin', null, true, true], ['member', 'owner', true, false], ['member', 'manager', true, false], ['member', 'contributor', false, false], ['member', 'viewer', false, false], ['member', null, false, false], ['portal', null, false, false]];
    }

    public function test_replay_noop_revision_stale_conflict_and_revocation_are_preserved_under_task_lock(): void
    {
        [$workspace, , $project] = $this->fixture();
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        $membership = ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->actingAsMcp($manager, ['tasks:write']);
        $body = ['title' => 'Synthetic replay task'];
        $created = $this->withHeader('Idempotency-Key', 'replay-task-create')->postJson($this->createPath($workspace, $project), $body)->assertCreated();
        $this->postJson($this->createPath($workspace, $project), $body)->assertCreated()->assertJsonPath('data.id', $created->json('data.id'));
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $created->json('data.id'))->firstOrFail();
        LockOrderRecorder::start();
        try {
            $update = ['expected_version' => $created->json('data.version')];
            $updated = $this->withHeader('Idempotency-Key', 'replay-task-update')->patchJson($this->updatePath($workspace, $task), $update)->assertOk();
            $this->assertNotSame($created->json('data.version'), $updated->json('data.version'));
            $sequence = LockOrderRecorder::sequences()[0];
            $this->assertContains(LockResource::ClientTask, $sequence);
        } finally {
            LockOrderRecorder::stop();
        }
        $this->patchJson($this->updatePath($workspace, $task), $update)->assertOk()->assertJsonPath('data.version', $updated->json('data.version'));
        $this->withHeader('Idempotency-Key', 'stale-task-update')->patchJson($this->updatePath($workspace, $task), $update)->assertConflict();
        $membership->delete();
        $this->withHeader('Idempotency-Key', 'replay-task-create')->postJson($this->createPath($workspace, $project), $body)->assertForbidden();
        $this->withHeader('Idempotency-Key', 'replay-task-update')->patchJson($this->updatePath($workspace, $task), $update)->assertForbidden();
        $this->assertDatabaseCount('client_tasks', 1);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'tasks.update', 'outcome' => 'replay']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'tasks.update', 'outcome' => 'failed', 'error_category' => 'conflict']);
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'tasks.update', 'outcome' => 'failed', 'error_category' => 'forbidden']);
    }

    public function test_web_and_agent_writes_reject_foreign_and_malformed_parent_chains(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        [$foreign, $foreignCompany, $foreignProject] = $this->fixture();
        $foreignTask = $this->task($foreign, $foreignProject);
        $malformed = $this->writingLegacyCrossTenantRows(fn () => $this->task($workspace, $foreignProject));
        $badProject = $this->writingLegacyCrossTenantRows(fn () => ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $foreignCompany->id, 'name' => 'Malformed synthetic project']));
        $this->actingAs($owner)->postJson($this->createPath($workspace, $foreignProject, true), ['title' => 'Foreign synthetic task'])->assertNotFound();
        foreach ([$foreignTask, $malformed] as $record) {
            $this->actingAs($owner)->patchJson($this->updatePath($workspace, $record, true), ['status' => 'completed'])->assertNotFound();
        }
        $this->actingAsMcp($owner, ['tasks:write']);
        foreach ([$foreignProject, $badProject] as $record) {
            $this->withHeader('Idempotency-Key', 'foreign-task-create-'.$record->public_id)->postJson($this->createPath($workspace, $record), ['title' => 'Foreign synthetic task'])->assertNotFound();
        }
        foreach ([$foreignTask, $malformed] as $record) {
            $this->withHeader('Idempotency-Key', 'foreign-task-update-'.$record->public_id)->patchJson($this->updatePath($workspace, $record), ['expected_version' => AgentApiVersion::for($record), 'status' => 'completed'])->assertNotFound();
            $this->assertSame('open', $record->fresh()->status);
        }
        $this->assertDatabaseCount('agent_mutation_receipts', 0);
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic task workspace', 'slug' => 'synthetic-'.Str::uuid(), 'timezone' => 'America/Los_Angeles']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic task company', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic task project']);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }

    private function task(Workspace $workspace, ClientProject $project): ClientTask
    {
        return ClientTask::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'title' => 'Synthetic task', 'status' => 'open']);
    }

    private function createPath(Workspace $workspace, ClientProject $project, bool $web = false): string
    {
        return ($web ? '' : '/api/v1').'/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks';
    }

    private function updatePath(Workspace $workspace, ClientTask $task, bool $web = false): string
    {
        return ($web ? '' : '/api/v1').'/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id;
    }
}
