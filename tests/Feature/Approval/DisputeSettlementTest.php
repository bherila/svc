<?php

namespace Tests\Feature\Approval;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiScopes;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
}
