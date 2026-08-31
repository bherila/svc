<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * The company switcher's shared payload.
 *
 * This is chrome, so it is shared from the middleware rather than passed by
 * each page - which means it is built once and reaches every screen, including
 * ones written later by someone who never thinks about it. A switcher is a list
 * of client names, so the thing to prove is not that it renders but that it
 * cannot carry a name from a workspace the viewer is not in.
 *
 * Fixtures are synthetic: reserved-looking names, no real client data.
 */
class ClientContextTest extends TestCase
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
                ->has('clientContext.companies', 2)
                ->where('clientContext.workspace.name', 'Synthetic Context')
                ->where('clientContext.companies.0.name', 'Aa Synthetic Context Client')
                ->where('clientContext.current_company_id', $first->public_id));
    }

    /**
     * The list screen is inside a workspace but not inside one company, so the
     * switcher has options and no selection. The layout renders no chrome in
     * that state rather than an empty switcher.
     */
    public function test_a_workspace_screen_outside_a_company_has_options_but_no_selection(): void
    {
        $manager = User::factory()->create();
        $workspace = $this->workspace('Synthetic Unselected', 'synthetic-unselected', $manager);
        $this->company($workspace, 'Synthetic Unselected Client', 'unselected-client');

        $this->actingAs($manager)
            ->get("/workspaces/{$workspace->public_id}/clients")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('clientContext.companies', 1)
                ->where('clientContext.current_company_id', null));
    }

    /**
     * The reason this is asserted rather than assumed: the switcher is built in
     * middleware that runs for every request, so a workspace-blind query here
     * would publish one tenant's whole client list on every other tenant's
     * screens - a leak with no page to review it on.
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

        $response->assertInertia(fn (Assert $page) => $page->has('clientContext.companies', 1));
        // The control string matters here: an empty switcher would omit the
        // foreign names too, and pass while proving nothing.
        $this->assertInertiaPayloadOmits($response, [
            'Foreign Context Client Name',
            'Foreign Context Tenant',
        ], 'Synthetic Mine Client');
    }

    /**
     * Off a workspace route there is nothing to switch between, and the shared
     * prop is absent rather than empty - so the portal and the dashboard pay no
     * query for chrome they do not show.
     */
    public function test_a_screen_outside_any_workspace_carries_no_switcher(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/app')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('clientContext', null));
    }

    private function workspace(string $name, string $slug, User $owner): Workspace
    {
        $workspace = Workspace::query()->create(['name' => $name, 'slug' => $slug]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'manager']);

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
}
