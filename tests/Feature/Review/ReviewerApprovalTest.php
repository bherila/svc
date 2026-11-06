<?php

namespace Tests\Feature\Review;

use App\Models\AccessAuditEvent;
use App\Models\ClientCompanyMembership;
use App\Models\ClientTimeEntry;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\RecordReceivedPayment;
use App\Services\Review\ReviewerGrants;
use App\Services\SharedProjects\SharedProjectParticipations;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Review\GranteeKind;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsReviewerWorld;
use Tests\TestCase;

/**
 * A reviewer's "To approve": what a grant shows, what it never shows, what
 * approving and disputing through it write and where, and the refusals that
 * matter most. Today is 2026-11-06.
 */
final class ReviewerApprovalTest extends TestCase
{
    use BuildsReviewerWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
        $this->buildReviewerWorld();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function page(User $user): TestResponse
    {
        return $this->actingAs($user)->get('/approvals')->assertOk();
    }

    /** @param array<string, mixed> $data */
    private function approve(User $user, string $source, string $id, array $data): TestResponse
    {
        return $this->actingAs($user)->from('/approvals')->post("/approvals/{$source}/{$id}/approve", $data);
    }

    // --- isolation -----------------------------------------------------

    public function test_a_reviewer_without_a_grant_sees_nothing(): void
    {
        $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14');
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15');

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->component('approvals/index')->where('blocks', []));
    }

    public function test_a_grant_shows_that_providers_rows_and_only_those(): void
    {
        $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 90);
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->has('blocks', 1)
            ->where('blocks.0.source', 'grant')
            ->where('blocks.0.provider', 'Synthetic Other Eng WS')
            ->where('blocks.0.client', 'Synthetic Vet')
            ->where('blocks.0.pending', 1)
            ->where('blocks.0.to_approve.groups.0.vendor', 'Synthetic Other Eng WS')
            ->where('blocks.0.to_approve.groups.0.hours.minutes', 30)
            ->has('blocks.0.to_approve.groups', 1));
    }

    public function test_provider_a_reviewer_never_sees_provider_b_unless_b_granted_them(): void
    {
        $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 90);
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->giveGrant($this->herila, $this->herilaOwner, $this->herilaVet);

        $body = $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->has('blocks', 1)->where('blocks.0.provider', 'Synthetic Herila WS'))->getContent();
        $this->assertStringNotContainsString('Synthetic Other Eng WS', (string) $body);

        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->has('blocks', 2)
            ->where('blocks.0.provider', 'Synthetic Herila WS')
            ->where('blocks.1.provider', 'Synthetic Other Eng WS'));
    }

    public function test_a_grant_for_one_client_never_reaches_another_of_the_same_provider(): void
    {
        $other = $this->newCompany($this->otherEng, 'Synthetic Second Client');
        $project = $this->newProject($other, 'Synthetic Second Project');
        $this->hours($project, $this->otherWorker, '2026-10-15', 45);
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-16', 30);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.to_approve.groups.0.hours.minutes', 30)
            ->has('blocks.0.to_approve.groups.0.hours.entries', 1));
    }

    public function test_a_project_grant_reaches_that_project_only(): void
    {
        $second = $this->newProject($this->otherVet, 'Synthetic Unshared Project');
        $this->hours($second, $this->otherWorker, '2026-10-15', 45);
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-16', 30);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet, project: $this->otherProject);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.to_approve.groups.0.hours.minutes', 30)
            ->where('blocks.0.to_approve.groups.0.hours.entries.0.project', 'Synthetic Other Project'));
    }

    public function test_a_revoked_grant_stops_at_once(): void
    {
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->has('blocks', 1));

        $this->actingAs($this->otherOwner)->delete("/workspaces/{$this->otherEng->public_id}/reviewers/{$grant->public_id}")->assertNoContent();

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
    }

    public function test_a_revoked_grant_can_neither_approve_nor_dispute(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->actingAs($this->otherOwner)->delete("/workspaces/{$this->otherEng->public_id}/reviewers/{$grant->public_id}")->assertNoContent();

        $this->approve($this->ben, 'grant', $grant->public_id, ['period' => '2026-10'])->assertNotFound();
        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$entry->public_id]])->assertNotFound();
        $this->actingAs($this->ben)->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $entry->public_id])->assertNotFound();

        $this->assertNull($entry->fresh()->client_approved_at);
        $this->assertNull($entry->fresh()->client_disputed_at);
    }

    public function test_someone_elses_grant_is_a_404_like_one_that_does_not_exist(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $stranger = User::factory()->create(['email' => 'stranger@vet.example.test']);

        $this->approve($stranger, 'grant', $grant->public_id, ['period' => '2026-10'])->assertNotFound();
        $this->approve($stranger, 'grant', '00000000-0000-4000-8000-000000000000', ['period' => '2026-10'])->assertNotFound();
        $this->assertNull($entry->fresh()->client_approved_at);
    }

    public function test_the_granting_workspaces_own_staff_are_not_reached_by_a_grant_naming_their_address(): void
    {
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $grant->forceFill(['reviewer_email' => $this->otherOwner->email, 'grantee_key' => 'contact:'.sha1($this->otherOwner->email)])->save();

        $this->page($this->otherOwner)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
    }

    public function test_a_workspace_grant_reaches_its_owners_and_admins_and_not_its_members(): void
    {
        $partner = $this->newWorkspace('Synthetic Partner WS');
        $partnerAdmin = $this->staff($partner, 'admin');
        $partnerMember = $this->staff($partner, 'member');
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->shareProject($this->otherEng, $this->otherOwner, $partner, $partnerAdmin);
        app(ReviewerGrants::class)->grant($this->otherOwner, $this->otherEng, $this->otherVet->public_id, null, GranteeKind::Workspace, $partner->public_id);

        $this->page($partnerAdmin)->assertInertia(fn (Assert $page) => $page->has('blocks', 1)->where('blocks.0.provider', 'Synthetic Other Eng WS'));
        $this->page($partnerMember)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
    }

    public function test_a_portal_membership_of_the_client_is_decided_by_the_membership_alone(): void
    {
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet, 'later-member@vet.example.test');
        $member = User::factory()->create(['email' => 'later-member@vet.example.test']);
        $this->page($member)->assertInertia(fn (Assert $page) => $page->has('blocks', 1)->where('blocks.0.source', 'grant'));

        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->otherVet->id, 'user_id' => $member->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY, 'can_approve_time_and_expenses' => false,
        ]);

        // An explicit no on the membership is not undone by a grant.
        $this->page($member)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
    }

    public function test_a_membership_that_approves_and_a_grant_for_the_same_client_show_one_block(): void
    {
        $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet, 'dual@vet.example.test');
        $dual = User::factory()->create(['email' => 'dual@vet.example.test']);
        $this->otherVet->forceFill(['billing_email' => 'dual@vet.example.test'])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $dual->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->page($dual)->assertInertia(fn (Assert $page) => $page->has('blocks', 1)->where('blocks.0.source', 'portal'));
    }

    // --- what a reviewer never sees ------------------------------------

    public function test_a_reviewer_never_sees_rates_invoices_or_notes(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->invoiceFor($entry);
        $this->expense($this->otherProject, $this->otherWorker, '2026-10-16', 4321, ['notes' => 'SECRET-EXPENSE-NOTE']);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $response = $this->page($this->ben);
        $body = (string) $response->getContent();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.to_approve.groups.0.hours.entries.0.description', 'VISIBLE-HOURS')
            ->where('blocks.0.to_approve.groups.0.hours.entries.0.invoice', null)
            ->where('blocks.0.to_approve.groups.0.expenses.items.0.invoice', null));
        foreach (['SECRET-', '987654', '98,765', 'INV', '/portal/', 'billing_rate', 'rate_amount', 'invoice_number', 'issued'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body, "The reviewer's page printed {$forbidden}.");
        }
        // What is being approved is still there: the expense carries its amount, hours carry minutes only.
        $this->assertStringContainsString('4321', $body);
    }

    // --- approving through a grant -------------------------------------

    public function test_approving_a_month_records_it_in_the_providers_workspace_against_its_own_rows(): void
    {
        $a = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $b = $this->hours($this->otherProject, $this->otherWorker, '2026-10-20', 45);
        $november = $this->hours($this->otherProject, $this->otherWorker, '2026-11-02', 15);
        $expense = $this->expense($this->otherProject, $this->otherWorker, '2026-10-16');
        $herilas = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->approve($this->ben, 'grant', $grant->public_id, ['period' => '2026-10'])
            ->assertRedirect('/approvals')
            ->assertSessionHas('approval_result.time', 2)
            ->assertSessionHas('approval_result.expenses', 1)
            ->assertSessionHas('approval_result.key', $grant->public_id);

        foreach ([$a, $b] as $row) {
            $row = $row->fresh();
            $this->assertNotNull($row->client_approved_at);
            $this->assertSame($this->ben->id, $row->client_approved_by_user_id);
            $this->assertSame('reviewer', $row->client_approval_source);
            $this->assertSame($this->otherEng->id, $row->workspace_id);
        }
        $this->assertSame('reviewer', $expense->fresh()->client_approval_source);
        $this->assertNull($november->fresh()->client_approved_at);
        $this->assertNull($herilas->fresh()->client_approved_at);
        // The provider's own ready-to-bill check is untouched.
        $this->assertSame('approved', $a->fresh()->status);
        $this->assertNull($a->fresh()->approved_by_user_id);
        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->has('blocks.0.to_approve.groups', 1)->where('blocks.0.pending', 1));
    }

    public function test_approving_named_rows_and_disputing_one_through_a_grant(): void
    {
        $a = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $b = $this->hours($this->otherProject, $this->otherWorker, '2026-10-20', 45);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$a->public_id]])->assertSessionHas('approval_result.time', 1);
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $b->public_id, 'note' => 'Which project?'])
            ->assertSessionHas('approval_result.kind', 'disputed');

        $this->assertSame('reviewer', $a->fresh()->client_approval_source);
        $this->assertNull($b->fresh()->client_approved_at);
        $this->assertSame($this->ben->id, $b->fresh()->client_disputed_by_user_id);
        $this->assertSame('Which project?', $b->fresh()->client_dispute_note);
    }

    public function test_a_row_of_another_provider_is_not_on_offer_through_this_grant(): void
    {
        $herilas = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-15', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$herilas->public_id]])
            ->assertSessionHasErrors('approval_'.$grant->public_id);
        $this->assertNull($herilas->fresh()->client_approved_at);
    }

    public function test_a_project_grant_cannot_approve_another_projects_row_by_naming_it(): void
    {
        $second = $this->newProject($this->otherVet, 'Synthetic Unshared Project');
        $outside = $this->hours($second, $this->otherWorker, '2026-10-15', 45);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet, project: $this->otherProject);

        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$outside->public_id]])->assertSessionHasErrors('approval_'.$grant->public_id);
        $this->approve($this->ben, 'grant', $grant->public_id, ['period' => '2026-10'])->assertSessionHas('approval_result.time', 0);
        $this->assertNull($outside->fresh()->client_approved_at);
    }

    // --- no self-approval, anywhere ------------------------------------

    public function test_a_reviewer_cannot_approve_or_dispute_hours_or_expenses_they_authored(): void
    {
        $own = $this->hours($this->otherProject, $this->ben, '2026-10-15', 30);
        $ownExpense = $this->expense($this->otherProject, $this->ben, '2026-10-16');
        $theirs = $this->hours($this->otherProject, $this->otherWorker, '2026-10-17', 20);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $key = 'approval_'.$grant->public_id;

        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$own->public_id]])->assertSessionHasErrors($key);
        $this->approve($this->ben, 'grant', $grant->public_id, ['expense_ids' => [$ownExpense->public_id]])->assertSessionHasErrors($key);
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $own->public_id])->assertSessionHasErrors($key);
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'expense', 'id' => $ownExpense->public_id])->assertSessionHasErrors($key);

        // A whole month approves what is not theirs and says how many it left.
        $this->approve($this->ben, 'grant', $grant->public_id, ['period' => '2026-10'])
            ->assertSessionHas('approval_result.time', 1)
            ->assertSessionHas('approval_result.skipped_own', 2);
        $this->assertNull($own->fresh()->client_approved_at);
        $this->assertNull($ownExpense->fresh()->client_approved_at);
        $this->assertNotNull($theirs->fresh()->client_approved_at);
    }

    public function test_self_approval_is_refused_on_the_portal_membership_path_of_the_same_page(): void
    {
        $own = $this->hours($this->otherProject, $this->ben, '2026-10-15', 30);
        $this->otherVet->forceFill(['billing_email' => $this->ben->email])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $this->ben->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->approve($this->ben, 'company', $this->otherVet->public_id, ['time_ids' => [$own->public_id]])
            ->assertSessionHasErrors('approval_'.$this->otherVet->public_id);
        $this->assertNull($own->fresh()->client_approved_at);
    }

    // --- the portal path on the same page ------------------------------

    public function test_one_queue_holds_a_membership_provider_and_a_grant_provider_grouped_by_provider(): void
    {
        $this->herilaVet->forceFill(['billing_email' => $this->ben->email])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->herilaVet->id, 'user_id' => $this->ben->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $herilas = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-15', 120, ['billing_rate_amount' => 111]);
        $others = $this->hours($this->otherProject, $this->otherWorker, '2026-10-16', 30);
        $this->invoiceFor($herilas, 'INV-HERILA-1');
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page
            ->has('blocks', 2)
            ->where('blocks.0.provider', 'Synthetic Herila WS')
            ->where('blocks.0.source', 'portal')
            ->where('blocks.0.to_approve.approve_href', "/approvals/company/{$this->herilaVet->public_id}/approve")
            // The membership path keeps its invoice link, as the portal home does.
            ->where('blocks.0.to_approve.groups.0.hours.entries.0.invoice.number', 'INV-HERILA-1')
            ->where('blocks.1.provider', 'Synthetic Other Eng WS')
            ->where('blocks.1.source', 'grant')
            ->where('blocks.1.to_approve.approve_href', "/approvals/grant/{$grant->public_id}/approve"));

        $this->approve($this->ben, 'company', $this->herilaVet->public_id, ['period' => '2026-10'])->assertSessionHas('approval_result.time', 1);
        $this->approve($this->ben, 'grant', $grant->public_id, ['period' => '2026-10'])->assertSessionHas('approval_result.time', 1);

        $this->assertSame('portal', $herilas->fresh()->client_approval_source);
        $this->assertSame('reviewer', $others->fresh()->client_approval_source);
    }

    public function test_a_company_id_that_is_not_a_membership_of_the_person_is_a_404(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);

        $this->approve($this->ben, 'company', $this->otherVet->public_id, ['period' => '2026-10'])->assertNotFound();
        $this->assertNull($entry->fresh()->client_approved_at);
    }

    public function test_a_membership_without_the_permission_gets_no_block_and_no_approval(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $this->ben->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
        $this->approve($this->ben, 'company', $this->otherVet->public_id, ['period' => '2026-10'])->assertForbidden();
        $this->assertNull($entry->fresh()->client_approved_at);
    }

    public function test_a_billing_contact_whose_grant_is_explicitly_false_is_not_an_approver_here(): void
    {
        $entry = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $this->otherVet->forceFill(['billing_email' => $this->ben->email])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $this->ben->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY, 'can_approve_time_and_expenses' => false]);

        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->where('blocks', []));
        $this->approve($this->ben, 'company', $this->otherVet->public_id, ['period' => '2026-10'])->assertForbidden();
        $this->assertNull($entry->fresh()->client_approved_at);
    }

    // --- paying still approves, as slice 8 does ------------------------

    public function test_a_paid_invoice_still_approves_and_leaves_a_reviewers_decision_alone(): void
    {
        $paid = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $decided = $this->hours($this->otherProject, $this->otherWorker, '2026-10-16', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$decided->public_id]]);
        $invoice = $this->invoiceFor($paid);
        $line = $invoice->lines()->firstOrFail();
        $line->timeEntries()->attach($decided->id, ['workspace_id' => $this->otherEng->id]);

        app(RecordReceivedPayment::class)->record(
            $this->otherEng,
            $invoice->fresh(),
            ReceivedPaymentData::from(['amount' => 10000, 'currency' => 'USD', 'received_on' => '2026-11-06', 'method' => 'bank transfer']),
        );

        $this->assertSame('payment', $paid->fresh()->client_approval_source);
        $this->assertNull($paid->fresh()->client_approved_by_user_id);
        $this->assertSame('reviewer', $decided->fresh()->client_approval_source);
        $this->assertSame($this->ben->id, $decided->fresh()->client_approved_by_user_id);
        $this->page($this->ben)->assertInertia(fn (Assert $page) => $page->where('blocks.0.to_approve.groups', []));
    }

    // --- audit ---------------------------------------------------------

    public function test_every_grant_read_and_decision_is_written_in_the_granting_workspace(): void
    {
        $a = $this->hours($this->otherProject, $this->otherWorker, '2026-10-15', 30);
        $b = $this->hours($this->otherProject, $this->otherWorker, '2026-10-16', 30);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben);
        $this->approve($this->ben, 'grant', $grant->public_id, ['time_ids' => [$a->public_id]]);
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $b->public_id]);

        $events = AccessAuditEvent::query()->where('workspace_id', $this->otherEng->id)->where('action', 'like', 'reviewer_grant.%')->orderBy('id')->get();
        $this->assertSame(['reviewer_grant.granted', 'reviewer_grant.read', 'reviewer_grant.approved', 'reviewer_grant.disputed'], $events->pluck('action')->all());
        $this->assertSame($this->ben->id, $events[1]->actor_user_id);
        $this->assertSame($grant->public_id, $events[1]->after_state['grant']);
        $this->assertSame(2, $events[1]->after_state['rows']);
        $this->assertSame(1, $events[2]->after_state['time']);
        $this->assertSame($this->otherVet->public_id, $events[2]->client_company_public_id);
        $this->assertSame(0, AccessAuditEvent::query()->where('workspace_id', $this->herila->id)->where('action', 'like', 'reviewer_grant.%')->count());
    }

    public function test_a_page_with_nothing_disclosed_writes_no_read_event(): void
    {
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->page($this->ben);

        $this->assertSame(0, AccessAuditEvent::query()->where('action', 'reviewer_grant.read')->count());
    }

    // --- helpers -------------------------------------------------------

    private function shareProject(Workspace $host, User $hostOwner, Workspace $participant, User $participantAdmin): void
    {
        $project = $this->otherProject;
        $service = app(SharedProjectParticipations::class);
        $invitation = $service->invite($hostOwner, $host, $project->public_id, $participantAdmin->email);
        $service->accept($participantAdmin, $invitation->public_id, $participant);
    }

    public function test_the_grant_table_is_not_readable_by_a_workspace_that_did_not_give_it(): void
    {
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->assertSame(1, ReviewerGrant::query()->count());
        $this->assertSame(0, ReviewerGrant::query()->where('granter_workspace_id', $this->herila->id)->count());
        $this->assertSame(0, ClientTimeEntry::query()->whereNotNull('client_approved_at')->count());
    }
}
