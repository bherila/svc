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

    public function test_native_task_creation_defaults_to_private_like_the_shared_api(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $this->browser($owner)->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic private task'])->assertCreated();
        $this->assertFalse(ClientTask::query()->where('workspace_id', $workspace->id)->sole()->is_visible_to_client);
    }

    public function test_callers_choose_visibility_explicitly_and_share_completion_rules(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $this->browser($owner)->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic web task', 'is_visible_to_client' => true])
            ->assertCreated();
        $webTask = ClientTask::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertTrue($webTask->is_visible_to_client);
        $this->assertSame('open', $webTask->status);
        $this->assertNull($webTask->completed_at);
        $this->agent($owner, ['tasks:write']);
        $created = $this->withHeader('Idempotency-Key', 'synthetic-task-create')->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic agent task'])->assertCreated();
        $created->assertJsonPath('data.is_visible_to_client', false)->assertJsonPath('data.status', 'open');
        $agentTask = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->browser($owner)->patchJson($this->updatePath($workspace, $webTask), ['expected_version' => AgentApiVersion::for($webTask->fresh()), 'status' => 'completed'])
            ->assertOk();
        $this->agent($owner, ['tasks:write']);
        $completed = $this->withHeader('Idempotency-Key', 'synthetic-task-complete')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $created->json('data.version'), 'status' => 'completed'])->assertOk();
        $this->assertSame('2026-10-08 12:00:00', $webTask->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->assertTrue($webTask->fresh()->completed_at->equalTo($agentTask->fresh()->completed_at));
        $this->assertTrue($webTask->fresh()->is_visible_to_client);
        $this->assertFalse($agentTask->fresh()->is_visible_to_client);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
        $edited = $this->withHeader('Idempotency-Key', 'synthetic-task-title')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $completed->json('data.version'), 'title' => 'Revised synthetic agent task', 'description' => null])->assertOk();
        $this->assertSame('2026-10-08 12:00:00', $agentTask->fresh()->completed_at->format('Y-m-d H:i:s'));
        $this->browser($owner)->patchJson($this->updatePath($workspace, $webTask), ['expected_version' => AgentApiVersion::for($webTask->fresh()), 'status' => 'in_progress', 'is_visible_to_client' => false])->assertOk();
        $this->agent($owner, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'synthetic-task-reopen')->patchJson($this->updatePath($workspace, $agentTask), ['expected_version' => $edited->json('data.version'), 'status' => 'open', 'is_visible_to_client' => true])->assertOk();
        $this->assertNull($webTask->fresh()->completed_at);
        $this->assertNull($agentTask->fresh()->completed_at);
        $this->assertFalse($webTask->fresh()->is_visible_to_client);
        $this->assertTrue($agentTask->fresh()->is_visible_to_client);
    }

    public function test_web_and_agent_share_title_and_description_limits(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $this->browser($owner)->postJson($this->createPath($workspace, $project), ['title' => str_repeat('x', 256), 'is_visible_to_client' => true])->assertUnprocessable();
        $this->browser($owner)->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic web task', 'description' => str_repeat('x', 10001), 'is_visible_to_client' => true])->assertUnprocessable();
        $this->browser($owner)->postJson($this->createPath($workspace, $project), ['title' => str_repeat('x', 255), 'description' => str_repeat('x', 10000), 'is_visible_to_client' => true])->assertCreated();
        $this->agent($owner, ['tasks:write']);
        $created = $this->withHeader('Idempotency-Key', 'long-agent-task')->postJson($this->createPath($workspace, $project), ['title' => str_repeat('x', 255), 'description' => str_repeat('x', 10000)])->assertCreated();
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->where('public_id', $created->json('data.id'))->firstOrFail();
        $this->browser($owner)->patchJson($this->updatePath($workspace, $task), ['is_visible_to_client' => true])->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->agent($owner, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'partial-agent-task')->patchJson($this->updatePath($workspace, $task), ['expected_version' => $created->json('data.version'), 'description' => null])->assertOk()->assertJsonPath('data.description', null);
    }

    #[DataProvider('roles')]
    public function test_shared_task_policy_allows_the_same_current_internal_project_roles(string $workspaceRole, ?string $projectRole, bool $agentAllowed, bool $webAllowed): void
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
        $this->browser($actor)->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic web role task', 'is_visible_to_client' => true])->assertStatus($webAllowed ? 201 : 403);
        $this->browser($actor)->patchJson($this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task), 'status' => 'in_progress'])->assertStatus($webAllowed ? 200 : 403);
        $this->agent($actor, ['tasks:write']);
        $this->withHeader('Idempotency-Key', 'role-create')->postJson($this->createPath($workspace, $project), ['title' => 'Synthetic agent role task'])->assertStatus($agentAllowed ? 201 : 403);
        $this->withHeader('Idempotency-Key', 'role-update')->patchJson($this->updatePath($workspace, $task), ['expected_version' => AgentApiVersion::for($task->fresh()), 'title' => 'Revised synthetic role task'])->assertStatus($agentAllowed ? 200 : 403);
    }

    public static function roles(): array
    {
        return [['owner', null, true, true], ['admin', null, true, true], ['member', 'owner', true, true], ['member', 'manager', true, true], ['member', 'contributor', false, false], ['member', 'viewer', false, false], ['member', null, false, false], ['portal', null, false, false]];
    }

    public function test_replay_noop_revision_stale_conflict_and_revocation_are_preserved_under_task_lock(): void
    {
        [$workspace, , $project] = $this->fixture();
        $manager = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'member']);
        $membership = ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $this->agent($manager, ['tasks:write']);
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
        $this->browser($owner)->postJson($this->createPath($workspace, $foreignProject), ['title' => 'Foreign synthetic task', 'is_visible_to_client' => true])->assertNotFound();
        foreach ([$foreignTask, $malformed] as $record) {
            $this->browser($owner)->patchJson($this->updatePath($workspace, $record), ['expected_version' => AgentApiVersion::for($record), 'status' => 'completed'])->assertNotFound();
        }
        $this->agent($owner, ['tasks:write']);
        foreach ([$foreignProject, $badProject] as $record) {
            $this->withHeader('Idempotency-Key', 'foreign-task-create-'.$record->public_id)->postJson($this->createPath($workspace, $record), ['title' => 'Foreign synthetic task', 'is_visible_to_client' => true])->assertNotFound();
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

    private function createPath(Workspace $workspace, ClientProject $project): string
    {
        return '/api/v1'.'/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks';
    }

    private function updatePath(Workspace $workspace, ClientTask $task): string
    {
        return '/api/v1'.'/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id;
    }

    private function browser(User $actor): static
    {
        return $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40))
            ->withHeader('Idempotency-Key', 'synthetic-native-'.Str::uuid());
    }

    /** @param list<string> $scopes */
    private function agent(User $actor, array $scopes): void
    {
        $this->unencryptedCookies = [];
        $this->actingAsMcp($actor, $scopes);
    }
}
