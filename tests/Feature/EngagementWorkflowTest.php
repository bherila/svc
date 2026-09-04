<?php

namespace Tests\Feature;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientProject;
use App\Models\ClientProposal;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Engagement\ProposalAcceptanceAgreementQuery;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Engagement\ProposalWorkflow;
use App\Services\Engagement\UnlinkedProposalAgreementAuditor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\WritesLegacyCrossTenantRows;
use Tests\TestCase;

class EngagementWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use WritesLegacyCrossTenantRows;

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
     * A null `source_proposal_id` hides an existing agreement from the ordinary
     * linked-agreement lookup. Acceptance must refuse before it records a
     * signature or creates the second active contract; it cannot safely guess
     * that the unlinked agreement belongs to this proposal.
     */
    public function test_an_active_agreement_whose_proposal_link_is_missing_stops_acceptance(): void
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

        // The agreement this proposal may already have produced, with its link
        // lost. There is no safe evidence here that lets acceptance repair it.
        $existing = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic support plan',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);

        // The auditor and write path share the same query definition, so an
        // operator sees the same population that acceptance refuses.
        $this->assertSame(
            1,
            app(UnlinkedProposalAgreementAuditor::class)->count($workspace)->withAnActiveUnlinkedAgreement,
        );

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer', 'signer_title' => 'Synthetic Buyer'],
        )->assertUnprocessable()->assertExactJson([
            'message' => 'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        ]);

        $proposal->refresh();
        $this->assertSame('sent', $proposal->status);
        $this->assertNull($proposal->accepted_at);
        $this->assertNull($proposal->accepted_by_user_id);
        $this->assertNull($proposal->acceptance_signer_name);
        $this->assertSame(1, ClientAgreement::query()->count());
        $this->assertSame(0, ClientCompanyActivity::query()->count());
        $this->assertSame('active', $existing->fresh()->status);
        $this->assertNull($existing->fresh()->source_proposal_id);
        $this->assertSame(0, $existing->recurringItems()->count());

        // A refusal does not manufacture a clean audit result. The conflict
        // remains visible until an operator resolves the agreement state.
        $counts = app(UnlinkedProposalAgreementAuditor::class)->count($workspace);
        $this->assertSame(1, $counts->withAnActiveUnlinkedAgreement);
        $this->assertSame(0, $counts->acceptedWithoutALinkedAgreement);
    }

    public function test_an_inactive_unlinked_agreement_does_not_block_a_new_acceptance(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic former agreement',
            'status' => 'cancelled',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
        ]);

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertOk();

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame(1, ClientAgreement::query()->where('status', 'active')->count());
        $this->assertSame($proposal->id, ClientAgreement::query()->where('status', 'active')->sole()->source_proposal_id);
    }

    public function test_an_ended_active_unlinked_agreement_does_not_block_acceptance(): void
    {
        $this->travelTo('2026-09-04 12:00:00');
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic ended agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
            'ends_on' => '2026-09-03',
        ]);
        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertOk();

        $this->assertSame('accepted', $proposal->fresh()->status);
        $this->assertSame($proposal->id, ClientAgreement::query()->where('source_proposal_id', $proposal->id)->sole()->source_proposal_id);
    }

    public function test_a_future_active_unlinked_agreement_blocks_the_open_ended_acceptance_term(): void
    {
        $this->travelTo('2026-09-04 12:00:00');
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic future agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-09-05',
            'ends_on' => null,
        ]);

        $this->assertSame(
            1,
            app(UnlinkedProposalAgreementAuditor::class)->count($workspace)->withAnActiveUnlinkedAgreement,
        );

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertUnprocessable()->assertExactJson([
            'message' => 'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        ]);

        $this->assertSame('sent', $proposal->fresh()->status);
        $this->assertDatabaseCount('client_agreements', 1);
        $this->assertDatabaseCount('client_company_activity', 0);
    }

    public function test_acceptance_locks_the_company_before_loading_the_workspace_calendar(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $proposal = $this->sentProposal($workspace, $company, $owner);
        $proposal->forceFill(['valid_until' => '2026-12-31'])->save();

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        app(ProposalWorkflow::class)->accept($proposal, $owner, 'Synthetic Signer', null);

        $companyLock = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'client_companies'));
        $workspaceCalendar = array_find_key($statements, static fn (string $sql): bool => str_contains($sql, 'workspaces'));

        $this->assertIsInt($companyLock);
        $this->assertIsInt($workspaceCalendar);
        $this->assertLessThan($workspaceCalendar, $companyLock);
    }

    public function test_an_active_unlinked_agreement_for_another_project_does_not_block_acceptance(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposalProject = $this->project($workspace, $company, 'Proposal project');
        $otherProject = $this->project($workspace, $company, 'Other project');
        $proposal = $this->sentProposal($workspace, $company, $owner, project: $proposalProject);

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $otherProject->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic other-project agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
        ]);

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertOk();

        $created = ClientAgreement::query()->where('source_proposal_id', $proposal->id)->sole();
        $this->assertSame($proposalProject->id, $created->client_project_id);
    }

    public function test_a_cross_workspace_source_link_collision_fails_closed_without_leaking_the_foreign_row(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);

        $neighbour = Workspace::query()->create([
            'name' => 'Synthetic Collision Workspace',
            'slug' => 'synthetic-collision-workspace-'.uniqid(),
        ]);
        $neighbourCompany = ClientCompany::query()->create([
            'workspace_id' => $neighbour->id,
            'name' => 'Private Foreign Client',
            'slug' => 'private-foreign-client-'.uniqid(),
        ]);
        $foreign = ClientAgreement::query()->create([
            'workspace_id' => $neighbour->id,
            'client_company_id' => $neighbourCompany->id,
            'source_proposal_id' => $proposal->id,
            'title' => 'Private Foreign Agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
        ]);

        $response = $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertUnprocessable()->assertExactJson([
            'message' => 'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        ]);

        $this->assertStringNotContainsString($foreign->title, $response->getContent());
        $this->assertSame('sent', $proposal->fresh()->status);
        $this->assertNull($proposal->accepted_at);
        $this->assertDatabaseCount('client_agreements', 1);
        $this->assertDatabaseCount('client_company_activity', 0);
    }

    public function test_an_overlapping_same_project_agreement_cannot_be_activated(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $project = $this->project($workspace, $company, 'Shared project');

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic live agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);
        $draft = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic overlapping draft',
            'status' => 'draft',
            'currency' => 'USD',
            'starts_on' => '2026-09-01',
        ]);

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/agreements/{$draft->public_id}/activate",
        )->assertUnprocessable()->assertExactJson([
            'message' => 'This agreement cannot overlap another active agreement. Ask an operator to verify its terms.',
        ]);

        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertDatabaseCount('client_company_activity', 0);
    }

    public function test_non_overlapping_and_different_project_agreements_do_not_block_activation(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $project = $this->project($workspace, $company, 'Target project');
        $otherProject = $this->project($workspace, $company, 'Independent project');

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic preceding agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-08-31',
        ]);
        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $otherProject->id,
            'title' => 'Synthetic independent agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2026-01-01',
        ]);
        $draft = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic successor agreement',
            'status' => 'draft',
            'currency' => 'USD',
            'starts_on' => '2026-09-01',
        ]);

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/agreements/{$draft->public_id}/activate",
        )->assertOk();

        $this->assertSame('active', $draft->fresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('subject_public_id', $draft->public_id)->count());
    }

    /**
     * A row retained from before the composite tenant key can carry this
     * company's id under another workspace. The activation lookup must ignore
     * it by workspace as well as company and project.
     */
    public function test_activation_overlap_lookup_ignores_a_legacy_cross_workspace_agreement(): void
    {
        $owner = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner);
        $project = $this->project($workspace, $company, 'Local project');
        $neighbour = Workspace::query()->create([
            'name' => 'Synthetic Foreign Workspace',
            'slug' => 'synthetic-foreign-workspace-'.uniqid(),
        ]);

        $this->writingLegacyCrossTenantRows(static function () use ($neighbour, $company, $project): void {
            DB::table('client_agreements')->insert([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $neighbour->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project->id,
                'source_proposal_id' => null,
                'title' => 'Private foreign agreement',
                'status' => 'active',
                'starts_on' => '2026-01-01',
                'ends_on' => null,
                'is_visible_to_client' => false,
                'currency' => 'USD',
                'billing_cadence' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $draft = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project->id,
            'title' => 'Synthetic local agreement',
            'status' => 'draft',
            'currency' => 'USD',
            'starts_on' => '2026-09-01',
        ]);

        $this->actingAs($owner)->postJson(
            "/workspaces/{$workspace->public_id}/agreements/{$draft->public_id}/activate",
        )->assertOk();

        $this->assertSame('active', $draft->fresh()->status);
    }

    public function test_an_active_agreement_linked_to_another_proposal_does_not_block_acceptance(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $other = $this->sentProposal($workspace, $company, $owner, 'Synthetic earlier proposal');
        $proposal = $this->sentProposal($workspace, $company, $owner, 'Synthetic current proposal');

        ClientAgreement::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'source_proposal_id' => $other->id,
            'title' => 'Synthetic accounted agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
        ]);

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertOk();

        $this->assertSame($proposal->id, ClientAgreement::query()->where('source_proposal_id', $proposal->id)->sole()->source_proposal_id);
    }

    public function test_an_unlinked_agreement_from_another_workspace_does_not_block_acceptance(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);
        $neighbour = Workspace::query()->create([
            'name' => 'Synthetic Neighbour Workspace',
            'slug' => 'synthetic-neighbour-workspace-'.uniqid(),
        ]);
        $neighbourCompany = ClientCompany::query()->create([
            'workspace_id' => $neighbour->id,
            'name' => 'Synthetic Neighbour Client',
            'slug' => 'synthetic-neighbour-client-'.uniqid(),
        ]);

        ClientAgreement::query()->create([
            'workspace_id' => $neighbour->id,
            'client_company_id' => $neighbourCompany->id,
            'source_proposal_id' => null,
            'title' => 'Synthetic neighbouring agreement',
            'status' => 'active',
            'currency' => 'USD',
            'starts_on' => '2025-01-01',
        ]);

        $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertOk();

        $created = ClientAgreement::query()
            ->where('workspace_id', $workspace->id)
            ->where('source_proposal_id', $proposal->id)
            ->sole();
        $this->assertSame($company->id, $created->client_company_id);
    }

    public function test_linked_agreement_lookup_independently_scopes_the_workspace_predicate(): void
    {
        $owner = User::factory()->create();
        $clientUser = User::factory()->create();
        [$workspace, $company] = $this->clientFor($owner, $clientUser);
        $proposal = $this->sentProposal($workspace, $company, $owner);
        $neighbour = Workspace::query()->create([
            'name' => 'Synthetic Linked Lookup Neighbour',
            'slug' => 'synthetic-linked-lookup-neighbour-'.uniqid(),
        ]);

        $this->writingLegacyCrossTenantRows(static function () use ($neighbour, $company, $proposal): void {
            DB::table('client_agreements')->insert([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $neighbour->id,
                'client_company_id' => $company->id,
                'client_project_id' => null,
                'source_proposal_id' => $proposal->id,
                'title' => 'Private foreign linked agreement',
                'status' => 'active',
                'starts_on' => '2026-01-01',
                'ends_on' => null,
                'is_visible_to_client' => false,
                'currency' => 'USD',
                'billing_cadence' => 'monthly',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertNull(app(ProposalAcceptanceAgreementQuery::class)->linkedAgreement($proposal));

        $response = $this->actingAs($clientUser)->postJson(
            "/portal/{$company->public_id}/proposals/{$proposal->public_id}/accept",
            ['signer_name' => 'Synthetic Signer'],
        )->assertUnprocessable();

        $response->assertExactJson([
            'message' => 'This proposal cannot be accepted automatically. Ask an operator to verify its agreement link.',
        ]);
        $this->assertStringNotContainsString('Private foreign linked agreement', $response->getContent());
        $this->assertSame('sent', $proposal->fresh()->status);
    }

    private function sentProposal(
        Workspace $workspace,
        ClientCompany $company,
        User $owner,
        string $title = 'Synthetic proposal',
        ?ClientProject $project = null,
    ): ClientProposal {
        $proposal = ClientProposal::query()->create([
            'workspace_id' => $workspace->id,
            'client_company_id' => $company->id,
            'client_project_id' => $project?->id,
            'created_by_user_id' => $owner->id,
            'title' => $title,
            'currency' => 'USD',
            'is_visible_to_client' => true,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $proposal->items()->create([
            'workspace_id' => $workspace->id,
            'description' => 'Synthetic monthly support',
            'quantity' => '1.000',
            'unit_amount' => 10000,
            'cadence' => 'monthly',
            'sort_order' => 0,
        ]);

        return $proposal;
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
