<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientTask;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TaskAgentAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_portal_identity_blocks_agent_updates_and_receipt_replays_even_with_a_private_task_version(): void
    {
        config(['agent_api.writes_enabled' => true]);
        $workspace = Workspace::query()->create(['name' => 'Synthetic dual actor', 'slug' => 'synthetic-dual-actor']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic visible project', 'is_visible_to_client' => true]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->actingAsMcp($owner, ['tasks:read', 'tasks:write']);
        $createPath = '/api/v1/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/tasks';
        $create = ['title' => 'Synthetic private task secret', 'starts_on' => '2026-10-22'];
        $created = $this->withHeader('Idempotency-Key', 'synthetic-before-portal-create')->postJson($createPath, $create)->assertCreated();
        $task = ClientTask::query()->where('workspace_id', $workspace->id)->sole();
        $updatePath = '/api/v1/workspaces/'.$workspace->public_id.'/tasks/'.$task->public_id;
        $update = ['expected_version' => $created->json('data.version'), 'description' => 'Synthetic private description secret'];
        $updated = $this->withHeader('Idempotency-Key', 'synthetic-before-portal-update')->patchJson($updatePath, $update)->assertOk();
        $privateVersion = $updated->json('data.version');
        ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'client']);
        $this->getJson($updatePath)->assertNotFound();
        $this->withHeader('Idempotency-Key', 'synthetic-before-portal-create')->postJson($createPath, $create)->assertForbidden()->assertJsonMissingPath('data');
        $this->withHeader('Idempotency-Key', 'synthetic-before-portal-update')->patchJson($updatePath, $update)->assertForbidden()->assertJsonMissingPath('data');
        foreach ([$privateVersion, str_repeat('0', 64)] as $index => $version) {
            $response = $this->withHeader('Idempotency-Key', 'synthetic-after-portal-'.$index)->patchJson($updatePath,
                ['expected_version' => $version, 'title' => 'Synthetic refused change', 'origin' => 'Internal'])->assertForbidden();
            $response->assertJsonMissingPath('data');
            $this->assertStringNotContainsString($privateVersion, $response->getContent());
            $this->assertStringNotContainsString($task->title, $response->getContent());
        }
        $this->assertSame($privateVersion, AgentApiVersion::for($task->fresh()));
        $this->assertDatabaseCount('client_tasks', 1);
        // The authenticated staff website is a trusted Internal adapter.
        $this->browser($owner)->get(route('tasks.show', [$workspace, $task]))->assertOk();
        $this->native()->patchJson(route('agent-api.v1.tasks.update', [$workspace, $task->public_id]), ['expected_version' => $privateVersion, 'description' => 'Synthetic internal edit'])->assertOk();
        $this->assertSame('Synthetic internal edit', $task->fresh()->description);
    }

    private function browser(User $actor): static
    {
        $this->actingAs($actor, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));

        return $this->native();
    }

    private function native(): static
    {
        return $this->withHeader('Idempotency-Key', 'synthetic-native-'.Str::uuid());
    }
}
