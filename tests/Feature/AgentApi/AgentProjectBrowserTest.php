<?php

namespace Tests\Feature\AgentApi;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AgentProjectBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_supply_canonical_project_versions_and_finished_write_urls(): void
    {
        [$owner, $workspace, $company, $project] = $this->tenant();
        $this->actingAs($owner)->get(route('clients.settings', [$workspace, $company]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('clients/settings')
                ->where('project_create', [
                    'url' => route('agent-api.v1.projects.store', $workspace, absolute: false),
                    'company_id' => $company->public_id,
                    'expected_version' => AgentApiVersion::for($company),
                ])
                ->where('projects.0.id', $project->public_id)
                ->where('projects.0.version', AgentApiVersion::for($project))
                ->where('projects.0.update_url', route('agent-api.v1.projects.update', [$workspace, $project->public_id], absolute: false))
                ->where('projects.0.members_update_url', route('agent-api.v1.projects.members.update', [$workspace, $project->public_id], absolute: false))
                ->missing('projects.0.lock_version'));
    }

    public function test_session_project_writes_replay_and_refuse_stale_and_cross_tenant_changes(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.project_writes_enabled' => true]);
        [$owner, $workspace, $company, $project] = $this->tenant();
        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), str_repeat('p', 40));
        $create = route('agent-api.v1.projects.store', $workspace, absolute: false);
        $body = ['company_id' => $company->public_id, 'expected_version' => AgentApiVersion::for($company), 'confirm' => true, 'name' => 'Synthetic browser-created project'];
        $id = $this->withHeader('Idempotency-Key', 'browser-project-create')->postJson($create, $body)->assertCreated()->json('data.id');
        $this->withHeader('Idempotency-Key', 'browser-project-create')->postJson($create, $body)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('client_projects', 2);
        $update = route('agent-api.v1.projects.update', [$workspace, $project->public_id], absolute: false);
        $oldVersion = AgentApiVersion::for($project);
        $this->withHeader('Idempotency-Key', 'browser-project-edit')->patchJson($update, [
            'name' => 'Synthetic edited project', 'repository' => 'git@github.com:Synthetic/Browser.git',
            'expected_version' => $oldVersion, 'confirm' => true,
        ])->assertOk()->assertJsonPath('data.repository', 'github.com/synthetic/browser');
        $this->withHeader('Idempotency-Key', 'browser-project-stale')->patchJson($update, [
            'name' => 'Stale overwrite', 'expected_version' => $oldVersion, 'confirm' => true,
        ])->assertConflict();
        $access = route('agent-api.v1.projects.members.update', [$workspace, $project->public_id], absolute: false);
        $grant = ['user_id' => $member->public_id, 'role' => 'viewer', 'expected_version' => AgentApiVersion::for($project->fresh()), 'confirm' => true];
        $this->withHeader('Idempotency-Key', 'browser-project-grant')->putJson($access, $grant)->assertOk();
        $this->withHeader('Idempotency-Key', 'browser-project-grant')->putJson($access, $grant)->assertOk();
        $this->assertDatabaseHas('client_project_memberships', ['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $member->id, 'role' => 'viewer']);
        [$foreignOwner, $foreignWorkspace, $foreignCompany, $foreignProject] = $this->tenant();
        $foreignUpdate = route('agent-api.v1.projects.update', [$workspace, $foreignProject->public_id], absolute: false);
        $this->withHeader('Idempotency-Key', 'browser-project-foreign')->patchJson($foreignUpdate, [
            'name' => 'Foreign overwrite', 'expected_version' => AgentApiVersion::for($foreignProject), 'confirm' => true,
        ])->assertNotFound();
        $this->assertSame('Synthetic browser project', $foreignProject->fresh()->name);
        Auth::shouldUse('web');
        $this->actingAs($owner)->get(route('clients.settings', [$workspace, $foreignCompany]))->assertNotFound();
    }

    /** @return array{User, Workspace, ClientCompany, ClientProject} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic browser workspace', 'slug' => 'synthetic-browser-'.str()->random(8)]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic browser company', 'slug' => 'synthetic-browser-'.str()->random(8)]);
        $project = $company->projects()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic browser project']);

        return [$owner, $workspace, $company, $project];
    }
}
