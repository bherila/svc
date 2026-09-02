<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
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
        $this->assertSame(3, ClientCompanyActivity::query()->count());
        $this->assertSame(
            ['agreement.created', 'agreement.activated', 'agreement.signed'],
            ClientCompanyActivity::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertSame(
            [$agreement->public_id],
            ClientCompanyActivity::query()->pluck('subject_public_id')->unique()->values()->all(),
        );
        $this->assertSame(
            [$clientUser->id],
            ClientCompanyActivity::query()->pluck('actor_user_id')->unique()->values()->all(),
        );

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
        $this->assertSame(
            ['agreement.created', 'agreement.activated', 'agreement.signed'],
            ClientCompanyActivity::query()->orderBy('id')->pluck('action')->all(),
        );
        $this->assertTrue(ClientCompanyActivity::query()->get()->every(
            fn (ClientCompanyActivity $activity): bool => $activity->workspace_id === $workspace->id
                && $activity->client_company_id === $company->id
                && $activity->actor_user_id === $owner->id
                && $activity->subject_public_id === $agreement->public_id,
        ));
    }

    public function test_paused_agreement_can_be_reactivated_with_a_distinct_activity_occurrence(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $workflow = app(AgreementWorkflow::class);
        $agreement = $workflow->create($workspace, $company, null, null, [
            'title' => 'Synthetic reactivation agreement',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        $workflow->activate($agreement);
        $agreement->forceFill(['status' => 'paused'])->save();
        $workflow->activate($agreement->fresh());

        $activations = ClientCompanyActivity::query()
            ->where('action', 'agreement.activated')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $activations);
        $this->assertSame('draft', $activations[0]->payload['changes']['status']['old']);
        $this->assertSame('paused', $activations[1]->payload['changes']['status']['old']);
        $this->assertNotSame($activations[0]->deduplication_key, $activations[1]->deduplication_key);
        $this->assertSame('active', $agreement->fresh()->status);
    }

    /**
     * A null `activated_at` is what admits the stamp; a set one preserves it.
     *
     * Activation writes `activated_at ?? now()`, so the null is the only state
     * in which the timestamp moves. Reactivating a paused agreement must leave
     * the original date alone - it is when the client's terms took effect, and
     * rewriting it forward on every pause would move the start of the billing
     * relationship each time somebody toggled the status.
     */
    public function test_only_an_unstamped_agreement_takes_an_activation_date(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $workflow = app(AgreementWorkflow::class);
        $agreement = $workflow->create($workspace, $company, null, null, [
            'title' => 'Synthetic activation agreement',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        $this->assertNull($agreement->activated_at, 'A draft has not been activated');

        $this->travelTo('2026-08-15 09:00:00');
        $first = $workflow->activate($agreement)->activated_at;
        $this->assertNotNull($first);

        $agreement->fresh()->forceFill(['status' => 'paused'])->save();

        $this->travelTo('2026-09-20 09:00:00');
        $workflow->activate($agreement->fresh());

        $this->assertTrue(
            $first->equalTo($agreement->fresh()->activated_at),
            'Reactivation keeps the date the agreement first took effect',
        );
    }

    /**
     * A null `signed_at` is what admits a signature; a set one closes it.
     *
     * The workflow returns early when the column is already stamped, so a
     * second signing is a no-op rather than an overwrite. Without that, a
     * replayed request would rewrite the signatory and the date recorded
     * against a live agreement, and record a second signing activity for a
     * signature that happened once.
     */
    public function test_only_an_unsigned_agreement_can_be_signed(): void
    {
        $owner = User::factory()->create();
        $second = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $workspace->memberships()->create(['user_id' => $second->id, 'role' => 'admin']);
        $workflow = app(AgreementWorkflow::class);
        $agreement = $workflow->activate($workflow->create($workspace, $company, null, null, [
            'title' => 'Synthetic signing agreement',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]));

        $this->assertNull($agreement->signed_at, 'Nobody has signed yet');

        $signed = $workflow->sign($agreement, $owner, 'First Signer', 'Owner');
        $this->assertNotNull($signed->signed_at);

        $again = $workflow->sign($agreement->fresh(), $second, 'Second Signer', 'Impostor');

        $this->assertSame('First Signer', $again->signer_name);
        $this->assertSame($owner->id, $again->signed_by_user_id);
        $this->assertTrue($signed->signed_at->equalTo($again->signed_at));
        $this->assertSame(
            1,
            ClientCompanyActivity::query()->where('action', 'agreement.signed')->count(),
            'One signature, one activity',
        );
    }

    /** @return array{0: Workspace, 1: ClientCompany} */
    public function test_plain_workspace_member_cannot_accept_a_proposal_on_the_clients_behalf(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        $member = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/proposals", [
            'title' => 'Synthetic member-gate plan',
            'currency' => 'USD',
            'is_visible_to_client' => true,
            'items' => [['description' => 'Support', 'quantity' => '1.000', 'unit_amount' => 10000, 'cadence' => 'monthly', 'sort_order' => 0]],
        ])->assertCreated();
        $proposal = ClientProposal::query()->sole();
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/proposals/{$proposal->public_id}/send")->assertOk();

        $acceptPath = "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept";
        $this->actingAs($member)->postJson($acceptPath, [
            'signer_name' => 'Forged Signer',
        ])->assertForbidden();
        $this->assertSame('sent', $proposal->fresh()->status);
        $this->assertSame(0, ClientAgreement::query()->count());

        // Owner/admin staff may still record an offline acceptance; the client's
        // own portal user path is pinned by the acceptance test above.
        $this->actingAs($owner)->postJson($acceptPath, [
            'signer_name' => 'Recorded Offline Signer',
        ])->assertOk();
        $this->assertSame('accepted', $proposal->fresh()->status);
    }

    /**
     * A null `source_proposal_id` hides an existing agreement from acceptance,
     * and acceptance writes a second one.
     *
     * This pins a defect rather than a guarantee, deliberately. #148 established
     * that the fix cannot live inside `accept()`: the only evidence tying an
     * agreement to a proposal is the link that is missing, and matching by
     * company, title or date inside a write path would trade a duplicate for a
     * mis-attribution - a worse error, and one nobody would notice. So the
     * repair is to restore the links, sized by
     * `svc:engagement:audit-unlinked-proposal-agreements`, and until that lands
     * this is what the code does.
     *
     * Written as an assertion so it fails the moment the behaviour changes. A
     * defect that is understood and left in place should be a failing test's
     * worth of noise to alter, not a silent surprise - and the registry entry
     * for `client_agreements.source_proposal_id` names `accept()` as a live
     * reader of the null, which #143 requires be pinned by something.
     */
    public function test_an_agreement_whose_proposal_link_is_missing_does_not_stop_a_second_being_created(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);

        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/proposals", [
            'title' => 'Synthetic support plan',
            'currency' => 'USD',
            'is_visible_to_client' => true,
            'items' => [[
                'description' => 'Monthly support',
                'quantity' => '1.000',
                'unit_amount' => 10000,
                'cadence' => 'monthly',
                'sort_order' => 0,
            ]],
        ])->assertCreated();

        $proposal = ClientProposal::query()->sole();
        $this->actingAs($owner)->postJson("/workspaces/{$workspace->public_id}/proposals/{$proposal->public_id}/send")
            ->assertOk();

        // The agreement this proposal really produced, with its link lost - the
        // state the importer reaches when the source proposal does not resolve.
        $existing = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic support plan',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        // The audit names this proposal while it is still preventable. After
        // acceptance it looks sound - the second agreement carries the link the
        // first one lost - which is precisely why the warning has to be read
        // before anyone accepts and not after.
        $this->assertSame(
            1,
            app(UnlinkedProposalAgreementAuditor::class)->count($workspace)->withAnActiveUnlinkedAgreement,
        );

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer', 'signer_title' => 'Synthetic Buyer'],
        )->assertOk();

        // Two agreements for one engagement, each with its own recurring item,
        // so the client is billed twice every month.
        $this->assertSame(2, ClientAgreement::query()->count());

        $created = ClientAgreement::query()->whereKeyNot($existing->id)->sole();
        $this->assertSame($proposal->id, $created->source_proposal_id);
        $this->assertSame(1, $created->recurringItems()->count());
        $this->assertSame(0, $existing->recurringItems()->count());

        // The pre-existing agreement is untouched: the duplicate is additive,
        // which is why nothing downstream reports a conflict.
        $this->assertSame('active', $existing->fresh()->status);
        $this->assertNull($existing->fresh()->source_proposal_id);

        // And the proposal now looks sound to the audit, because the duplicate
        // it created carries the link. The population shrinks by resolving
        // itself into a second contract - the reason this cannot be measured
        // after the fact.
        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);
        $this->assertSame(0, $counts->withAnActiveUnlinkedAgreement);
        $this->assertSame(0, $counts->acceptedWithoutALinkedAgreement);
    }

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
