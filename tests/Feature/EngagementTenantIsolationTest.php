<?php

namespace Tests\Feature;

use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EngagementTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.engagement.proposals.store')) {
            require base_path('routes/engagement.php');
        }
    }

    public function test_time_entry_rejects_a_project_from_another_workspace(): void
    {
        $owner = User::factory()->create();
        [$firstWorkspace, $firstCompany] = $this->workspaceClient($owner, 'first');
        [$secondWorkspace, $secondCompany] = $this->workspaceClient($owner, 'second');
        $project = ClientProject::query()->create([
            'workspace_id' => $secondWorkspace->id,
            'client_company_id' => $secondCompany->id,
            'name' => 'Other Project',
        ]);

        $this->actingAs($owner)->postJson("/workspaces/{$firstWorkspace->public_id}/projects/{$project->public_id}/time-entries", [
            'worked_on' => '2026-08-15',
            'minutes' => 30,
            'description' => 'Cross tenant attempt',
            'is_billable' => true,
            'is_deferred' => false,
            'billing_rate_amount' => 10000,
            'currency' => 'USD',
        ])->assertNotFound();

        $this->assertDatabaseCount('client_time_entries', 0);
        $this->assertSame($firstWorkspace->id, $firstCompany->fresh()->workspace_id);
    }

    public function test_portal_member_can_only_accept_their_companys_visible_proposal(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->workspaceClient($owner, 'first', $clientUser);
        [$otherWorkspace, $otherCompany] = $this->workspaceClient($owner, 'second');

        $visible = ClientProposal::query()->create($this->proposalAttributes($workspace, $company, true, 'Visible proposal'));
        $hidden = ClientProposal::query()->create($this->proposalAttributes($workspace, $company, false, 'Hidden proposal'));
        $other = ClientProposal::query()->create($this->proposalAttributes($otherWorkspace, $otherCompany, true, 'Other proposal'));
        $visible->update(['status' => 'sent', 'sent_at' => now()]);
        $hidden->update(['status' => 'sent', 'sent_at' => now()]);
        $other->update(['status' => 'sent', 'sent_at' => now()]);

        $this->actingAs($clientUser)->postJson("/portal/{$company->public_id}/proposals/{$visible->public_id}/accept", [
            'signer_name' => 'Synthetic Client',
        ])->assertOk();
        $this->actingAs($clientUser)->postJson("/portal/{$company->public_id}/proposals/{$hidden->public_id}/accept", [
            'signer_name' => 'Synthetic Client',
        ])->assertNotFound();
        $this->actingAs($clientUser)->postJson("/portal/{$company->public_id}/proposals/{$other->public_id}/accept", [
            'signer_name' => 'Synthetic Client',
        ])->assertNotFound();

        $this->assertDatabaseHas('client_proposals', ['id' => $visible->id, 'status' => 'accepted']);
        $this->assertDatabaseHas('client_proposals', ['id' => $hidden->id, 'status' => 'sent']);
        $this->assertDatabaseHas('client_proposals', ['id' => $other->id, 'status' => 'sent']);
    }

    /** @return array{0: Workspace, 1: ClientCompany} */
    private function workspaceClient(User $owner, string $suffix, ?User $clientUser = null): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic '.$suffix, 'slug' => 'synthetic-'.$suffix.'-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic '.$suffix.' Client',
            'slug' => 'synthetic-'.$suffix.'-client-'.uniqid(),
        ]);

        if ($clientUser !== null) {
            $company->portalUsers()->attach($clientUser, ['role' => 'client']);
        }

        return [$workspace, $company];
    }

    /** @return array<string, mixed> */
    private function proposalAttributes(Workspace $workspace, ClientCompany $company, bool $visible, string $title): array
    {
        return [
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => $title,
            'currency' => 'USD',
            'is_visible_to_client' => $visible,
            'status' => 'draft',
        ];
    }
}
