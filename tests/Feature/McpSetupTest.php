<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class McpSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_receive_the_configured_endpoint_and_navigation_link(): void
    {
        config(['app.url' => 'https://svc.example.test/', 'agent_api.mcp_enabled' => true, 'bherila-auth.oauth_server.enabled' => true]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-mcp']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);

        $this->actingAs($user)->get(route('mcp.setup', $workspace))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('mcp-setup')
                ->where('serverUrl', 'https://svc.example.test/api/v1/mcp')
                ->where('available', true)
                ->where('workspaceNavigation.mcp_setup_href', route('mcp.setup', $workspace)));
    }

    public function test_portal_members_can_read_the_guide_but_other_clients_cannot(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Portal Workspace', 'slug' => 'synthetic-portal-mcp']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-mcp-client']);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->actingAs($user)->get(route('portal.mcp.setup', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('mcp-setup')
                ->where('workspaceNavigation.mcp_setup_href', route('portal.mcp.setup', $company)));
        $this->actingAs($foreign)->get(route('portal.mcp.setup', $company))->assertNotFound();
    }

    public function test_a_foreign_workspace_is_not_accessible(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Foreign Workspace', 'slug' => 'synthetic-foreign-mcp']);
        $this->actingAs($user)->get(route('mcp.setup', $workspace))->assertNotFound();
    }

    public function test_guests_must_sign_in(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-guest-mcp']);
        $this->get(route('mcp.setup', $workspace))->assertRedirect(route('login'));
    }

    public function test_the_guide_reports_disabled_mcp(): void
    {
        config(['agent_api.mcp_enabled' => false]);
        $user = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Workspace', 'slug' => 'synthetic-disabled-mcp']);
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => 'owner']);
        $this->actingAs($user)->get(route('mcp.setup', $workspace))->assertInertia(fn (Assert $page) => $page->where('available', false));
    }
}
