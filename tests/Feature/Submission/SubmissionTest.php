<?php

namespace Tests\Feature\Submission;

use App\Models\ClientCompanyMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Submission\SubmissionRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsReviewerWorld;
use Tests\TestCase;

/**
 * Submission: a provider marks hours and expenses ready for review, they reach
 * a reviewer without anyone in the provider's workspace approving them, and the
 * reviewer's approval is what makes them approved (docs/ux-overhaul.md 7b, 7e).
 *
 * "Other Eng" is the solo contractor: its owner logs the hours and cannot
 * approve them. "Ben" is the client's person who reviews. Today is 2026-11-06.
 */
final class SubmissionTest extends TestCase
{
    use BuildsReviewerWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');
        config(['agent_api.writes_enabled' => false]);
        $this->buildReviewerWorld();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** A draft hour with the client-facing line, as the solo contractor logs it. */
    private function draft(User $author, string $day = '2026-10-14', int $minutes = 60, array $overrides = []): ClientTimeEntry
    {
        return $this->hours($this->otherProject, $author, $day, $minutes, $overrides + ['status' => 'draft', 'billing_rate_amount' => null]);
    }

    private function asSignedIn(User $user): static
    {
        return $this->actingAs($user)->withCredentials()->withUnencryptedCookie((string) config('session.cookie'), Str::random(40));
    }

    /** @param array<string, mixed> $body */
    private function submit(User $user, array $body, ?Workspace $workspace = null): TestResponse
    {
        $workspace ??= $this->otherEng;

        return $this->asSignedIn($user)->withHeader('Idempotency-Key', 'submit-'.Str::random(12))->postJson("/api/v1/workspaces/{$workspace->public_id}/submissions", $body);
    }

    /** @param array<string, mixed> $body */
    private function withdraw(User $user, array $body, ?Workspace $workspace = null): TestResponse
    {
        $workspace ??= $this->otherEng;

        return $this->asSignedIn($user)->withHeader('Idempotency-Key', 'withdraw-'.Str::random(12))->postJson("/api/v1/workspaces/{$workspace->public_id}/submissions/withdraw", $body);
    }

    private function queue(): TestResponse
    {
        return $this->actingAs($this->ben)->get('/approvals')->assertOk();
    }

    /** The rows Ben is asked to decide on, through the block of the one provider that granted him. */
    private function benSees(): int
    {
        $count = 0;
        $this->queue()->assertInertia(function (Assert $page) use (&$count): void {
            $blocks = $page->toArray()['props']['blocks'];
            foreach ($blocks as $block) {
                foreach ($block['to_approve']['groups'] as $group) {
                    $count += count($group['hours']['entries']) + count($group['expenses']['items']);
                }
            }
        });

        return $count;
    }

    // --- reaching a reviewer ------------------------------------------------

    public function test_a_submitted_draft_reaches_a_granted_reviewer(): void
    {
        $entry = $this->draft($this->otherOwner);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk()
            ->assertJsonPath('data.items.0.kind', 'time')
            ->assertJsonPath('data.items.0.id', $entry->public_id)
            ->assertJsonPath('data.items.0.state', 'submitted');

        $this->queue()->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.provider', 'Synthetic Other Eng WS')
            ->where('blocks.0.pending', 1)
            ->where('blocks.0.to_approve.groups.0.hours.minutes', 60)
            ->where('blocks.0.to_approve.groups.0.hours.entries.0.id', $entry->public_id));
        $this->assertNotNull($entry->fresh()->submitted_at);
        $this->assertSame($this->otherOwner->id, $entry->fresh()->submitted_by_user_id);
        $this->assertSame('draft', $entry->fresh()->status, 'Submitting moves no status.');
        $this->assertNull($entry->fresh()->client_approved_at, 'Submitting approves nothing.');
    }

    public function test_an_unsubmitted_draft_does_not_reach_the_reviewer(): void
    {
        $this->draft($this->otherOwner);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);

        $this->queue()->assertInertia(fn (Assert $page) => $page->where('blocks.0.pending', 0)->has('blocks.0.to_approve.groups', 0));
        $this->assertSame(0, $this->benSees());
    }

    public function test_a_submitted_draft_reaches_a_portal_contact_too(): void
    {
        $entry = $this->draft($this->otherOwner);
        $contact = User::factory()->create(['email' => 'vet-billing@vet.example.test']);
        $this->otherVet->forceFill(['billing_email' => 'vet-billing@vet.example.test'])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $contact->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->actingAs($contact)->get('/portal/'.$this->otherVet->public_id)->assertInertia(fn (Assert $page) => $page->has('to_approve.groups', 0));

        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();

        $this->actingAs($contact)->get('/portal/'.$this->otherVet->public_id)->assertInertia(fn (Assert $page) => $page
            ->where('to_approve.groups.0.hours.entries.0.id', $entry->public_id));
    }

    public function test_withdrawing_a_submission_removes_the_row_from_the_queue(): void
    {
        $entry = $this->draft($this->otherOwner);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk();
        $this->assertSame(1, $this->benSees());

        $this->withdraw($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk()
            ->assertJsonPath('data.items.0.state', 'draft');

        $this->assertSame(0, $this->benSees());
        $this->assertNull($entry->fresh()->submitted_at);
        $this->assertNull($entry->fresh()->submitted_by_user_id);
    }

    public function test_the_reviewers_approval_moves_the_row_to_approved_in_the_providers_workspace(): void
    {
        $entry = $this->draft($this->otherOwner);
        $herilaEntry = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 30, ['status' => 'draft', 'billing_rate_amount' => null]);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk();

        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/approve", ['period' => '2026-10'])->assertRedirect();

        $fresh = $entry->fresh();
        $this->assertSame($this->otherEng->id, $fresh->workspace_id);
        $this->assertNotNull($fresh->client_approved_at);
        $this->assertSame($this->ben->id, $fresh->client_approved_by_user_id);
        $this->assertSame('reviewer', $fresh->client_approval_source);
        $this->assertNull($herilaEntry->fresh()->client_approved_at, 'Another provider is untouched.');
        $this->assertNull($herilaEntry->fresh()->submitted_at);
        $this->asSignedIn($this->otherOwner)->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions?period=2026-10")->assertOk()
            ->assertJsonPath('data.time.0.state', 'approved')
            ->assertJsonPath('data.time.0.can_withdraw', false)
            ->assertJsonPath('data.totals.approved', 60);
        $this->assertSame(0, $this->benSees(), 'An approved row leaves the queue.');
    }

    public function test_an_approved_submission_can_no_longer_be_withdrawn(): void
    {
        $entry = $this->draft($this->otherOwner);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/approve", ['period' => '2026-10']);

        $this->withdraw($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertStatus(409)
            ->assertJsonPath('message', 'This has been approved, so it can no longer be withdrawn.');
        $this->assertNotNull($entry->fresh()->submitted_at);
    }

    public function test_a_disputed_row_can_be_withdrawn_fixed_and_submitted_again(): void
    {
        $entry = $this->draft($this->otherOwner);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();
        $this->actingAs($this->ben)->from('/approvals')->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $entry->public_id, 'note' => 'Which ticket?']);
        $this->asSignedIn($this->otherOwner)->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions?period=2026-10")
            ->assertJsonPath('data.time.0.state', 'disputed')->assertJsonPath('data.time.0.dispute_note', 'Which ticket?');

        $this->withdraw($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk()
            ->assertJsonPath('data.items.0.state', 'submitted');

        $fresh = $entry->fresh();
        $this->assertNull($fresh->client_disputed_at, 'Submitting again answers the dispute.');
        $this->assertNull($fresh->client_dispute_note);
        $this->assertSame(1, $this->benSees());
    }

    // --- the provider never approves its own ---------------------------------

    public function test_the_provider_cannot_approve_its_own_submitted_rows_on_any_path(): void
    {
        $entry = $this->draft($this->otherOwner);
        $grant = $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();

        // 1. The reviewer route: a grant never reaches the provider's own staff.
        $this->actingAs($this->otherOwner)->post("/approvals/grant/{$grant->public_id}/approve", ['period' => '2026-10'])->assertNotFound();
        $this->actingAs($this->otherOwner)->post("/approvals/grant/{$grant->public_id}/approve", ['time_ids' => [$entry->public_id]])->assertNotFound();
        $this->actingAs($this->otherOwner)->post("/approvals/grant/{$grant->public_id}/dispute", ['kind' => 'time', 'id' => $entry->public_id])->assertNotFound();

        // 2. The portal route, even for an author who is also the client's contact.
        $this->otherVet->forceFill(['billing_email' => $this->otherOwner->email])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $this->otherOwner->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->actingAs($this->otherOwner)->post('/portal/'.$this->otherVet->public_id.'/approvals/approve', ['time_ids' => [$entry->public_id]])->assertSessionHasErrors('approval');
        $this->actingAs($this->otherOwner)->post('/portal/'.$this->otherVet->public_id.'/approvals/approve', ['period' => '2026-10']);
        $this->actingAs($this->otherOwner)->post('/portal/'.$this->otherVet->public_id.'/approvals/dispute', ['kind' => 'time', 'id' => $entry->public_id])->assertSessionHasErrors('approval');

        // 3. The operator route, REST: nobody approves their own time.
        $this->asSignedIn($this->otherOwner)->withHeader('Idempotency-Key', 'self-1')
            ->postJson("/api/v1/workspaces/{$this->otherEng->public_id}/time-entries/approve", ['entries' => [['id' => $entry->public_id, 'expected_version' => AgentApiVersion::for($entry->fresh())]]])
            ->assertStatus(409);

        $fresh = $entry->fresh();
        $this->assertNull($fresh->client_approved_at);
        $this->assertNull($fresh->client_approved_by_user_id);
        $this->assertSame('draft', $fresh->status);
        $this->assertNull($fresh->approved_at);
    }

    public function test_a_colleague_on_the_providers_team_cannot_approve_what_the_provider_submitted(): void
    {
        $entry = $this->draft($this->otherOwner);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();
        // The colleague is also a contact of the client, with the right to approve.
        $this->otherVet->forceFill(['billing_email' => $this->otherWorker->email])->save();
        ClientCompanyMembership::query()->create(['client_company_id' => $this->otherVet->id, 'user_id' => $this->otherWorker->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);

        $this->actingAs($this->otherWorker)->post('/portal/'.$this->otherVet->public_id.'/approvals/approve', ['time_ids' => [$entry->public_id]])->assertSessionHasErrors('approval');
        $this->actingAs($this->otherWorker)->post('/portal/'.$this->otherVet->public_id.'/approvals/approve', ['period' => '2026-10']);

        $this->assertNull($entry->fresh()->client_approved_at);
    }

    // --- eligibility ---------------------------------------------------------

    public function test_only_eligible_rows_are_submitted_in_a_month_and_a_named_one_that_is_not_refuses_everything(): void
    {
        $good = $this->draft($this->otherOwner, '2026-10-14');
        $unbilled = $this->draft($this->otherOwner, '2026-10-15', 30, ['is_billable' => false]);
        $private = $this->draft($this->otherOwner, '2026-10-16', 30, ['client_visible_description' => null, 'is_visible_to_client' => false]);
        $november = $this->draft($this->otherOwner, '2026-11-02');
        $colleagues = $this->draft($this->otherWorker, '2026-10-17');

        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk()->assertJsonCount(1, 'data.items');

        $this->assertNotNull($good->fresh()->submitted_at);
        $this->assertNull($unbilled->fresh()->submitted_at);
        $this->assertNull($private->fresh()->submitted_at);
        $this->assertNull($november->fresh()->submitted_at, 'Another month is left alone.');
        $this->assertNull($colleagues->fresh()->submitted_at, "Someone else's hours are never mine to submit.");

        $this->submit($this->otherOwner, ['time_entry_ids' => [$november->public_id, $private->public_id]])->assertStatus(409)
            ->assertJsonPath('message', 'Add the client-facing description and show it to the client before submitting: a reviewer reads only that.');
        $this->assertNull($november->fresh()->submitted_at, 'Nothing is half submitted.');
        $this->submit($this->otherOwner, ['time_entry_ids' => [$good->public_id]])->assertStatus(409)
            ->assertJsonPath('message', 'This is already submitted. Withdraw it first to change it.');
    }

    public function test_direct_subcontractor_time_and_approved_time_cannot_be_submitted(): void
    {
        $direct = $this->draft($this->otherOwner, '2026-10-14', 60, ['subcontractor_billing_mode' => 'direct']);
        $ready = $this->hours($this->otherProject, $this->otherOwner, '2026-10-15', 60, ['status' => 'approved']);

        $this->submit($this->otherOwner, ['time_entry_ids' => [$direct->public_id]])->assertStatus(409);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$ready->public_id]])->assertStatus(409);
        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_a_request_naming_nothing_or_a_bad_month_is_refused(): void
    {
        $this->submit($this->otherOwner, [])->assertStatus(422);
        $this->submit($this->otherOwner, ['period' => '2026-13'])->assertStatus(422);
        $this->submit($this->otherOwner, ['period' => 'October'])->assertStatus(422);
        $this->withdraw($this->otherOwner, [])->assertStatus(422);
    }

    public function test_the_first_and_last_day_are_in_the_month_and_their_neighbours_are_not(): void
    {
        $days = ['2026-09-30', '2026-10-01', '2026-10-31', '2026-11-01'];
        $entries = array_map(fn (string $day): ClientTimeEntry => $this->draft($this->otherOwner, $day), $days);

        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk()->assertJsonCount(2, 'data.items');

        $this->assertNull($entries[0]->fresh()->submitted_at);
        $this->assertNotNull($entries[1]->fresh()->submitted_at);
        $this->assertNotNull($entries[2]->fresh()->submitted_at);
        $this->assertNull($entries[3]->fresh()->submitted_at);
    }

    public function test_expenses_are_submitted_with_the_month_and_reach_the_reviewer(): void
    {
        $expense = $this->expense($this->otherProject, $this->otherOwner, '2026-10-20', 4200, ['status' => 'draft']);
        $this->giveGrant($this->otherEng, $this->otherOwner, $this->otherVet);
        config(['agent_api.writes_enabled' => true, 'agent_api.expense_writes_enabled' => true]);

        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk()
            ->assertJsonPath('data.items.0.kind', 'expense')->assertJsonPath('data.items.0.state', 'submitted');

        $this->queue()->assertInertia(fn (Assert $page) => $page
            ->where('blocks.0.to_approve.groups.0.expenses.items.0.id', $expense->public_id)
            ->where('blocks.0.to_approve.groups.0.expenses.amount', 4200));

        $this->withdraw($this->otherOwner, ['expense_ids' => [$expense->public_id]])->assertOk();
        $this->assertNull($expense->fresh()->submitted_at);
    }

    public function test_a_submitted_row_cannot_be_edited_until_it_is_withdrawn(): void
    {
        $entry = $this->draft($this->otherOwner);
        $expense = $this->expense($this->otherProject, $this->otherOwner, '2026-10-20', 4200, ['status' => 'draft']);
        $this->submit($this->otherOwner, ['period' => '2026-10'])->assertOk();

        $this->asSignedIn($this->otherOwner)->withHeader('Idempotency-Key', 'edit-1')
            ->patchJson("/api/v1/workspaces/{$this->otherEng->public_id}/time-entries/{$entry->public_id}", ['expected_version' => AgentApiVersion::for($entry->fresh()), 'minutes' => 90])
            ->assertStatus(409)->assertJsonPath('message', SubmissionRules::FROZEN_MESSAGE);
        $this->assertSame(60, $entry->fresh()->minutes);

        try {
            (new WorkspaceExpenses($this->otherEng))->discard($expense->fresh());
            $this->fail('A submitted expense is frozen.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
            $this->assertSame(SubmissionRules::FROZEN_MESSAGE, $exception->getMessage());
        }
        $this->assertNull($expense->fresh()->deleted_at);

        $this->withdraw($this->otherOwner, ['period' => '2026-10'])->assertOk();
        $this->asSignedIn($this->otherOwner)->withHeader('Idempotency-Key', 'edit-2')
            ->patchJson("/api/v1/workspaces/{$this->otherEng->public_id}/time-entries/{$entry->public_id}", ['expected_version' => AgentApiVersion::for($entry->fresh()), 'minutes' => 90])
            ->assertOk();
        $this->assertSame(90, $entry->fresh()->minutes);
    }

    public function test_submitting_is_retry_safe(): void
    {
        $entry = $this->draft($this->otherOwner);
        $first = $this->asSignedIn($this->otherOwner)->withHeader('Idempotency-Key', 'same-key')
            ->postJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions", ['time_entry_ids' => [$entry->public_id]])->assertOk()->json();
        $again = $this->asSignedIn($this->otherOwner)->withHeader('Idempotency-Key', 'same-key')
            ->postJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions", ['time_entry_ids' => [$entry->public_id]])->assertOk()->json();

        $this->assertSame($first, $again);
    }

    // --- isolation -----------------------------------------------------------

    public function test_another_workspace_never_reads_or_changes_a_partners_submissions(): void
    {
        $entry = $this->draft($this->otherOwner);
        $this->submit($this->otherOwner, ['time_entry_ids' => [$entry->public_id]])->assertOk();

        // The host (Herila) and a stranger: the partner's workspace is not found.
        foreach ([$this->herilaOwner, $this->herilaWorker, $this->ben] as $outsider) {
            // A new request from a new person: the last one's resolved guards are forgotten.
            $this->app['auth']->forgetGuards();
            $this->asSignedIn($outsider)->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions?period=2026-10")->assertNotFound();
            $this->asSignedIn($outsider)->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/timesheets/approved?period=2026-10")->assertNotFound();
            $this->submit($outsider, ['time_entry_ids' => [$entry->public_id]])->assertNotFound();
            $this->withdraw($outsider, ['time_entry_ids' => [$entry->public_id]])->assertNotFound();
        }
        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->otherEng->public_id}/submissions")->assertNotFound();
        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet")->assertNotFound();
        $this->actingAs($this->herilaOwner)->get("/workspaces/{$this->otherEng->public_id}/timesheet/export?format=csv")->assertNotFound();
        $this->assertNotNull($entry->fresh()->submitted_at);
    }

    public function test_a_colleague_cannot_submit_or_withdraw_someone_elses_hours_and_a_foreign_id_is_not_found(): void
    {
        $mine = $this->draft($this->otherOwner);
        $theirs = $this->draft($this->otherWorker, '2026-10-15');
        $herilas = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 60, ['status' => 'draft']);
        $mine->forceFill(['submitted_at' => now(), 'submitted_by_user_id' => $this->otherOwner->id])->save();

        $this->submit($this->otherWorker, ['time_entry_ids' => [$mine->public_id]])->assertNotFound();
        $this->assertNotNull($mine->fresh()->submitted_at);
        $this->assertSame($this->otherOwner->id, $mine->fresh()->submitted_by_user_id);
        $this->assertNull($herilas->fresh()->submitted_at);
        $this->assertNull($theirs->fresh()->submitted_at);
    }

    public function test_a_foreign_or_colleagues_id_is_not_found_to_the_author(): void
    {
        $theirs = $this->draft($this->otherWorker, '2026-10-15');
        $herilas = $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-14', 60, ['status' => 'draft']);

        $this->submit($this->otherOwner, ['time_entry_ids' => [$theirs->public_id, $herilas->public_id]])->assertNotFound();
        $this->assertNull($herilas->fresh()->submitted_at);
        $this->assertNull($theirs->fresh()->submitted_at);
    }

    public function test_a_colleague_cannot_withdraw_someone_elses_submission(): void
    {
        $mine = $this->draft($this->otherOwner);
        $mine->forceFill(['submitted_at' => now(), 'submitted_by_user_id' => $this->otherOwner->id])->save();

        $this->withdraw($this->otherWorker, ['time_entry_ids' => [$mine->public_id]])->assertNotFound();
        $this->assertNotNull($mine->fresh()->submitted_at);
    }

    public function test_the_month_view_is_the_authors_own_and_never_carries_money(): void
    {
        $this->draft($this->otherOwner, '2026-10-14', 60, ['billing_rate_amount' => 987654]);
        $this->draft($this->otherWorker, '2026-10-15', 45);
        $this->hours($this->herilaProject, $this->herilaWorker, '2026-10-16', 30, ['status' => 'draft']);

        $body = $this->asSignedIn($this->otherOwner)->getJson("/api/v1/workspaces/{$this->otherEng->public_id}/submissions?period=2026-10")->assertOk()
            ->assertJsonCount(1, 'data.time')->assertJsonPath('data.time.0.minutes', 60)->getContent();

        $this->assertStringNotContainsString('987654', (string) $body);
        $this->assertStringNotContainsString('billing_rate', (string) $body);
    }

    // --- the page ------------------------------------------------------------

    public function test_the_page_shows_the_months_rows_with_finished_links(): void
    {
        $entry = $this->draft($this->otherOwner);
        $this->actingAs($this->otherOwner)->get("/workspaces/{$this->otherEng->public_id}/submissions?period=2026-10")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('workspaces/submissions')
                ->where('submissions.period', '2026-10')
                ->where('submissions.period_label', 'October 2026')
                ->where('submissions.previous_period', '2026-09')
                ->where('submissions.next_period', '2026-11')
                ->where('submissions.time.0.id', $entry->public_id)
                ->where('submissions.time.0.state', 'draft')
                ->where('submissions.time.0.can_submit', true)
                ->where('links.submit_url', "/api/v1/workspaces/{$this->otherEng->public_id}/submissions")
                ->where('links.next_href', "/workspaces/{$this->otherEng->public_id}/submissions?period=2026-11"));
    }
}
