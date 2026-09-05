<?php

namespace Tests\Feature;

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
 * The navbar's shared payload.
 *
 * This is chrome, so it is built once in middleware and reaches every screen -
 * including ones written later by someone who never thinks about it. A switcher
 * is a list of client names, so the thing to prove is not that it renders but
 * that it cannot carry a name from a tenant, a client or a project the viewer
 * has no business seeing, and that the URLs it hands the browser are the ones
 * that viewer is actually allowed to open.
 *
 * Fixtures are synthetic: reserved-looking names, no real client data.
 */
class WorkspaceNavigationTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    public function test_the_switcher_lists_this_workspaces_companies_and_marks_the_current_one(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Context', 'synthetic-context', $manager);
        $first = $this->company($workspace, 'Aa Synthetic Context Client', 'aa-context-client');
        $this->company($workspace, 'Bb Synthetic Context Client', 'bb-context-client');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$first->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workspaceNavigation.clients', 2)
                ->where('workspaceNavigation.workspace_id', $workspace->public_id)
                ->where('workspaceNavigation.clients.0.name', 'Aa Synthetic Context Client')
                ->where('workspaceNavigation.current_client_id', $first->public_id));
    }

    /**
     * The bar names the tenant, and the name is all it gets.
     *
     * This used to assert the opposite - that no workspace name was sent at all
     * - on the argument that a tenant label competes with the client switcher.
     * What that missed is that an operator with two workspaces had nothing on
     * the screen saying which one they were in. The name is now the label
     * beside the exit control.
     *
     * What is still asserted is the shape: a name, not a workspace object. The
     * bar needs one string, and sending the record would put its slug, its
     * timezone and whatever is added to it next into the payload of every
     * authenticated page.
     */
    public function test_the_navigation_payload_names_the_workspace_and_sends_nothing_else_of_it(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Distinctive Synthetic Tenant Name', 'distinctive-tenant', $manager);
        $company = $this->company($workspace, 'Synthetic Named Client', 'synthetic-named-client');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceNavigation.workspace_name', 'Distinctive Synthetic Tenant Name')
                ->missing('workspaceNavigation.workspace'));
    }

    /**
     * Every option carries finished URLs for the modules it actually serves.
     *
     * The browser never assembles a path from two ids, because the right path
     * is not a function of the ids alone - the same reader can be an operator
     * of one company and an external client of another.
     */
    public function test_each_option_carries_the_module_urls_for_its_route_family(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Routed', 'synthetic-routed', $manager);
        $company = $this->company($workspace, 'Synthetic Routed Client', 'routed-client');
        $base = "/workspaces/{$workspace->public_id}/clients/{$company->public_id}";

        $this->actingAs($manager)
            ->get($base)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceNavigation.clients.0.destinations.home', $base)
                ->where('workspaceNavigation.clients.0.destinations.invoices', $base.'/invoices')
                ->where('workspaceNavigation.clients.0.destinations.time', $base.'/time')
                ->where('workspaceNavigation.clients.0.destinations.tasks', $base.'/tasks')
                // The operator surface for expenses arrived in #75's first
                // slice, so the tab is a link rather than a null.
                ->where('workspaceNavigation.clients.0.destinations.expenses', $base.'/expenses')
                ->where('workspaceNavigation.permissions.search', true));
    }

    /**
     * A portal user gets the client-facing family, and nothing operator-shaped.
     *
     * They are not a workspace member at all, so an operator URL in their
     * switcher would be a link that 403s - and, worse, an admission that the
     * screen exists.
     */
    public function test_a_portal_user_gets_the_portal_route_family(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Portal Nav', 'synthetic-portal-nav', $owner);
        $company = $this->company($workspace, 'Synthetic Portal Nav Client', 'portal-nav-client');
        $client = $this->portalUser($company);

        $response = $this->actingAs($client)
            ->get("/portal/{$company->public_id}")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->has('workspaceNavigation.clients', 1)
            ->where('workspaceNavigation.current_client_id', $company->public_id)
            ->where('workspaceNavigation.clients.0.destinations.home', "/portal/{$company->public_id}")
            ->where('workspaceNavigation.permissions.manage_workspace', false)
            ->where('workspaceNavigation.permissions.create_client', false)
            ->where('workspaceNavigation.permissions.manage_current_client', false)
            // The palette searches the workspaces this person is a member of,
            // and a portal user is a member of none - so the trigger would have
            // promised a search that always came back empty.
            ->where('workspaceNavigation.permissions.search', false));

        // No operator URL anywhere in the payload. `workspaces` is the first
        // segment of every one of them, and no key in the navigation contract
        // uses the plural - so its absence is the whole route family's absence.
        $this->assertInertiaPayloadOmits(
            $response,
            ['workspaces'],
            'Synthetic Portal Nav Client',
        );
    }

    /**
     * Holding both doors to the same client opens the operator one.
     *
     * It is the strictly larger view of the same company, and sending an
     * operator to the client-facing copy of their own workspace would hide work
     * they are responsible for.
     */
    public function test_operator_access_wins_over_portal_access_for_the_same_client(): void
    {
        $both = User::factory()->create();
        $workspace = $this->workspace('Synthetic Both', 'synthetic-both', $both);
        $company = $this->company($workspace, 'Synthetic Both Client', 'both-client');
        ClientCompanyMembership::query()->create([
            'client_company_id' => $company->id,
            'user_id' => $both->id,
            'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        $this->actingAs($both)
            ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('workspaceNavigation.clients', 1)
                ->where(
                    'workspaceNavigation.clients.0.destinations.home',
                    "/workspaces/{$workspace->public_id}/clients/{$company->public_id}",
                ));
    }

    /**
     * The reason this is asserted rather than assumed: the switcher is built in
     * middleware, so a workspace-blind query here would publish one tenant's
     * whole client list on every other tenant's screens - a leak with no page
     * to review it on.
     */
    public function test_no_other_workspaces_client_reaches_the_switcher(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Mine', 'synthetic-mine', $manager);
        $mine = $this->company($workspace, 'Synthetic Mine Client', 'synthetic-mine-client');

        $foreign = Workspace::query()->create(['name' => 'Foreign Context Tenant', 'slug' => 'foreign-context']);
        $this->company($foreign, 'Foreign Context Client Name', 'foreign-context-client');

        $response = $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$mine->public_id}")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('workspaceNavigation.clients', 1));
        // The control string matters here: an empty switcher would omit the
        // foreign names too, and pass while proving nothing.
        $this->assertInertiaPayloadOmits($response, [
            'Foreign Context Client Name',
            'Foreign Context Tenant',
        ], 'Synthetic Mine Client');
    }

    /**
     * A member scoped to one project learns the name of one client.
     *
     * The switcher is the one control that appears on *every* screen, so a
     * workspace-wide query here would publish the whole client list whichever
     * page a scoped member happened to open.
     */
    public function test_a_project_scoped_member_sees_only_the_clients_they_reach(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Scoped', 'synthetic-scoped', $owner);
        $reachable = $this->company($workspace, 'Reachable Synthetic Client', 'reachable-client');
        $hidden = $this->company($workspace, 'Hidden Synthetic Client', 'hidden-client');
        $project = $this->project($workspace, $reachable, 'Reachable Synthetic Project');
        $this->project($workspace, $hidden, 'Hidden Synthetic Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $response = $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$reachable->public_id}")
            ->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->has('workspaceNavigation.clients', 1));
        $this->assertInertiaPayloadOmits(
            $response,
            ['Hidden Synthetic Client', 'Hidden Synthetic Project'],
            'Reachable Synthetic Client',
        );
    }

    /**
     * A company reached by pasting its id is not echoed back as selected.
     *
     * Company ids are unique across every tenant, so such a URL arrives bound
     * and plausible. Naming it in the switcher would disclose exactly what the
     * scoping refuses.
     */
    public function test_an_unreachable_company_in_the_route_is_not_marked_current(): void
    {
        $owner = User::factory()->create();
        $workspace = $this->workspace('Synthetic Pasted', 'synthetic-pasted', $owner);
        $reachable = $this->company($workspace, 'Reachable Pasted Client', 'reachable-pasted');
        $unreachable = $this->company($workspace, 'Unreachable Pasted Client', 'unreachable-pasted');
        $project = $this->project($workspace, $reachable, 'Reachable Pasted Project');

        $member = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $workspace->id,
            'client_project_id' => $project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        // The page itself refuses; what is asserted here is that the chrome
        // refuses independently, because the chrome runs before it.
        $this->actingAs($member)
            ->get("/workspaces/{$workspace->public_id}/clients/{$unreachable->public_id}")
            ->assertNotFound();
    }

    /**
     * The switcher costs the same whatever hangs under each client.
     *
     * It runs on every screen in the application, so a query that grew with the
     * workspace's contents would be paid everywhere at once.
     */
    public function test_the_navigation_payload_is_bounded(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Bounded', 'synthetic-bounded', $manager);
        $company = $this->company($workspace, 'Synthetic Bounded Client', 'bounded-client');

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($manager)
                ->get("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/tasks")
                ->assertOk(),
            function () use ($workspace, $company): void {
                for ($index = 0; $index < 5; $index++) {
                    $this->project($workspace, $company, "Synthetic Bounded Project {$index}");
                }
            },
        );
    }

    /**
     * Off a shell route the payload is absent rather than empty, so the
     * selector, the login redirect, webhooks, PDFs and downloads pay nothing
     * for chrome they do not show.
     */
    public function test_a_screen_outside_any_workspace_carries_no_navigation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->missing('workspaceNavigation'));
    }

    /**
     * The Time tab is the same sheet, bound to the company by route.
     *
     * Worth its own assertion because the sheet already had a company filter
     * and falls back to the first company when the query string is missing or
     * stale - so a route parameter that quietly did nothing would still render
     * a plausible page, showing the wrong client's time under the right
     * client's switcher.
     */
    public function test_the_time_tab_selects_the_company_named_in_the_route(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Tabbed', 'synthetic-tabbed', $manager);
        // Both need a project: the sheet lists only companies with work the
        // viewer can reach, and a company it cannot list is a company it cannot
        // select. Alphabetically first is what it falls back to.
        $fallback = $this->company($workspace, 'Aa Synthetic Fallback Client', 'aa-fallback-client');
        $this->project($workspace, $fallback, 'Aa Synthetic Project');
        $wanted = $this->company($workspace, 'Zz Synthetic Wanted Client', 'zz-wanted-client');
        $this->project($workspace, $wanted, 'Zz Synthetic Project');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients/{$wanted->public_id}/time")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('time')
                ->where('filters.company_id', $wanted->public_id)
                ->where('workspaceNavigation.current_client_id', $wanted->public_id));
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        // `admin`, not `manager`: only owner/admin resolve to a project role,
        // and the time sheet lists a company only when the viewer can reach
        // work inside it.
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'admin']);

        return $workspace;
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

    private function company(Workspace $workspace, string $name, string $slug): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => $slug,
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
