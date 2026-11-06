<?php

namespace Tests\Feature\Submission;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Support\Workspaces\WorkspaceEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsSharedProjects;
use Tests\TestCase;

/**
 * The partner's light workspace, made when a person with no workspace accepts
 * the host's invitation (docs/ux-overhaul.md section 7e).
 */
final class LightWorkspaceTest extends TestCase
{
    use BuildsSharedProjects;
    use RefreshDatabase;

    /** @return array<string, int> */
    private function hostFootprint(Workspace $host): array
    {
        $counts = [];
        foreach (['client_companies', 'client_projects', 'client_tasks', 'client_time_entries', 'client_expenses', 'client_invoices', 'client_agreements', 'client_comments', 'client_attachments', 'workspace_memberships', 'client_project_memberships', 'client_task_assignments'] as $table) {
            $counts[$table] = DB::table($table)->where('workspace_id', $host->id)->count();
        }

        return $counts;
    }

    private function invite(string $email): string
    {
        $this->sharedWorld();

        return app(SharedProjectParticipations::class)->invite($this->world['hostOwner'], $this->world['host'], $this->world['project']->public_id, $email)->public_id;
    }

    public function test_accepting_without_a_workspace_makes_a_light_one_that_owns_nothing_of_the_hosts(): void
    {
        $partner = User::factory()->create(['name' => 'Olive Other', 'email' => 'olive@partner.example.test']);
        $invitation = $this->invite('olive@partner.example.test');
        $host = $this->world['host'];
        $before = $this->hostFootprint($host);

        $this->actingAs($partner)->get("/shared-project-invitations/{$invitation}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('creates_light_workspace', true)->where('workspaces', []));
        $response = $this->actingAs($partner)->postJson("/shared-project-invitations/{$invitation}/accept", ['client_name' => 'Synthetic Partner Client', 'workspace_name' => 'Olive Engineering'])->assertOk();

        $workspace = Workspace::query()->where('name', 'Olive Engineering')->firstOrFail();
        $this->assertSame("/workspaces/{$workspace->public_id}/my-work", $response->json('data.redirect_url'));
        $this->assertSame('light', $workspace->edition);
        $this->assertSame(['owner'], $workspace->memberships()->pluck('role')->all());
        $this->assertSame($partner->id, $workspace->memberships()->value('user_id'));

        // Everything it holds is its own: one client the person named, one linked project.
        $companies = ClientCompany::query()->where('workspace_id', $workspace->id)->get();
        $this->assertSame(['Synthetic Partner Client'], $companies->pluck('name')->all());
        $local = ClientProject::query()->where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $local);
        $this->assertSame('Synthetic Shared Project', $local[0]->name);
        $this->assertNotNull($local[0]->shared_participation_id);
        $this->assertSame($companies[0]->id, $local[0]->client_company_id);
        foreach (['client_tasks', 'client_time_entries', 'client_expenses', 'client_invoices', 'client_agreements', 'client_comments', 'client_attachments'] as $table) {
            $this->assertSame(0, DB::table($table)->where('workspace_id', $workspace->id)->count(), "{$table} starts empty");
        }

        // The host's own records are exactly as they were, and none of its words were copied.
        $this->assertSame($before, $this->hostFootprint($host));
        $this->assertSame(0, ClientCompany::query()->where('workspace_id', $workspace->id)->where('name', 'SECRET-HOST-CLIENT-NAME')->count());
        $this->assertSame($before['workspace_memberships'], $host->memberships()->count(), 'The partner is not on the host team.');
        $this->assertFalse($host->memberships()->where('user_id', $partner->id)->exists());
        $this->assertTrue(AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'workspace.light_created')->exists());

        $this->assertSame(1, DB::table('client_project_participations')->where('participant_workspace_id', $workspace->id)->whereNull('revoked_at')->count());
    }

    public function test_a_person_who_already_has_a_workspace_must_choose_it(): void
    {
        $existing = $this->person($this->workspace('Synthetic Existing Co'), 'owner', 'Eve Existing');
        $invitation = $this->invite((string) $existing->email);

        $this->actingAs($existing)->postJson("/shared-project-invitations/{$invitation}/accept", ['client_name' => 'Synthetic Client'])->assertStatus(422);
        $this->assertSame(0, Workspace::query()->where('edition', 'light')->count());
    }

    public function test_the_client_name_is_required_and_another_person_cannot_accept(): void
    {
        $partner = User::factory()->create(['email' => 'olive@partner.example.test']);
        $stranger = User::factory()->create(['email' => 'stranger@partner.example.test']);
        $invitation = $this->invite('olive@partner.example.test');

        $this->actingAs($partner)->postJson("/shared-project-invitations/{$invitation}/accept", [])->assertStatus(422);
        $this->actingAs($stranger)->postJson("/shared-project-invitations/{$invitation}/accept", ['client_name' => 'Synthetic Client'])->assertNotFound();
        $this->assertSame(0, Workspace::query()->where('edition', 'light')->count());
    }

    public function test_a_light_workspace_opens_on_my_work_with_no_billing_in_its_navigation(): void
    {
        $partner = User::factory()->create(['name' => 'Olive Other', 'email' => 'olive@partner.example.test']);
        $invitation = $this->invite('olive@partner.example.test');
        $this->actingAs($partner)->postJson("/shared-project-invitations/{$invitation}/accept", ['client_name' => 'Synthetic Partner Client'])->assertOk();
        $workspace = Workspace::query()->where('edition', 'light')->firstOrFail();

        $this->actingAs($partner)->get("/workspaces/{$workspace->public_id}")->assertRedirect("/workspaces/{$workspace->public_id}/my-work");
        $this->actingAs($partner)->get("/workspaces/{$workspace->public_id}/my-work")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspaceNavigation.light', true)
                ->where('workspaceNavigation.workspace_destinations.dashboard', null)
                ->where('workspaceNavigation.workspace_destinations.invoices', null)
                ->where('workspaceNavigation.workspace_destinations.finance', null)
                ->where('workspaceNavigation.workspace_destinations.time', null)
                ->where('workspaceNavigation.workspace_destinations.my_work', "/workspaces/{$workspace->public_id}/my-work")
                ->where('workspaceNavigation.workspace_destinations.submissions', "/workspaces/{$workspace->public_id}/submissions")
                ->where('workspaceNavigation.workspace_destinations.timesheet', "/workspaces/{$workspace->public_id}/timesheet")
                ->where('workspaceNavigation.clients.0.destinations.agreements', null)
                ->where('workspaceNavigation.clients.0.destinations.invoices', null)
                ->where('light.client', 'Synthetic Partner Client')
                ->where('light.shared_project.name', 'Synthetic Shared Project')
                ->where('light.shared_project.host_workspace_name', 'Synthetic Host Studio')
                ->where('light.full_workspace_url', "/workspaces/{$workspace->public_id}/edition/full"));
    }

    public function test_its_owner_can_turn_on_the_full_workspace_and_nobody_else_can(): void
    {
        $partner = User::factory()->create(['email' => 'olive@partner.example.test']);
        $invitation = $this->invite('olive@partner.example.test');
        $this->actingAs($partner)->postJson("/shared-project-invitations/{$invitation}/accept", ['client_name' => 'Synthetic Partner Client'])->assertOk();
        $workspace = Workspace::query()->where('edition', 'light')->firstOrFail();

        $this->actingAs($this->world['hostOwner'])->post("/workspaces/{$workspace->public_id}/edition/full")->assertNotFound();
        $this->assertSame('light', $workspace->fresh()->edition);

        $this->actingAs($partner)->post("/workspaces/{$workspace->public_id}/edition/full")->assertRedirect("/workspaces/{$workspace->public_id}");
        $this->assertSame('full', $workspace->fresh()->edition);
        $this->assertTrue(AccessAuditEvent::query()->where('workspace_id', $workspace->id)->where('action', 'workspace.edition_changed')->exists());
        $this->actingAs($partner)->get("/workspaces/{$workspace->public_id}/my-work")
            ->assertInertia(fn (Assert $page) => $page->where('light', null)->where('workspaceNavigation.light', false)->where('workspaceNavigation.workspace_destinations.finance', "/workspaces/{$workspace->public_id}/finance"));
    }

    public function test_a_full_workspace_is_unchanged(): void
    {
        $this->sharedWorld();
        $owner = $this->world['hostOwner'];

        $this->actingAs($owner)->get('/workspaces/'.$this->world['host']->public_id.'/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workspaceNavigation.light', false)->where('workspaceNavigation.full_workspace_url', null));
        $this->assertSame(WorkspaceEdition::Full->value, $this->world['host']->fresh()->edition);
    }
}
