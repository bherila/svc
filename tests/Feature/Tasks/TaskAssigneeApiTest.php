<?php

namespace Tests\Feature\Tasks;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;

final class TaskAssigneeApiTest extends TestCase
{
    use CallsMcp;
    use RefreshDatabase;

    public function test_rest_mcp_and_cli_share_bounded_staff_identity_search(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $eligible = $this->staff($workspace, $project, 'Synthetic eligible staff');
        $ungranted = User::factory()->create(['name' => 'Synthetic ungranted']);
        $workspace->memberships()->create(['user_id' => $ungranted->id, 'role' => 'member']);
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read']);
        $expected = ['data' => [['id' => $eligible->public_id, 'name' => $eligible->name]], 'has_more' => false];
        $this->getJson($this->href($workspace, $project).'?q=eligible')->assertOk()->assertExactJson($expected);
        $rpc = $this->callTool($this->initialize(), 'tasks.assignees.list', ['workspace_id' => $workspace->public_id, 'project_id' => $project->public_id, 'q' => 'eligible']);
        $this->assertSame($expected, $rpc['result']['structuredContent']);
        $this->assertSame(0, Artisan::call('svc:tasks', ['operation' => 'assignees', 'workspace' => $workspace->public_id, '--actor' => $owner->public_id, '--project' => $project->public_id, '--q' => 'eligible', '--format' => 'json']));
        $this->assertSame($expected, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
        $this->getJson($this->href($workspace, $project).'?q='.str_repeat('x', 101))->assertUnprocessable();
        $this->getJson($this->href($workspace, $project).'?q[]=x')->assertUnprocessable();
        $this->assertFalse(Route::has('tasks.assignees'));
        $this->getJson('/workspaces/'.$workspace->public_id.'/projects/'.$project->public_id.'/task-assignees')->assertNotFound();
    }

    public function test_foreign_workspace_and_project_never_supply_options(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        [$foreign, , $foreignProject] = $this->fixture();
        $this->actingAsMcp($owner, ['mcp:use', 'tasks:read']);
        $this->getJson($this->href($workspace, $foreignProject))->assertNotFound();
        $this->getJson($this->href($foreign, $foreignProject))->assertNotFound();
        $outsider = User::factory()->create();
        $this->actingAsMcp($outsider, ['tasks:read']);
        $this->getJson($this->href($workspace, $project))->assertNotFound();
    }

    public function test_portal_and_dual_agent_are_refused_but_trusted_internal_dual_manager_is_allowed(): void
    {
        [$workspace, $company, $project, $owner] = $this->fixture();
        $portal = User::factory()->create();
        foreach ([$portal, $owner] as $person) {
            ClientCompanyMembership::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'user_id' => $person->id, 'role' => 'client']);
            $this->actingAsMcp($person, ['tasks:read']);
            $this->getJson($this->href($workspace, $project).'?origin=internal')->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'web')->withCredentials()->withUnencryptedCookie(config('session.cookie'), Str::random(40));
        $this->getJson($this->href($workspace, $project))->assertOk()->assertExactJson(['data' => [['id' => $owner->public_id, 'name' => $owner->name]], 'has_more' => false]);
        $workspace->memberships()->where('user_id', $owner->id)->delete();
        $this->getJson($this->href($workspace, $project))->assertForbidden();
    }

    public function test_project_management_and_read_scope_are_both_required(): void
    {
        [$workspace, , $project, $owner] = $this->fixture();
        $staff = $this->staff($workspace, $project, 'Synthetic contributor');
        $this->actingAsMcp($staff, ['tasks:read']);
        $this->getJson($this->href($workspace, $project))->assertForbidden();
        ClientProjectMembership::query()->where('user_id', $staff->id)->update(['role' => 'manager']);
        $this->getJson($this->href($workspace, $project))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAsMcp($owner, ['projects:read']);
        $this->getJson($this->href($workspace, $project))->assertForbidden();
    }

    private function href(Workspace $workspace, ClientProject $project): string
    {
        return route('agent-api.v1.tasks.assignees.index', [$workspace, $project->public_id], false);
    }

    private function staff(Workspace $workspace, ClientProject $project, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);
        ClientProjectMembership::query()->create(['workspace_id' => $workspace->id, 'client_project_id' => $project->id, 'user_id' => $user->id, 'role' => 'contributor']);

        return $user;
    }

    /** @return array{Workspace,ClientCompany,ClientProject,User} */
    private function fixture(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic assignee lookup', 'slug' => 'synthetic-'.Str::uuid()]);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-'.Str::uuid()]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic project']);
        $owner = User::factory()->create(['name' => 'Synthetic owner']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$workspace, $company, $project, $owner];
    }
}
