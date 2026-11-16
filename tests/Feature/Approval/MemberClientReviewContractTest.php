<?php

namespace Tests\Feature\Approval;

use App\Models\AgentPrincipal;
use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\ClientTimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\ProjectRole;
use App\Support\Approval\ClientApprovalSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * What a member who cannot manage the workspace sees of a client's review
 * (#718, owner decision of 2026-11-16): the same derived state on every
 * surface - the client approved by paying, a dispute held by a charge - as the
 * manager sees, but never the invoice behind it on a surface that withholds it.
 */
final class MemberClientReviewContractTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private User $member;

    private ClientTimeEntry $entry;

    private ClientExpense $expense;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-04-15 12:00:00');
        $this->workspace = Workspace::query()->create(['name' => 'Synthetic review contract', 'slug' => 'synthetic-review-contract']);
        $owner = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $this->member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $this->member->id, 'role' => 'member']);
        $this->company = ClientCompany::query()->create(['workspace_id' => $this->workspace->id, 'name' => 'Synthetic review client', 'slug' => 'synthetic-review-client']);
        $project = ClientProject::query()->create(['workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'name' => 'Synthetic review project', 'status' => 'active']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id, 'client_project_id' => $project->id, 'user_id' => $this->member->id, 'role' => ProjectRole::Contributor->value,
        ]);

        $invoice = ClientInvoice::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'invoice_number' => 'SYN-REVIEW-1',
            'status' => 'paid', 'currency' => 'USD', 'subtotal_amount' => 16250, 'tax_amount' => 0, 'total_amount' => 16250, 'balance_amount' => 0,
            'is_visible_to_client' => true,
        ]);
        $line = fn (string $type, int $amount): ClientInvoiceLine => ClientInvoiceLine::query()->create([
            'workspace_id' => $this->workspace->id, 'client_invoice_id' => $invoice->id, 'client_project_id' => $project->id, 'type' => $type,
            'description' => 'Synthetic '.$type, 'quantity' => '1', 'unit_amount' => $amount, 'total_amount' => $amount, 'tax_amount' => 0, 'sort_order' => 0,
        ]);
        $paidByClient = ['client_approved_at' => '2026-04-02 10:00:00', 'client_approval_source' => ClientApprovalSource::Payment->value];

        $this->entry = ClientTimeEntry::query()->create([
            'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id,
            'user_id' => $this->member->id, 'worked_on' => '2026-03-14', 'minutes' => 60, 'description' => 'Synthetic review work',
            'status' => 'invoiced', 'billing_rate_amount' => 12000, 'currency' => 'USD',
        ]);
        $this->entry->forceFill($paidByClient)->save();
        DB::table('client_invoice_line_time_entries')->insert([
            'workspace_id' => $this->workspace->id, 'client_invoice_line_id' => $line('time', 12000)->id, 'client_time_entry_id' => $this->entry->id,
        ]);

        $this->expense = ClientExpense::query()->create([
            'public_id' => (string) Str::uuid(), 'workspace_id' => $this->workspace->id, 'client_company_id' => $this->company->id, 'client_project_id' => $project->id,
            'created_by_user_id' => $this->member->id, 'spent_on' => '2026-03-14', 'amount' => 4250, 'currency' => 'USD',
            'description' => 'Synthetic review courier', 'status' => 'invoiced',
        ]);
        $this->expense->forceFill($paidByClient + ['client_invoice_line_id' => $line('expense', 4250)->id])->save();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_time_sheet_and_the_expense_page_show_a_member_the_review(): void
    {
        $base = "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}";

        $this->actingAs($this->member)->get($base.'/time')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('months.0.entries.0.client_review.state', 'approved_by_payment'));

        $expenses = $this->actingAs($this->member)->get($base.'/expenses')->assertOk();
        $expenses->assertInertia(fn (Assert $page) => $page
            ->where('expenses.0.client_review.state', 'approved_by_payment')
            ->where('expenses.0.billing', null));
        $this->assertStringNotContainsString('SYN-REVIEW-1', (string) $expenses->getContent());
    }

    public function test_the_time_and_expense_apis_show_a_member_the_review(): void
    {
        Passport::actingAs(AgentPrincipal::query()->findOrFail($this->member->id), ['time:read', 'expenses:read']);
        $base = "/api/v1/workspaces/{$this->workspace->public_id}";

        $this->getJson($base.'/time-entries')->assertOk()
            ->assertJsonPath('data.0.id', $this->entry->public_id)
            ->assertJsonPath('data.0.client_review.state', 'approved_by_payment');
        $this->getJson($base.'/expenses')->assertOk()
            ->assertJsonPath('data.0.id', $this->expense->public_id)
            ->assertJsonPath('data.0.client_review.state', 'approved_by_payment');
    }
}
