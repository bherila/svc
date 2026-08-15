<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EngagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('svc.engagement.proposals.store')) {
            require base_path('routes/engagement.php');
        }
    }

    public function test_accepting_a_visible_sent_proposal_materializes_an_idempotent_agreement(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);

        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/proposals", [
            'title' => 'Synthetic support plan',
            'summary' => 'A synthetic summary.',
            'terms' => 'A synthetic set of terms.',
            'valid_until' => '2026-12-31',
            'currency' => 'USD',
            'is_visible_to_client' => true,
            'items' => [[
                'description' => 'Monthly support',
                'quantity' => '2.000',
                'unit_amount' => 12500,
                'cadence' => 'monthly',
                'sort_order' => 0,
            ]],
        ])->assertCreated();

        $proposal = ClientProposal::query()->sole();
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/proposals/{$proposal->public_id}/send")
            ->assertOk();

        $acceptPath = "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept";
        $this->actingAs($clientUser)->postJson($acceptPath, [
            'signer_name' => 'Synthetic Signer',
            'signer_title' => 'Synthetic Buyer',
        ])->assertOk();

        $this->actingAs($clientUser)->postJson($acceptPath, [
            'signer_name' => 'Different Replay Value',
            'signer_title' => 'Ignored Replay Value',
        ])->assertOk();

        $proposal = $proposal->fresh(['agreements.recurringItems']);
        $agreement = ClientAgreement::query()->sole();

        $this->assertSame('accepted', $proposal->status);
        $this->assertSame('Synthetic Signer', $proposal->acceptance_signer_name);
        $this->assertSame('Synthetic support plan', $agreement->title);
        $this->assertSame('active', $agreement->status);
        $this->assertSame($proposal->id, $agreement->source_proposal_id);
        $this->assertSame("A synthetic summary.\n\nA synthetic set of terms.", $agreement->agreement_text);
        $this->assertSame('USD', $agreement->currency);
        $this->assertTrue($agreement->is_visible_to_client);
        $this->assertSame(1, $agreement->recurringItems()->count());
        $this->assertSame(12500, $agreement->recurringItems()->sole()->amount);
        $this->assertNotNull($agreement->signed_at);
        $this->assertSame('Synthetic Signer', $agreement->signer_name);
        $this->assertSame('Synthetic Signer', $proposal->acceptance_signer_name);
        $this->assertSame(1, ClientAgreement::query()->count());

        $this->expectException(\LogicException::class);
        $proposal->update(['title' => 'Attempted mutation']);
    }

    public function test_expired_proposal_cannot_be_accepted(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'title' => 'Expired synthetic proposal',
            'currency' => 'USD',
            'is_visible_to_client' => true,
            'valid_until' => today()->subDay(),
            'status' => 'sent',
            'sent_at' => now()->subDays(2),
        ]);

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertUnprocessable()->assertJsonPath('message', 'This proposal has expired.');

        $this->assertSame('sent', $proposal->fresh()->status);
        $this->assertDatabaseCount('client_agreements', 0);
    }

    public function test_manager_can_activate_and_sign_an_agreement_with_exact_payload_names(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);

        $response = $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/agreements", [
            'title' => 'Synthetic agreement',
            'starts_on' => '2026-08-15',
            'ends_on' => '2027-08-14',
            'agreement_text' => 'Synthetic agreement text.',
            'is_visible_to_client' => true,
            'billing_cadence' => 'annual',
            'currency' => 'USD',
            'hourly_rate_amount' => 18000,
            'retainer_amount' => 72000,
            'retainer_minutes' => 240,
        ])->assertCreated();

        $agreement = ClientAgreement::query()->sole();
        $this->assertSame('Synthetic agreement', $response->json('data.title'));

        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/activate")
            ->assertOk();

        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/sign", [
            'signer_name' => 'Synthetic Owner',
        ])->assertOk();

        $agreement = $agreement->fresh();
        $this->assertSame('active', $agreement->status);
        $this->assertSame(18000, $agreement->hourly_rate_amount);
        $this->assertNotNull($agreement->signed_at);
    }

    /** @return array{0: Workspace, 1: ClientCompany} */
    private function clientFor(User $owner, ?User $clientUser = null): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Engagement Workspace', 'slug' => 'synthetic-engagement-workspace-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Synthetic Engagement Client',
            'slug' => 'synthetic-engagement-client-'.uniqid(),
        ]);

        if ($clientUser !== null) {
            $company->portalUsers()->attach($clientUser, ['role' => 'client']);
        }

        return [$workspace, $company];
    }
}
