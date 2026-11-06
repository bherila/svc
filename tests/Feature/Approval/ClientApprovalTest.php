<?php

namespace Tests\Feature\Approval;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Approval\PortalApprovalQueue;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\ReceivedPaymentData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A client contact's "To approve": who sees it, what is on it, what approving
 * and disputing write, and the refusals that matter most - another client's
 * rows, another workspace's, and a member who is also the client's contact
 * approving their own work. Today is 2026-11-06.
 */
final class ClientApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    /** The workspace member who logs the hours. */
    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-06 12:00:00');

        $this->workspace = Workspace::query()->create(['name' => 'Synthetic Vendor WS', 'slug' => 'synthetic-approvals']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Synthetic Approving Client', 'slug' => 'synthetic-approving-client',
            'billing_email' => 'ap@synthetic.example.test',
        ]);
        $this->project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic Project', 'is_visible_to_client' => true]);
        $this->worker = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->worker->id, 'role' => 'member']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function contact(string $email = 'ap@synthetic.example.test', ?bool $grant = null, ?ClientCompany $company = null, string $scope = ClientCompanyMembership::SCOPE_COMPANY): User
    {
        $user = User::factory()->create(['email' => $email]);
        ClientCompanyMembership::query()->create([
            'client_company_id' => ($company ?? $this->company)->id, 'user_id' => $user->id, 'role' => 'client',
            'access_scope' => $scope, 'can_approve_time_and_expenses' => $grant,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $overrides */
    private function hours(string $day, int $minutes = 60, array $overrides = [], ?ClientCompany $company = null, ?Workspace $workspace = null): ClientTimeEntry
    {
        $workspace ??= $this->workspace;
        $company ??= $this->company;

        return ClientTimeEntry::query()->create($overrides + [
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id,
            'client_project_id' => ($company->id === $this->company->id ? $this->project : ClientProject::query()->where('client_company_id', $company->id)->firstOrFail())->id,
            'user_id' => $this->worker->id, 'worked_on' => $day, 'minutes' => $minutes,
            'description' => 'Synthetic INTERNAL note', 'client_visible_description' => 'Synthetic client text',
            'is_visible_to_client' => true, 'is_billable' => true, 'status' => 'approved',
            'billing_rate_amount' => 12345, 'currency' => 'USD',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function expense(string $day, int $amount = 5000, array $overrides = []): ClientExpense
    {
        return ClientExpense::query()->create($overrides + [
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id,
            'created_by_user_id' => $this->worker->id, 'spent_on' => $day, 'amount' => $amount, 'currency' => 'USD',
            'description' => 'Synthetic expense', 'status' => 'approved',
        ]);
    }

    private function home(User $user): TestResponse
    {
        return $this->actingAs($user)->get('/portal/'.$this->company->public_id)->assertOk();
    }

    /** @param array<string, mixed> $data */
    private function approve(User $user, array $data, ?ClientCompany $company = null): TestResponse
    {
        return $this->actingAs($user)->from('/portal/x')->post('/portal/'.($company ?? $this->company)->public_id.'/approvals/approve', $data);
    }

    /** @param array<string, mixed> $data */
    private function dispute(User $user, array $data): TestResponse
    {
        return $this->actingAs($user)->from('/portal/x')->post('/portal/'.$this->company->public_id.'/approvals/dispute', $data);
    }

    // --- who sees it ---------------------------------------------------

    public function test_the_billing_contact_sees_to_approve_by_default(): void
    {
        $this->hours('2026-10-14');

        $this->home($this->contact())->assertInertia(fn (Assert $page) => $page
            ->component('portal/home')
            ->where('to_approve.approve_href', '/portal/'.$this->company->public_id.'/approvals/approve')
            ->where('to_approve.dispute_href', '/portal/'.$this->company->public_id.'/approvals/dispute')
            ->has('to_approve.groups', 1));
    }

    public function test_another_contact_does_not_see_it_unless_granted(): void
    {
        $this->hours('2026-10-14');

        $this->home($this->contact('other@synthetic.example.test'))->assertInertia(fn (Assert $page) => $page->where('to_approve', null));
        $this->home($this->contact('granted@synthetic.example.test', true))->assertInertia(fn (Assert $page) => $page->has('to_approve.groups', 1));
    }

    public function test_an_explicit_no_removes_it_from_the_billing_contact(): void
    {
        $this->hours('2026-10-14');

        $this->home($this->contact('ap@synthetic.example.test', false))->assertInertia(fn (Assert $page) => $page->where('to_approve', null));
    }

    public function test_an_owner_previewing_the_portal_has_no_approvals_without_a_membership(): void
    {
        $owner = User::factory()->create(['email' => 'ap@synthetic.example.test']);
        $this->workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->hours('2026-10-14');

        $this->home($owner)->assertInertia(fn (Assert $page) => $page->where('to_approve', null));
        $this->approve($owner, ['period' => '2026-10'])->assertForbidden();
    }

    // --- what is on it ---------------------------------------------------

    public function test_it_lists_hours_and_expenses_by_month_newest_first_and_never_prices_the_hours(): void
    {
        $this->hours('2026-10-14', 90);
        $this->hours('2026-10-31', 30);
        $this->hours('2026-09-30', 60);
        $this->expense('2026-10-02', 4200);

        $this->home($this->contact())->assertInertia(fn (Assert $page) => $page
            ->where('to_approve.groups.0.period', '2026-10')
            ->where('to_approve.groups.0.period_label', 'October 2026')
            ->where('to_approve.groups.0.vendor', 'Synthetic Vendor WS')
            ->where('to_approve.groups.0.hours.minutes', 120)
            ->where('to_approve.groups.0.hours.pending_count', 2)
            ->where('to_approve.groups.0.expenses.amount', 4200)
            ->where('to_approve.groups.0.expenses.currency', 'USD')
            ->where('to_approve.groups.1.period', '2026-09')
            ->where('to_approve.groups.1.hours.minutes', 60)
            ->where('to_approve.groups.1.expenses.pending_count', 0)
            ->where('to_approve.groups.0.hours.entries.0.description', 'Synthetic client text')
            ->where('to_approve.groups.0.hours.entries.0.project', 'Synthetic Project'));
    }

    public function test_no_rate_amount_or_internal_note_reaches_the_client(): void
    {
        $this->hours('2026-10-14');
        $payload = json_encode($this->queue($this->contact()), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('INTERNAL', $payload);
        $this->assertStringNotContainsString('12345', $payload);
        $this->assertStringNotContainsString('billing_rate', $payload);
    }

    public function test_only_billable_client_visible_ready_to_bill_time_is_listed(): void
    {
        $this->hours('2026-10-01', 1);
        $this->hours('2026-10-02', 2, ['status' => 'draft']);
        $this->hours('2026-10-03', 3, ['is_billable' => false]);
        $this->hours('2026-10-04', 4, ['is_visible_to_client' => false]);
        $this->hours('2026-10-05', 5, ['client_visible_description' => null]);
        $this->hours('2026-10-06', 6, ['subcontractor_billing_mode' => 'direct']);
        $this->hours('2026-10-07', 7, ['subcontractor_billing_mode' => 'flat_hourly', 'subcontractor_cost_amount' => 100, 'subcontractor_cost_currency' => 'USD']);
        $this->hours('2026-10-08', 8, ['status' => 'invoiced']);

        $minutes = $this->queue($this->contact())['groups'][0]['hours']['entries'];

        $this->assertEqualsCanonicalizing([1, 7, 8], array_column($minutes, 'minutes'));
    }

    public function test_expenses_not_ready_to_bill_are_not_listed(): void
    {
        $this->expense('2026-10-01', 100, ['status' => 'draft']);
        $this->expense('2026-10-02', 200);

        $items = $this->queue($this->contact())['groups'][0]['expenses']['items'];

        $this->assertSame([200], array_column($items, 'amount'));
    }

    public function test_mixed_currency_expenses_have_no_total(): void
    {
        $this->expense('2026-10-01', 100);
        $this->expense('2026-10-02', 200, ['currency' => 'EUR']);

        $expenses = $this->queue($this->contact())['groups'][0]['expenses'];

        $this->assertNull($expenses['amount']);
        $this->assertNull($expenses['currency']);
        $this->assertSame(2, $expenses['pending_count']);
    }

    public function test_another_client_and_another_workspace_are_never_listed(): void
    {
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Other Client', 'slug' => 'other-approving-client']);
        ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $other->id, 'name' => 'Other Project']);
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere WS', 'slug' => 'elsewhere-approvals']);
        $elsewhereCompany = ClientCompany::query()->create(['workspace_id' => $elsewhere->id, 'name' => 'Synthetic Approving Client', 'slug' => 'synthetic-approving-client', 'billing_email' => 'ap@synthetic.example.test']);
        ClientProject::query()->create(['workspace_id' => $elsewhere->id, 'client_company_id' => $elsewhereCompany->id, 'name' => 'Elsewhere Project']);
        $this->hours('2026-10-14', 11, [], $other);
        $this->hours('2026-10-14', 22, [], $elsewhereCompany, $elsewhere);
        $mine = $this->hours('2026-10-14', 33);

        $groups = $this->queue($this->contact())['groups'];

        $this->assertSame([$mine->public_id], array_column($groups[0]['hours']['entries'], 'id'));
    }

    public function test_a_project_scoped_contact_sees_only_their_projects(): void
    {
        $other = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Second Project', 'is_visible_to_client' => true]);
        $this->hours('2026-10-14', 10);
        $this->hours('2026-10-14', 20, ['client_project_id' => $other->id]);
        $this->expense('2026-10-03', 300, ['client_project_id' => $other->id]);
        $user = $this->contact('ap@synthetic.example.test', null, null, ClientCompanyMembership::SCOPE_PROJECTS);
        $membership = ClientCompanyMembership::query()->where('user_id', $user->id)->firstOrFail();
        DB::table('client_portal_project_access')->insert(['workspace_id' => $this->workspace->id, 'client_company_membership_id' => $membership->id, 'client_project_id' => $this->project->id, 'created_at' => now(), 'updated_at' => now()]);

        $group = $this->queue($user)['groups'][0];

        $this->assertSame([10], array_column($group['hours']['entries'], 'minutes'));
        $this->assertSame([], $group['expenses']['items']);
    }

    // --- approving -----------------------------------------------------------

    public function test_approving_a_month_stamps_hours_and_expenses_and_leaves_other_months_alone(): void
    {
        $contact = $this->contact();
        $october = $this->hours('2026-10-14');
        $september = $this->hours('2026-09-30');
        $expense = $this->expense('2026-10-02');
        $septemberExpense = $this->expense('2026-09-01');

        $this->approve($contact, ['period' => '2026-10'])->assertRedirect('/portal/x')->assertSessionHas('approval_result.time', 1)->assertSessionHas('approval_result.expenses', 1);

        foreach ([$october->fresh(), $expense->fresh()] as $row) {
            $this->assertNotNull($row->client_approved_at);
            $this->assertSame($contact->id, $row->client_approved_by_user_id);
            $this->assertSame('portal', $row->client_approval_source);
        }
        $this->assertNull($september->fresh()->client_approved_at);
        $this->assertNull($septemberExpense->fresh()->client_approved_at);
        // The operator's check is untouched.
        $this->assertSame('approved', $october->fresh()->status);
        $this->assertNull($october->fresh()->approved_by_user_id);
        $this->assertSame('approved', $expense->fresh()->status);

        $this->home($contact)->assertInertia(fn (Assert $page) => $page->has('to_approve.groups', 1)->where('to_approve.groups.0.period', '2026-09'));
    }

    public function test_the_first_and_last_day_of_the_month_are_in_it_and_their_neighbours_are_not(): void
    {
        $contact = $this->contact();
        $first = $this->hours('2026-10-01');
        $last = $this->hours('2026-10-31');
        $before = $this->hours('2026-09-30');
        $after = $this->hours('2026-11-01');

        $this->approve($contact, ['period' => '2026-10'])->assertSessionHasNoErrors();

        $this->assertNotNull($first->fresh()->client_approved_at);
        $this->assertNotNull($last->fresh()->client_approved_at);
        $this->assertNull($before->fresh()->client_approved_at);
        $this->assertNull($after->fresh()->client_approved_at);
    }

    public function test_named_rows_are_approved_alone_and_partial_approval_leaves_the_rest_waiting(): void
    {
        $contact = $this->contact();
        $one = $this->hours('2026-10-14');
        $two = $this->hours('2026-10-15');
        $expense = $this->expense('2026-10-02');

        $this->approve($contact, ['time_ids' => [$one->public_id]])->assertSessionHasNoErrors();

        $this->assertNotNull($one->fresh()->client_approved_at);
        $this->assertNull($two->fresh()->client_approved_at);
        $this->assertNull($expense->fresh()->client_approved_at);

        $this->approve($contact, ['expense_ids' => [$expense->public_id]])->assertSessionHasNoErrors();
        $this->assertNotNull($expense->fresh()->client_approved_at);
        $this->assertNull($two->fresh()->client_approved_at);
    }

    public function test_a_request_naming_nothing_is_refused(): void
    {
        $this->approve($this->contact(), [])->assertStatus(422);
        $this->approve($this->contact('granted@synthetic.example.test', true), ['period' => '2026-13'])->assertSessionHasErrors('period');
    }

    public function test_a_contact_without_the_permission_cannot_approve(): void
    {
        $row = $this->hours('2026-10-14');

        $this->approve($this->contact('other@synthetic.example.test'), ['period' => '2026-10'])->assertForbidden();
        $this->approve($this->contact('ap2@synthetic.example.test', false), ['time_ids' => [$row->public_id]])->assertForbidden();
        $this->assertNull($row->fresh()->client_approved_at);
    }

    public function test_another_clients_contact_cannot_approve_this_clients_rows(): void
    {
        $other = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Other Client', 'slug' => 'other-approving-client']);
        $row = $this->hours('2026-10-14');
        $stranger = $this->contact('ap@synthetic.example.test', true, $other);

        // Not a member of this client's portal at all: the client does not resolve for them.
        $this->approve($stranger, ['period' => '2026-10'])->assertNotFound();
        // A member of their own, naming this client's row: it does not resolve.
        $this->approve($stranger, ['time_ids' => [$row->public_id]], $other)->assertSessionHasErrors('approval');
        $this->assertNull($row->fresh()->client_approved_at);
    }

    public function test_a_row_that_is_no_longer_waiting_is_a_refusal_not_a_no_op(): void
    {
        $contact = $this->contact();
        $row = $this->hours('2026-10-14');
        $this->approve($contact, ['time_ids' => [$row->public_id]])->assertSessionHasNoErrors();

        $this->approve($contact, ['time_ids' => [$row->public_id]])->assertSessionHasErrors('approval');
    }

    public function test_a_draft_row_cannot_be_approved_by_naming_it(): void
    {
        $draft = $this->hours('2026-10-14', 60, ['status' => 'draft']);

        $this->approve($this->contact(), ['time_ids' => [$draft->public_id]])->assertSessionHasErrors('approval');
        $this->assertNull($draft->fresh()->client_approved_at);
    }

    // --- disputing -------------------------------------------------------------

    public function test_a_dispute_with_a_note_is_recorded_and_the_row_leaves_the_waiting_list(): void
    {
        $contact = $this->contact();
        $row = $this->hours('2026-10-14');

        $this->dispute($contact, ['kind' => 'time', 'id' => $row->public_id, 'note' => '  Too many hours  '])->assertSessionHasNoErrors();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh->client_disputed_at);
        $this->assertSame($contact->id, $fresh->client_disputed_by_user_id);
        $this->assertSame('Too many hours', $fresh->client_dispute_note);
        $this->assertNull($fresh->client_approved_at);
        $this->assertSame('approved', $fresh->status);

        $entries = $this->queue($contact)['groups'][0]['hours'];
        $this->assertSame(0, $entries['pending_count']);
        $this->assertSame('disputed', $entries['entries'][0]['state']);
        $this->assertSame('Too many hours', $entries['entries'][0]['dispute_note']);
    }

    public function test_a_dispute_without_a_note_is_recorded_too(): void
    {
        $contact = $this->contact();
        $expense = $this->expense('2026-10-14');
        $hours = $this->hours('2026-10-14');

        $this->dispute($contact, ['kind' => 'expense', 'id' => $expense->public_id, 'note' => '   '])->assertSessionHasNoErrors();
        $this->dispute($contact, ['kind' => 'time', 'id' => $hours->public_id])->assertSessionHasNoErrors();

        foreach ([$expense->fresh(), $hours->fresh()] as $row) {
            $this->assertNotNull($row->client_disputed_at);
            $this->assertNull($row->client_dispute_note);
        }
    }

    public function test_approving_a_month_does_not_override_a_dispute_and_a_dispute_clears_an_approval(): void
    {
        $contact = $this->contact();
        $disputed = $this->hours('2026-10-14');
        $approved = $this->hours('2026-10-15');
        $this->dispute($contact, ['kind' => 'time', 'id' => $disputed->public_id])->assertSessionHasNoErrors();
        $this->approve($contact, ['time_ids' => [$approved->public_id]])->assertSessionHasNoErrors();

        $this->approve($contact, ['period' => '2026-10'])->assertSessionHasNoErrors();
        $this->assertNull($disputed->fresh()->client_approved_at);

        // Approved rows are no longer on offer to dispute.
        $this->dispute($contact, ['kind' => 'time', 'id' => $approved->public_id])->assertSessionHasErrors('approval');
    }

    public function test_a_note_longer_than_the_limit_is_refused(): void
    {
        $row = $this->hours('2026-10-14');

        $this->dispute($this->contact(), ['kind' => 'time', 'id' => $row->public_id, 'note' => str_repeat('x', 1001)])->assertSessionHasErrors('note');
        $this->assertNull($row->fresh()->client_disputed_at);
    }

    // --- nobody approves their own work -------------------------------------------

    /** A workspace member who is also the client's contact, and who logged some of the hours. */
    private function memberWhoIsAlsoAContact(): User
    {
        $user = $this->contact('ap@synthetic.example.test');
        $this->workspace->memberships()->create(['user_id' => $user->id, 'role' => 'member']);

        return $user;
    }

    public function test_a_member_who_is_also_a_contact_cannot_approve_their_own_hours_by_name(): void
    {
        $person = $this->memberWhoIsAlsoAContact();
        $own = $this->hours('2026-10-14', 60, ['user_id' => $person->id]);
        $theirs = $this->hours('2026-10-15');

        $this->approve($person, ['time_ids' => [$own->public_id]])->assertSessionHasErrors('approval');
        // Naming one of each refuses the whole request: nothing half-approved.
        $this->approve($person, ['time_ids' => [$own->public_id, $theirs->public_id]])->assertSessionHasErrors('approval');

        $this->assertNull($own->fresh()->client_approved_at);
        $this->assertNull($theirs->fresh()->client_approved_at);
    }

    public function test_approving_a_month_skips_their_own_hours_and_says_so(): void
    {
        $person = $this->memberWhoIsAlsoAContact();
        $own = $this->hours('2026-10-14', 60, ['user_id' => $person->id]);
        $ownExpense = $this->expense('2026-10-03', 100, ['created_by_user_id' => $person->id]);
        $theirs = $this->hours('2026-10-15');
        $theirExpense = $this->expense('2026-10-04');

        $this->approve($person, ['period' => '2026-10'])
            ->assertSessionHas('approval_result.time', 1)
            ->assertSessionHas('approval_result.expenses', 1)
            ->assertSessionHas('approval_result.skipped_own', 2);

        $this->assertNull($own->fresh()->client_approved_at);
        $this->assertNull($ownExpense->fresh()->client_approved_at);
        $this->assertNotNull($theirs->fresh()->client_approved_at);
        $this->assertNotNull($theirExpense->fresh()->client_approved_at);
    }

    public function test_they_cannot_approve_or_dispute_their_own_expense_by_name(): void
    {
        $person = $this->memberWhoIsAlsoAContact();
        $own = $this->expense('2026-10-03', 100, ['created_by_user_id' => $person->id]);

        $this->approve($person, ['expense_ids' => [$own->public_id]])->assertSessionHasErrors('approval');
        $this->dispute($person, ['kind' => 'expense', 'id' => $own->public_id])->assertSessionHasErrors('approval');

        $this->assertNull($own->fresh()->client_approved_at);
        $this->assertNull($own->fresh()->client_disputed_at);
    }

    public function test_an_expense_whose_creator_is_gone_can_be_approved(): void
    {
        $contact = $this->contact();
        $row = $this->expense('2026-10-03', 100, ['created_by_user_id' => null]);

        $this->approve($contact, ['expense_ids' => [$row->public_id]])->assertSessionHasNoErrors();

        $this->assertNotNull($row->fresh()->client_approved_at);
    }

    // --- paying approves ---------------------------------------------------------------

    private function invoiceWith(ClientTimeEntry $entry, ?ClientExpense $expense = null, int $total = 10000): ClientInvoice
    {
        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'invoice_number' => 'SYN-PAY-1', 'status' => 'issued',
            'currency' => 'USD', 'issue_date' => '2026-11-01', 'due_date' => '2026-11-30', 'subtotal_amount' => $total, 'tax_amount' => 0,
            'total_amount' => $total, 'paid_amount' => 0, 'balance_amount' => $total, 'is_visible_to_client' => true,
        ]);
        $line = ClientInvoiceLine::query()->create(['workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'type' => 'service', 'description' => 'Synthetic line', 'quantity' => 1, 'unit_amount' => $total, 'tax_amount' => 0, 'total_amount' => $total, 'sort_order' => 0]);
        $line->timeEntries()->attach($entry->id, ['workspace_id' => $this->workspace->id]);
        $expense?->forceFill(['client_invoice_line_id' => $line->id, 'status' => 'invoiced'])->save();
        $entry->forceFill(['status' => 'invoiced'])->save();

        return $invoice;
    }

    private function pay(ClientInvoice $invoice, int $amount, ?int $recordedBy = null): void
    {
        app(RecordReceivedPayment::class)->record(
            $this->workspace,
            $invoice,
            ReceivedPaymentData::from(['amount' => $amount, 'currency' => 'USD', 'received_on' => '2026-11-06', 'method' => 'bank transfer']),
            null,
            null,
            $recordedBy,
        );
    }

    public function test_paying_in_full_approves_the_hours_and_expenses_on_the_invoice_and_names_no_one(): void
    {
        $entry = $this->hours('2026-10-14');
        $expense = $this->expense('2026-10-02');
        $elsewhere = $this->hours('2026-10-20');
        $invoice = $this->invoiceWith($entry, $expense);

        $this->pay($invoice->fresh(), 10000);

        foreach ([$entry->fresh(), $expense->fresh()] as $row) {
            $this->assertNotNull($row->client_approved_at);
            $this->assertSame('payment', $row->client_approval_source);
            $this->assertNull($row->client_approved_by_user_id);
        }
        $this->assertNull($elsewhere->fresh()->client_approved_at, 'Only what is on the paid invoice.');
        // What was paid for is off the waiting list; the unrelated hour is not.
        $this->assertSame([$elsewhere->public_id], array_column($this->queue($this->contact())['groups'][0]['hours']['entries'], 'id'));
    }

    public function test_a_part_payment_approves_nothing(): void
    {
        $entry = $this->hours('2026-10-14');
        $invoice = $this->invoiceWith($entry);

        $this->pay($invoice->fresh(), 4000);

        $this->assertNull($entry->fresh()->client_approved_at);
    }

    public function test_a_pending_payment_approves_nothing(): void
    {
        $entry = $this->hours('2026-10-14');
        $invoice = $this->invoiceWith($entry);

        app(RecordReceivedPayment::class)->record($this->workspace, $invoice->fresh(), ReceivedPaymentData::from(['amount' => 10000, 'currency' => 'USD', 'received_on' => '2026-11-06', 'method' => 'bank transfer', 'status' => 'pending'], includeBookkeeping: true));

        $this->assertNull($entry->fresh()->client_approved_at);
    }

    public function test_the_person_recording_the_payment_never_approves_their_own_work_through_it(): void
    {
        $own = $this->hours('2026-10-14', 60, ['user_id' => $this->worker->id]);
        $theirs = $this->hours('2026-10-15', 60, ['user_id' => User::factory()->create()->id]);
        $ownExpense = $this->expense('2026-10-02', 100, ['created_by_user_id' => $this->worker->id]);
        $invoice = $this->invoiceWith($own, $ownExpense);
        $line = $invoice->lines()->firstOrFail();
        $line->timeEntries()->attach($theirs->id, ['workspace_id' => $this->workspace->id]);

        $this->pay($invoice->fresh(), 10000, recordedBy: $this->worker->id);

        $this->assertNull($own->fresh()->client_approved_at);
        $this->assertNull($ownExpense->fresh()->client_approved_at);
        $this->assertNotNull($theirs->fresh()->client_approved_at);
    }

    public function test_payment_does_not_override_a_clients_own_decision(): void
    {
        $contact = $this->contact();
        $approved = $this->hours('2026-10-14');
        $disputed = $this->hours('2026-10-15');
        $invoice = $this->invoiceWith($approved);
        $invoice->lines()->firstOrFail()->timeEntries()->attach($disputed->id, ['workspace_id' => $this->workspace->id]);
        $this->approve($contact, ['time_ids' => [$approved->public_id]])->assertSessionHasNoErrors();
        $this->dispute($contact, ['kind' => 'time', 'id' => $disputed->public_id, 'note' => 'No'])->assertSessionHasNoErrors();

        $this->pay($invoice->fresh(), 10000);

        $this->assertSame('portal', $approved->fresh()->client_approval_source);
        $this->assertSame($contact->id, $approved->fresh()->client_approved_by_user_id);
        $this->assertNull($disputed->fresh()->client_approved_at);
        $this->assertNotNull($disputed->fresh()->client_disputed_at);
    }

    public function test_a_refund_undoes_a_payment_approval_without_writing_and_leaves_a_portal_one(): void
    {
        $contact = $this->contact();
        $byPayment = $this->hours('2026-10-14');
        $byPerson = $this->hours('2026-10-15');
        $invoice = $this->invoiceWith($byPayment);
        $invoice->lines()->firstOrFail()->timeEntries()->attach($byPerson->id, ['workspace_id' => $this->workspace->id]);
        $this->approve($contact, ['time_ids' => [$byPerson->public_id]])->assertSessionHasNoErrors();
        $this->pay($invoice->fresh(), 10000);
        $this->assertSame([], array_column($this->queue($contact)['groups'] ?? [], 'key'));

        // The money goes back: the invoice is no longer paid in full.
        $invoice->payments()->firstOrFail()->forceFill(['refunded_amount' => 10000])->save();
        $invoice->fresh()->forceFill(['status' => 'issued', 'paid_amount' => 0, 'balance_amount' => 10000])->save();

        $groups = $this->queue($contact)['groups'];
        $this->assertSame([$byPayment->public_id], array_column($groups[0]['hours']['entries'], 'id'));
        $this->assertSame('payment', $byPayment->fresh()->client_approval_source, 'Nothing was rewritten.');
        $this->assertSame('portal', $byPerson->fresh()->client_approval_source);
    }

    /** @return array<string, mixed> */
    private function queue(User $user): array
    {
        return app(PortalApprovalQueue::class)->for($this->company, $user) ?? ['groups' => []];
    }
}
