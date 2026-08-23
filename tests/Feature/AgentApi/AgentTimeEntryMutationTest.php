<?php

namespace Tests\Feature\AgentApi;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

final class AgentTimeEntryMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contributor_can_idempotently_log_then_edit_and_delete_their_draft_only(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $contributor = User::factory()->create();
        $other = User::factory()->create();
        $this->member($workspace, $contributor);
        $this->member($workspace, $other);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $contributor->id, 'role' => 'contributor']);
        $this->actingAsAgent($contributor, [AgentApiScopes::TIME_WRITE]);
        $payload = ['entries' => [['project_id' => $project->public_id, 'worked_on' => '2026-08-23', 'minutes' => 45, 'description' => 'Draft work']]];

        $first = $this->withHeader('Idempotency-Key', 'time-log-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", $payload)->assertCreated();
        $entry = $first->json('data.0');
        $this->withHeader('Idempotency-Key', 'time-log-1')->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries", $payload)->assertCreated()->assertJsonPath('data.0.id', $entry['id']);
        $this->assertDatabaseCount('client_time_entries', 1);

        $updated = $this->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $entry['version'], 'minutes' => 60])->assertOk()->json('data');
        $this->patchJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $entry['version'], 'minutes' => 90])->assertConflict();
        $this->deleteJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/{$entry['id']}", ['expected_version' => $updated['version']])
            ->assertOk()->assertJsonPath('data.deleted_id', $entry['id']);
        $this->assertSoftDeleted('client_time_entries', ['public_id' => $entry['id']]);
    }

    public function test_project_manager_can_approve_but_contributor_cannot(): void
    {
        config(['agent_api.writes_enabled' => true]);
        [$workspace, $project] = $this->project();
        $worker = User::factory()->create();
        $manager = User::factory()->create();
        $this->member($workspace, $worker);
        $this->member($workspace, $manager);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $worker->id, 'role' => 'contributor']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $manager->id, 'role' => 'manager']);
        $entry = ClientTimeEntry::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $project->client_company_id, 'client_project_id' => $project->id, 'user_id' => $worker->id, 'worked_on' => '2026-08-23', 'minutes' => 30, 'description' => 'Review me', 'currency' => 'USD']);
        $this->actingAsAgent($worker, [AgentApiScopes::TIME_APPROVE]);
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]])->assertForbidden();
        $this->actingAsAgent($manager, [AgentApiScopes::TIME_APPROVE]);
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/time-entries/approve", ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry)]]])->assertOk();
        $this->assertDatabaseHas('client_time_entries', ['public_id' => $entry->public_id, 'status' => 'approved']);
    }

    /** @return array{Workspace, ClientProject} */
    private function project(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Mutation Workspace', 'slug' => 'mutation-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Mutation Client', 'slug' => 'mutation-client']);

        return [$workspace, ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Mutation Project'])];
    }

    private function member(Workspace $workspace, User $user): void
    {
        WorkspaceMembership::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => 'member']);
    }

    /** @param list<string> $scopes */
    private function actingAsAgent(User $user, array $scopes): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($user->id), $scopes);
    }
}
