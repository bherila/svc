<?php

namespace Tests\Feature\Approval;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\TimeEntryMutationService;
use App\Services\Approval\DisputedDraftRebuilder;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use App\Support\Billing\SelectedTimeInvoiceTerms;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The provider settles a client's dispute (docs/ux-overhaul.md 7b″): answered
 * puts the row back before the client and on its period's draft; written off
 * means it is never billed. Today is 2026-10-08; the cadence draft sells
 * October and bills September's work.
 */
final class DisputeSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private ClientAgreement $agreement;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-08 12:00:00');
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic settle', 'slug' => 'synthetic-settle']);
        $this->owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Synthetic settle client', 'slug' => 'synthetic-settle-client',
            'billing_email' => 'ap-settle@synthetic.example.test',
        ]);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic settle project', 'is_visible_to_client' => true]);
        $this->agreement = ClientAgreement::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'title' => 'Synthetic retainer', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_minutes' => 600, 'retainer_amount' => 100000,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function hours(string $day, int $minutes, array $overrides = [], ?User $author = null): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'user_id' => ($author ?? $this->owner)->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'draft', 'is_billable' => true, 'is_deferred' => false,
        ])->refresh();
    }

    private function expense(string $day, int $amount): ClientExpense
    {
        return ClientExpense::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $this->project->id,
            'created_by_user_id' => $this->owner->id, 'spent_on' => $day, 'amount' => $amount, 'currency' => 'USD',
            'description' => 'Synthetic expense', 'status' => 'approved',
        ])->refresh();
    }

    private function generate(): void
    {
        app(ClientInvoicingService::class)->generateCadencePeriod($this->company, $this->agreement, Carbon::parse('2026-10-01'));
    }

    private function draft(): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $this->workspace->id)->where('status', 'draft')->sole();
    }

    private function onAnyLine(ClientTimeEntry $entry): bool
    {
        return DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $entry->id)->exists();
    }

    private function contact(): User
    {
        $user = User::factory()->create(['email' => $this->company->billing_email]);
        ClientCompanyMembership::query()->create([
            'client_company_id' => $this->company->id, 'user_id' => $user->id, 'role' => 'client',
            'access_scope' => ClientCompanyMembership::SCOPE_COMPANY,
        ]);

        return $user;
    }

    private function dispute(User $contact, string $kind, string $id): void
    {
        $this->actingAs($contact)->from('/portal/x')
            ->post('/portal/'.$this->company->public_id.'/approvals/dispute', ['kind' => $kind, 'id' => $id, 'note' => 'Synthetic question'])
            ->assertSessionHasNoErrors();
    }

    private function settleTime(ClientTimeEntry $entry, DisputeSettlement $outcome, ?User $actor = null, string $reply = 'Synthetic reply'): ClientTimeEntry
    {
        $fresh = $entry->fresh() ?? $entry;

        return app(DisputeSettlements::class)->settleTime($this->workspace, $fresh->public_id, $actor ?? $this->owner, $outcome, $reply, AgentApiVersion::for($fresh));
    }

    private function refusal(callable $call): int
    {
        try {
            $call();
        } catch (HttpException $refused) {
            return $refused->getStatusCode();
        }
        $this->fail('The settlement was not refused.');
    }

    // --- answering ------------------------------------------------------

    public function test_answering_clears_the_hold_puts_the_hour_back_on_its_draft_and_shows_the_client_the_reply(): void
    {
        $this->hours('2026-09-10', 600);
        $disputed = $this->hours('2026-09-20', 120);
        $this->generate();
        $before = $this->draft()->lines()->count();
        $contact = $this->contact();
        $this->dispute($contact, 'time', $disputed->public_id);
        $this->assertFalse($this->onAnyLine($disputed));

        $settled = $this->settleTime($disputed, DisputeSettlement::Answered, reply: 'Synthetic: the call ran long, as agreed.');

        $this->assertNull($settled->client_disputed_at);
        $this->assertSame('answered', $settled->client_dispute_settlement);
        $this->assertSame($this->owner->id, $settled->client_dispute_settled_by_user_id);
        $this->assertTrue($this->onAnyLine($disputed), 'Its period\'s draft took it back.');
        $this->assertSame($before, $this->draft()->lines()->count());
        $this->assertDatabaseHas('client_company_activity', ['client_company_id' => $this->company->id, 'action' => 'dispute.answered']);

        $this->actingAs($contact)->get('/portal/'.$this->company->public_id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('to_approve.groups.0.hours.entries.1.id', $disputed->public_id)
            ->where('to_approve.groups.0.hours.entries.1.state', 'pending')
            ->where('to_approve.groups.0.hours.entries.1.settlement', 'answered')
            ->where('to_approve.groups.0.hours.entries.1.reply', 'Synthetic: the call ran long, as agreed.'));
    }

    public function test_a_new_dispute_clears_the_previous_reply(): void
    {
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $contact = $this->contact();
        $this->dispute($contact, 'time', $entry->public_id);
        $this->settleTime($entry, DisputeSettlement::Answered);

        $this->dispute($contact, 'time', $entry->public_id);

        $fresh = $entry->fresh();
        $this->assertNotNull($fresh->client_disputed_at);
        $this->assertNull($fresh->client_dispute_settlement);
        $this->assertNull($fresh->client_dispute_reply);
        $this->assertNull($fresh->client_dispute_settled_at);
        $this->assertNull($fresh->client_dispute_settled_by_user_id);
    }

    // --- writing off ----------------------------------------------------

    public function test_a_written_off_hour_is_never_billed_and_stays_read_only_before_the_client(): void
    {
        $entry = $this->hours('2026-09-20', 120, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $contact = $this->contact();
        $this->dispute($contact, 'time', $entry->public_id);

        $settled = $this->settleTime($entry, DisputeSettlement::WrittenOff, reply: 'Synthetic: no charge for this one.');
        $this->generate();

        $this->assertFalse($settled->is_billable);
        $this->assertFalse($this->onAnyLine($entry));
        $this->actingAs($contact)->get('/portal/'.$this->company->public_id)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('to_approve.groups.0.hours.pending_count', 0)
            ->where('to_approve.groups.0.hours.entries.0.state', 'written_off')
            ->where('to_approve.groups.0.hours.entries.0.reply', 'Synthetic: no charge for this one.'));
        $this->actingAs($contact)->from('/portal/x')
            ->post('/portal/'.$this->company->public_id.'/approvals/approve', ['time_ids' => [$entry->public_id]])
            ->assertSessionHasErrors();

        CarbonImmutable::setTestNow('2026-11-20 12:00:00');
        $this->actingAs($contact)->get('/portal/'.$this->company->public_id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('to_approve.groups', []));
    }

    public function test_a_written_off_expense_is_never_claimed(): void
    {
        $this->hours('2026-09-10', 60);
        $expense = $this->expense('2026-09-15', 4200);
        $this->dispute($this->contact(), 'expense', $expense->public_id);

        app(DisputeSettlements::class)->settleExpense($this->workspace, $expense->public_id, $this->owner, DisputeSettlement::WrittenOff, 'Synthetic: on us.', AgentApiVersion::for($expense->fresh()));
        $this->generate();

        $this->assertNull($expense->fresh()->client_invoice_line_id);
        $this->assertFalse($this->draft()->lines()->where('type', 'expense')->exists());
    }

    public function test_a_charge_an_issued_invoice_already_made_is_answered_not_written_off(): void
    {
        $entry = $this->hours('2026-09-10', 700);
        $this->generate();
        app(InvoiceLifecycleService::class)->issue($this->draft(), $this->workspace);
        $this->dispute($this->contact(), 'time', $entry->public_id);

        $this->assertSame(409, $this->refusal(fn () => $this->settleTime($entry, DisputeSettlement::WrittenOff)));
        $this->assertTrue($entry->fresh()->is_billable);
        $this->assertSame('answered', $this->settleTime($entry, DisputeSettlement::Answered)->client_dispute_settlement);
    }

    // --- refusals -------------------------------------------------------

    public function test_only_the_author_or_a_manager_answers_and_only_an_approver_writes_off(): void
    {
        $author = User::factory()->create();
        $bystander = User::factory()->create();
        foreach ([$author, $bystander] as $member) {
            $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        }
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD'], $author);
        $this->dispute($this->contact(), 'time', $entry->public_id);

        $this->assertContains($this->refusal(fn () => $this->settleTime($entry, DisputeSettlement::Answered, $bystander)), [403, 404]);
        $this->assertContains($this->refusal(fn () => $this->settleTime($entry, DisputeSettlement::WrittenOff, $author)), [403, 404]);
        $this->assertNotNull($entry->fresh()->client_disputed_at);
    }

    public function test_a_stale_version_an_undisputed_row_an_empty_reply_and_another_workspaces_row_are_refused(): void
    {
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $service = app(DisputeSettlements::class);

        $this->assertSame(422, $this->refusal(fn () => $this->settleTime($entry, DisputeSettlement::Answered)), 'Nothing is disputed.');
        $stale = AgentApiVersion::for($entry->fresh());
        $this->dispute($this->contact(), 'time', $entry->public_id);
        $this->assertSame(409, $this->refusal(fn () => $service->settleTime($this->workspace, $entry->public_id, $this->owner, DisputeSettlement::Answered, 'Synthetic reply', $stale)));
        $this->assertSame(422, $this->refusal(fn () => $this->settleTime($entry, DisputeSettlement::Answered, reply: '   ')));

        $other = Workspace::query()->create(['name' => 'Synthetic other', 'slug' => 'synthetic-settle-other']);
        $other->memberships()->create(['user_id' => $this->owner->id, 'role' => 'owner']);
        $this->assertSame(404, $this->refusal(fn () => $service->settleTime($other, $entry->public_id, $this->owner, DisputeSettlement::Answered, 'Synthetic reply', AgentApiVersion::for($entry->fresh()))));
        $this->assertNotNull($entry->fresh()->client_disputed_at);
    }

    // --- REST -----------------------------------------------------------

    public function test_rest_settles_with_confirmation_and_reports_the_client_review(): void
    {
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $this->dispute($this->contact(), 'time', $entry->public_id);
        $this->actingAsMcp($this->owner, [AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE]);
        $base = "/api/v1/workspaces/{$this->workspace->public_id}";

        $read = $this->getJson($base.'/time-entries')->assertOk()
            ->assertJsonPath('data.0.client_review.state', 'disputed')
            ->assertJsonPath('data.0.client_review.held', true)
            ->assertJsonPath('data.0.client_review.dispute_note', 'Synthetic question')
            ->json('data.0');
        $path = $base.'/time-entries/'.$entry->public_id.'/dispute/settle';
        $body = ['expected_version' => $read['version'], 'outcome' => 'answered', 'reply' => 'Synthetic reply'];

        $this->postJson($path, $body, ['Idempotency-Key' => 'synthetic-settle-unconfirmed'])->assertUnprocessable();
        $this->postJson($path, $body + ['confirm' => true], ['Idempotency-Key' => 'synthetic-settle'])->assertOk()
            ->assertJsonPath('data.client_review.state', 'undecided')
            ->assertJsonPath('data.client_review.held', false)
            ->assertJsonPath('data.client_review.settlement', 'answered')
            ->assertJsonPath('data.client_review.reply', 'Synthetic reply');
        $this->postJson($path, $body + ['confirm' => true], ['Idempotency-Key' => 'synthetic-settle'])->assertOk();
        $this->assertDatabaseHas('agent_mutation_audits', ['operation' => 'time_entries.settle_dispute', 'outcome' => 'replay']);
    }

    // --- review regressions -------------------------------------------

    /** @return array{0: int, 1: string} */
    private function stampDispute(ClientTimeEntry|ClientExpense $row): void
    {
        $row::query()->where('workspace_id', $this->workspace->id)->whereKey($row->id)->update([
            'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question', 'lock_version' => DB::raw('lock_version + 1'),
        ]);
    }

    public function test_every_settled_dispute_is_its_own_history_event(): void
    {
        $first = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $second = $this->hours('2026-09-21', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $expense = $this->expense('2026-09-22', 4200);
        $contact = $this->contact();
        $this->dispute($contact, 'time', $first->public_id);
        $this->dispute($contact, 'time', $second->public_id);
        $this->dispute($contact, 'expense', $expense->public_id);

        $this->settleTime($first, DisputeSettlement::Answered, reply: 'First reply');
        $this->settleTime($second, DisputeSettlement::Answered, reply: 'Second reply');
        $freshExpense = $expense->fresh();
        app(DisputeSettlements::class)->settleExpense($this->workspace, $freshExpense->public_id, $this->owner, DisputeSettlement::Answered, 'Expense reply', AgentApiVersion::for($freshExpense));
        // The same row disputed again and answered differently.
        $this->dispute($contact, 'time', $first->public_id);
        $this->settleTime($first, DisputeSettlement::Answered, reply: 'Another reply');

        $events = DB::table('client_company_activity')->where('action', 'dispute.answered')->orderBy('id')->pluck('payload')
            ->map(fn (string $payload): string => json_decode($payload, true)['reply'])->all();
        $this->assertSame(['First reply', 'Second reply', 'Expense reply', 'Another reply'], $events);
        $this->assertSame('Another reply', $first->fresh()->client_dispute_reply);
    }

    public function test_a_written_off_hour_cannot_be_made_billable_again_and_never_bills_if_it_is(): void
    {
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $this->dispute($this->contact(), 'time', $entry->public_id);
        $this->settleTime($entry, DisputeSettlement::WrittenOff);
        $this->assertFalse((bool) $entry->fresh()->is_billable);

        $mutations = app(TimeEntryMutationService::class);
        $unapproved = $mutations->unapprove($this->workspace, $entry->fresh(), $this->owner, AgentApiVersion::for($entry->fresh()));
        try {
            $mutations->update($this->workspace, $unapproved, $this->owner, ['is_billable' => true, 'expected_version' => AgentApiVersion::for($unapproved)]);
            $this->fail('A write-off was made billable again.');
        } catch (\DomainException $refused) {
            $this->assertStringContainsString('written off', $refused->getMessage());
        }
        $this->assertFalse((bool) $entry->fresh()->is_billable);

        // Even a row whose flag was changed underneath stays out of every bill:
        // as a draft, release leaves it a draft...
        ClientTimeEntry::query()->whereKey($entry->id)->update(['is_billable' => true]);
        $control = $this->hours('2026-09-21', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $this->generate();
        $this->assertSame('draft', $entry->fresh()->status);
        $this->assertFalse($this->onAnyLine($entry->fresh()));
        // ...and approved, no billing read takes it.
        ClientTimeEntry::query()->whereKey($entry->id)->update(['status' => 'approved']);
        app(ClientInvoicingService::class)->regenerateDraftInvoice($this->draft());
        $this->assertFalse($this->onAnyLine($entry->fresh()));
        $this->assertTrue($this->onAnyLine($control));
        $this->assertSame(0, ClientTimeEntry::query()->whereKey($entry->id)->notHeldForDispute()->count());
        $this->assertNull(SelectedTimeInvoiceTerms::forEntry($entry->fresh()));
        app(InvoiceLifecycleService::class)->issue($this->draft(), $this->workspace);
    }

    /** @return iterable<string, array{bool}> */
    public static function withdrawals(): iterable
    {
        yield 'both rows in one withdrawal' => [true];
        yield 'one withdrawal per row' => [false];
    }

    #[DataProvider('withdrawals')]
    public function test_two_disputes_on_one_ad_hoc_draft_both_come_off_it(bool $together): void
    {
        [$a, $b, $c] = array_map(fn (string $day): ClientTimeEntry => $this->hours($day, 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']), ['2026-09-20', '2026-09-21', '2026-09-22']);
        $draft = app(InvoiceFromTimeService::class)->create($this->workspace, $this->company, ['invoice_number' => 'SYNTH-ADHOC-TWO', 'currency' => 'USD'], [$a->public_id, $b->public_id, $c->public_id]);
        // Both disputes commit before either rebuild runs.
        $this->stampDispute($a);
        $this->stampDispute($b);

        $rebuilder = app(DisputedDraftRebuilder::class);
        if ($together) {
            $rebuilder->withdraw($this->workspace->id, [$a->id, $b->id], []);
        } else {
            $rebuilder->withdraw($this->workspace->id, [$a->id], []);
            $rebuilder->withdraw($this->workspace->id, [$b->id], []);
        }

        $this->assertFalse($this->onAnyLine($a));
        $this->assertFalse($this->onAnyLine($b));
        $this->assertTrue($this->onAnyLine($c));
        $this->assertSame(10000, (int) $draft->fresh()->total_amount);
        app(InvoiceLifecycleService::class)->issue($draft->fresh(), $this->workspace);
        $this->assertSame('issued', $draft->fresh()->status);
    }

    public function test_an_answered_subcontractor_hour_whose_period_has_no_draft_bills_on_the_next_one(): void
    {
        $flat = ['status' => 'approved', 'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD'];
        $earlier = $this->hours('2026-08-10', 120, $flat);
        $this->stampDispute($earlier);
        $this->generate();
        $draft = $this->draft();
        $this->assertFalse($this->onAnyLine($earlier), 'Held while disputed.');

        $this->settleTime($earlier, DisputeSettlement::Answered);

        $this->assertTrue($this->onAnyLine($earlier), 'Answered, it bills on the next draft. ');
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $earlier->id)->count());
        // Regenerating again bills it once, not twice.
        app(ClientInvoicingService::class)->regenerateDraftInvoice($draft->fresh());
        $this->assertSame(1, DB::table('client_invoice_line_time_entries')->where('client_time_entry_id', $earlier->id)->count());
    }

    public function test_an_unanswered_subcontractor_hour_from_an_earlier_period_is_not_carried(): void
    {
        $earlier = $this->hours('2026-08-10', 120, ['status' => 'approved', 'subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 5000, 'subcontractor_cost_currency' => 'USD']);
        $this->generate();

        $this->assertFalse($this->onAnyLine($earlier), 'Only an answered dispute is carried forward.');
    }

    public function test_the_interim_time_fingerprint_moves_with_a_dispute(): void
    {
        $this->agreement->forceFill(['billing_cadence' => 'quarterly'])->save();
        $entry = $this->hours('2026-07-10', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD']);
        $draft = new ClientInvoice(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'service_period_start' => '2026-07-01', 'service_period_end' => '2026-07-31']);
        $generator = app(InterimOverageGenerator::class);
        $fingerprint = fn (): string => DB::transaction(fn (): string => $generator->lockTimeFingerprint($draft, $this->agreement->fresh(), $generator->lockCycleClaims($draft, $this->agreement->fresh())[0]));

        $before = $fingerprint();
        $this->assertSame($before, $fingerprint());
        $this->stampDispute($entry);
        $this->assertNotSame($before, $fingerprint(), 'A dispute changes what the ledger counts.');
    }

    public function test_a_settlement_replay_answers_only_someone_who_can_still_read_the_entry(): void
    {
        $colleague = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $colleague->id, 'role' => 'member']);
        $manager = User::factory()->create();
        $membership = $this->workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        ClientProjectMembership::query()->create(['workspace_id' => $this->workspace->id, 'client_project_id' => $this->project->id, 'user_id' => $manager->id, 'role' => 'contributor']);
        $entry = $this->hours('2026-09-20', 60, ['status' => 'approved', 'billing_rate_amount' => 10000, 'currency' => 'USD'], $colleague);
        $this->dispute($this->contact(), 'time', $entry->public_id);
        $base = "/api/v1/workspaces/{$this->workspace->public_id}";
        $path = $base.'/time-entries/'.$entry->public_id.'/dispute/settle';
        $body = ['expected_version' => AgentApiVersion::for($entry->fresh()), 'outcome' => 'answered', 'reply' => 'Synthetic reply', 'confirm' => true];

        $this->actingAsMcp($manager, [AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE]);
        $this->postJson($path, $body, ['Idempotency-Key' => 'synthetic-settle-replay'])->assertOk()
            ->assertJsonPath('data.client_review.settlement', 'answered');
        $events = DB::table('client_company_activity')->where('action', 'dispute.answered')->count();
        // Replayed while still a manager: the existing result, no new event.
        $this->postJson($path, $body, ['Idempotency-Key' => 'synthetic-settle-replay'])->assertOk()
            ->assertJsonPath('data.id', $entry->public_id);
        $this->assertSame($events, DB::table('client_company_activity')->where('action', 'dispute.answered')->count());
        // The same key with another payload is still a conflict.
        $this->assertGreaterThanOrEqual(409, $this->postJson($path, ['reply' => 'Changed'] + $body, ['Idempotency-Key' => 'synthetic-settle-replay'])->status());

        // Demoted to a contributor who keeps the project, they no longer read
        // a colleague's hour, and the replay gives them nothing of it.
        $membership->update(['role' => 'member']);
        $this->actingAsMcp($manager->fresh(), [AgentApiScopes::TIME_READ, AgentApiScopes::TIME_WRITE]);
        $replay = $this->postJson($path, $body, ['Idempotency-Key' => 'synthetic-settle-replay']);
        $replay->assertNotFound();
        $this->assertStringNotContainsString('Synthetic reply', (string) $replay->getContent());
    }
}
