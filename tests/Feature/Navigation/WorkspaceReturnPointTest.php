<?php

namespace Tests\Feature\Navigation;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Navigation\WorkspaceReturnPoint;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coming back to where you were working.
 *
 * The session already remembered the client for as long as it lived. What is
 * new is that it survives the session, which means the memory now outlives the
 * grant that produced it - so every test here is really about the same
 * question: what happens when the remembered place is no longer this person's
 * to enter.
 */
class WorkspaceReturnPointTest extends TestCase
{
    use RefreshDatabase;

    public function test_being_inside_a_client_records_the_workspace_and_the_client(): void
    {
        [$owner, $workspace, $company] = $this->tenant();

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk();

        $owner->refresh();

        $this->assertSame((int) $workspace->id, (int) $owner->last_workspace_id);
        $this->assertSame((int) $company->id, (int) $owner->last_client_company_id);
    }

    public function test_entering_the_workspace_again_lands_in_the_remembered_client(): void
    {
        [$owner, $workspace] = $this->tenant();
        $other = $this->company($workspace, 'Second Synthetic Client', 'second-synthetic');

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}/clients/{$other->public_id}")
            ->assertOk();

        // A fresh session, so nothing is remembered in the cookie: this is the
        // durable record doing the work.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->actingAs($owner->fresh())
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/workspaces/{$workspace->public_id}/clients/{$other->public_id}");
    }

    public function test_a_client_the_viewer_can_no_longer_reach_is_not_returned_to(): void
    {
        [, $workspace, $company] = $this->tenant();
        $reachable = $this->company($workspace, 'Reachable Synthetic Client', 'reachable-synthetic');
        $project = ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $reachable->id,
            'name' => 'Reachable Synthetic Project',
            'slug' => 'reachable-synthetic-project',
            'status' => 'active',
        ]);

        // Written while they could still reach it: the member is an owner here
        // only long enough to record the visit.
        $member = User::factory()->create();
        $membership = $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'owner']);

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk();

        $this->assertSame((int) $company->id, (int) $member->fresh()->last_client_company_id);

        // Now scoped down to one project of a different company. The remembered
        // id is still on the row and still names a real company of this
        // workspace; what changed is that this person may no longer open it.
        $membership->forceFill(['role' => 'member'])->save();
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // The only client they can now reach, rather than the one they left.
        $this->actingAs($member->fresh())
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/workspaces/{$workspace->public_id}/clients/{$reachable->public_id}");
    }

    public function test_signing_in_lands_on_the_remembered_workspace(): void
    {
        [$owner, $workspace] = $this->tenant();
        $owner->forceFill(['last_workspace_id' => $workspace->id])->save();

        $this->assertSame(
            "/workspaces/{$workspace->public_id}",
            app(WorkspaceReturnPoint::class)->landingUrl($owner->fresh()),
        );
    }

    public function test_a_workspace_the_viewer_lost_access_to_sends_them_to_the_selector(): void
    {
        [, $workspace] = $this->tenant();
        $stranger = User::factory()->create();
        // A workspace they were once a member of and no longer are. The column
        // still names it; `nullOnDelete` does not fire, because nothing was
        // deleted - the grant was withdrawn.
        $stranger->forceFill(['last_workspace_id' => $workspace->id])->save();

        $this->assertSame(
            '/app',
            app(WorkspaceReturnPoint::class)->landingUrl($stranger->fresh()),
        );
    }

    public function test_a_user_with_nothing_remembered_lands_on_the_selector(): void
    {
        $user = User::factory()->create();

        $this->assertSame('/app', app(WorkspaceReturnPoint::class)->landingUrl($user));
    }

    /** @return array{0: User, 1: Workspace, 2: ClientCompany} */
    private function tenant(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => 'Synthetic Return Workspace',
            'slug' => 'synthetic-return-workspace-'.uniqid(),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return [$owner, $workspace, $this->company($workspace, 'Synthetic Return Client', 'synthetic-return-client')];
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug.'-'.uniqid(),
        ]);
    }
}
