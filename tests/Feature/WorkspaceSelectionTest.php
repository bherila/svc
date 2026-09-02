<?php

namespace Tests\Feature;

use App\Http\Controllers\WorkspaceEntryController;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * Signing in, and opening a workspace.
 *
 * Two screens with one job each. `/app` answers "which tenant", and its whole
 * value is what it does *not* carry: the page it replaced loaded every client,
 * project and task of every workspace, so it grew with the account and none of
 * it answered the question being asked. The entry point then answers "which
 * client", and the interesting part is where it refuses to guess.
 *
 * Fixtures are synthetic throughout.
 */
final class WorkspaceSelectionTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    public function test_the_selector_carries_workspaces_and_nothing_beneath_them(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Selector', 'synthetic-selector', $owner);
        $company = $this->company($workspace, 'Synthetic Selector Client', 'selector-client');
        $project = $this->project($workspace, $company, 'Synthetic Selector Project');
        $project->tasks()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Synthetic Selector Task',
            'status' => 'open',
        ]);

        $response = $this->actingAs($owner)->get('/app')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/index')
            ->has('workspaces', 1)
            ->where('workspaces.0.name', 'Synthetic Selector')
            ->where('workspaces.0.enter_href', "/workspaces/{$workspace->public_id}")
            // No nested tree, no counts, no role badge. Each of those was a
            // query paid on every sign-in for something the reader has to
            // scroll past to reach the one link they came for.
            ->missing('workspaces.0.clients')
            ->missing('workspaces.0.role')
            ->missing('workspaces.0.operations_url'));

        $this->assertInertiaPayloadOmits(
            $response,
            ['Synthetic Selector Client', 'Synthetic Selector Project', 'Synthetic Selector Task'],
            'Synthetic Selector',
        );
    }

    /**
     * A portal user holds no workspace membership by design, so reading only
     * that table signed them in and showed them an empty page.
     */
    public function test_a_portal_only_user_can_still_reach_their_workspace(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Portal Entry', 'synthetic-portal-entry', $owner);
        $company = $this->company($workspace, 'Synthetic Portal Entry Client', 'portal-entry-client');
        $client = $this->portalUser($company);

        $this->actingAs($client)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workspaces', 1)
                ->where('workspaces.0.name', 'Synthetic Portal Entry'));
    }

    public function test_a_workspace_reached_through_both_doors_is_listed_once(): void
    {
        $both = User::factory()->create();
        $workspace = $this->workspace('Synthetic Deduped', 'synthetic-deduped', $both);
        $company = $this->company($workspace, 'Synthetic Deduped Client', 'deduped-client');
        ClientCompanyMembership::query()->create([
            'client_company_id' => $company->id,
            'user_id' => $both->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $this->actingAs($both)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('workspaces', 1));
    }

    public function test_another_tenants_workspace_is_absent(): void
    {
        $owner = User::factory()->create();
        $this->workspace('Synthetic Mine Only', 'synthetic-mine-only', $owner);
        Workspace::query()->create(['name' => 'Foreign Selector Tenant', 'slug' => 'foreign-selector']);

        $response = $this->actingAs($owner)->get('/app')->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('workspaces', 1));
        $this->assertInertiaPayloadOmits($response, ['Foreign Selector Tenant'], 'Synthetic Mine Only');
    }

    /**
     * One workspace is still shown rather than entered automatically.
     *
     * Skipping it saves a click once and then removes the only place a second
     * workspace would ever appear - and makes the wordmark, the one intentional
     * way back out, lead to a page that immediately bounces.
     */
    public function test_a_single_workspace_is_still_offered_rather_than_entered(): void
    {
        $owner = User::factory()->create();
        $this->workspace('Synthetic Only', 'synthetic-only', $owner);

        $this->actingAs($owner)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/index'));
    }

    public function test_entering_a_workspace_you_cannot_reach_is_not_found(): void
    {
        $outsider = User::factory()->create();
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Closed', 'synthetic-closed', $owner);

        $this->actingAs($outsider)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertNotFound();
    }

    public function test_one_reachable_client_is_opened_directly(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Single', 'synthetic-single', $owner);
        $company = $this->company($workspace, 'Synthetic Single Client', 'single-client');

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/workspaces/{$workspace->public_id}/clients/{$company->public_id}");
    }

    public function test_the_client_last_opened_is_restored(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Remembered', 'synthetic-remembered', $owner);
        $this->company($workspace, 'Aa Synthetic Remembered Client', 'aa-remembered');
        $second = $this->company($workspace, 'Zz Synthetic Remembered Client', 'zz-remembered');

        // Remembered by visiting it, not by writing the session directly: the
        // point of the key is that it is written only from an id that already
        // passed authorization.
        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}/clients/{$second->public_id}")
            ->assertOk();

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/workspaces/{$workspace->public_id}/clients/{$second->public_id}");
    }

    /**
     * A remembered id outlives the grant that produced it, so it is revalidated
     * rather than trusted - including when it names another tenant's company,
     * which a session carried across workspaces can.
     */
    public function test_a_remembered_client_the_viewer_can_no_longer_open_is_ignored(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Stale', 'synthetic-stale', $owner);
        $this->company($workspace, 'Aa Synthetic Stale Client', 'aa-stale');
        $this->company($workspace, 'Zz Synthetic Stale Client', 'zz-stale');

        $foreign = Workspace::query()->create(['name' => 'Foreign Stale Tenant', 'slug' => 'foreign-stale']);
        $foreignCompany = $this->company($foreign, 'Foreign Stale Client', 'foreign-stale-client');

        $this->actingAs($owner)
            ->withSession([
                WorkspaceEntryController::rememberedClientKey($workspace) => $foreignCompany->public_id,
            ])
            ->get("/workspaces/{$workspace->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/enter'));
    }

    public function test_several_clients_and_no_history_asks_rather_than_guessing(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Choose', 'synthetic-choose', $owner);
        $this->company($workspace, 'Aa Synthetic Choose Client', 'aa-choose');
        $this->company($workspace, 'Zz Synthetic Choose Client', 'zz-choose');

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/enter')
                ->where('has_clients', true)
                ->where('can_create_client', true)
                // The switcher that answers the question is already on screen.
                ->has('workspaceNavigation.clients', 2)
                ->where('workspaceNavigation.current_client_id', null));
    }

    public function test_a_manager_with_no_clients_is_told_to_create_one(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Empty', 'synthetic-empty', $owner);

        $this->actingAs($owner)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/enter')
                ->where('has_clients', false)
                ->where('can_create_client', true));
    }

    /**
     * "None yet" and "none for you" read identically from an empty switcher, so
     * the two are distinguished on the server. A member waiting on access is
     * not offered a button they cannot press.
     */
    public function test_a_member_with_no_reachable_client_gets_a_neutral_empty_state(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Unassigned', 'synthetic-unassigned', $owner);
        $this->company($workspace, 'Synthetic Unassigned Client', 'unassigned-client');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/enter')
                ->where('has_clients', false)
                ->where('can_create_client', false));
    }

    /**
     * Entry is a resolver, not a grant. A portal user who reaches the workspace
     * through it lands on the portal, and picks up no operator authority on the
     * way.
     */
    public function test_a_portal_user_entering_the_workspace_lands_on_the_portal(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Portal Landing', 'synthetic-portal-landing', $owner);
        $company = $this->company($workspace, 'Synthetic Portal Landing Client', 'portal-landing-client');
        $client = $this->portalUser($company);

        $this->actingAs($client)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/portal/{$company->public_id}");

        $this->actingAs($client)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertForbidden();
    }

    /**
     * The scoped member's entry point opens the one client they hold, and the
     * switcher beside it names no other.
     */
    public function test_a_project_scoped_member_enters_the_client_they_hold(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Narrow', 'synthetic-narrow', $owner);
        $reachable = $this->company($workspace, 'Reachable Narrow Client', 'reachable-narrow');
        $this->company($workspace, 'Hidden Narrow Client', 'hidden-narrow');
        $project = $this->project($workspace, $reachable, 'Reachable Narrow Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}")
            ->assertRedirect("/workspaces/{$workspace->public_id}/clients/{$reachable->public_id}");
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'admin']);

        return $workspace;
    }

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function project(Workspace $workspace, ClientCompany $company, string $name): ClientProject
    {
        return ClientProject::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function portalUser(ClientCompany $company): User
    {
        $user = User::factory()->create();
        ClientCompanyMembership::query()->create([
            'client_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $user;
    }
}
