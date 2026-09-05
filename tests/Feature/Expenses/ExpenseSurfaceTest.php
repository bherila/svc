<?php

namespace Tests\Feature\Expenses;

use App\Models\ClientCompany;
use App\Models\ClientExpense;
use App\Models\ClientProject;
use App\Models\ClientProjectMembership;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\AgentApi\ProjectRole;
use App\Support\Expenses\ExpenseStatus;
use App\Support\Expenses\NewExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\AssertsSurfaceIsolation;
use Tests\TestCase;

/**
 * The manager's expense surface, over the domain that already existed.
 *
 * `WorkspaceExpenses` arrived in #228 with the tenancy rule, the lifecycle
 * rules and the locking already in it, and its docblock names this screen as
 * one of the callers it was built for. So these tests are about the HTTP
 * boundary rather than about the rules: that the surface reaches the domain
 * rather than around it, that it refuses what the domain refuses, and that a
 * viewer sees only their own tenant.
 */
final class ExpenseSurfaceTest extends TestCase
{
    use AssertsSurfaceIsolation;
    use RefreshDatabase;

    private Workspace $workspace;

    private ClientCompany $company;

    private ClientProject $project;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = User::factory()->create();
        $this->workspace = Workspace::query()->create(['name' => 'Expenses', 'slug' => 'expenses']);
        $this->workspace->memberships()->create(['user_id' => $this->manager->id, 'role' => 'admin']);
        $this->company = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Expense Client', 'slug' => 'expense-client',
        ]);
        $this->project = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Expense Project',
            'status' => 'active',
        ]);
    }

    public function test_the_page_lists_this_clients_expenses(): void
    {
        $expense = $this->expense(['description' => 'Courier for signed contract']);

        $this->actingAs($this->manager)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/expenses')
                ->has('expenses', 1)
                ->where('expenses.0.id', $expense->public_id)
                ->where('expenses.0.description', 'Courier for signed contract')
                ->where('expenses.0.amount', 4250)
                ->where('expenses.0.status', 'draft')
                // A draft may be edited, approved and discarded, and cannot be
                // returned to a state it is already in.
                ->where('expenses.0.can_edit', true)
                ->where('expenses.0.can_approve', true)
                ->where('expenses.0.can_unapprove', false)
                ->where('expenses.0.can_discard', true));
    }

    /**
     * The row states its own moves, so the browser never re-derives them.
     *
     * Two readings of one lifecycle is how a screen comes to offer a control
     * the server refuses, and `ExpenseStatus` is where the lifecycle lives.
     */
    public function test_an_approved_expense_offers_the_way_back_and_not_an_edit(): void
    {
        $expense = $this->expense();
        (new WorkspaceExpenses($this->workspace))->approve($expense, $this->manager);

        $this->actingAs($this->manager)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('expenses.0.status', 'approved')
                ->where('expenses.0.can_edit', false)
                ->where('expenses.0.can_unapprove', true)
                ->where('expenses.0.approved_by', $this->manager->name));
    }

    public function test_a_manager_records_approves_and_discards_an_expense(): void
    {
        $this->actingAs($this->manager)
            ->post($this->url(), [
                'spent_on' => '2026-03-14',
                'amount' => 4250,
                'currency' => 'usd',
                'description' => 'Courier',
                'project_id' => $this->project->public_id,
            ])
            ->assertRedirect();

        $expense = ClientExpense::query()->sole();
        $this->assertSame('draft', $expense->status);
        // Normalised by the domain's own constructor, not by the controller.
        $this->assertSame('USD', $expense->currency);
        $this->assertSame($this->project->id, $expense->client_project_id);
        $this->assertSame($this->manager->id, $expense->created_by_user_id);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}/approve")
            ->assertRedirect();
        $this->assertSame('approved', $expense->fresh()?->status);

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}/unapprove")
            ->assertRedirect();
        $this->assertSame('draft', $expense->fresh()?->status);
        $this->assertNull($expense->fresh()?->approved_at);

        $this->actingAs($this->manager)
            ->delete("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}")
            ->assertRedirect();
        $this->assertNull(ClientExpense::query()->find($expense->id));
    }

    /**
     * A refusal the domain makes reaches the operator as a message, not a 500.
     *
     * Two managers on one list, both pressing approve, is the ordinary case:
     * the second request is refused because the row moved under it, and that
     * caller did nothing wrong. The message names the status the row holds now,
     * which is what tells them to re-read rather than retry.
     */
    public function test_editing_an_approved_expense_is_refused_with_a_readable_message(): void
    {
        $expense = $this->expense();
        (new WorkspaceExpenses($this->workspace))->approve($expense, $this->manager);

        $this->actingAs($this->manager)
            ->from($this->url())
            ->patch("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}", [
                'spent_on' => '2026-03-14',
                'amount' => 9999,
                'currency' => 'USD',
                'description' => 'Rewritten after approval',
            ])
            ->assertRedirect($this->url())
            ->assertSessionHasErrors('expense');

        // And the approved amount is the amount that was approved.
        $this->assertSame(4250, (int) $expense->fresh()?->amount);
    }

    public function test_a_second_approval_is_refused_rather_than_overwriting_the_first(): void
    {
        $expense = $this->expense();
        $expenses = new WorkspaceExpenses($this->workspace);
        $expenses->approve($expense, $this->manager);
        $firstApprover = $expense->fresh()?->approved_by_user_id;

        $other = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $other->id, 'role' => 'admin']);

        $this->actingAs($other)
            ->from($this->url())
            ->post("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}/approve")
            ->assertSessionHasErrors('expense');

        $this->assertSame($firstApprover, $expense->fresh()?->approved_by_user_id);
    }

    public function test_a_project_from_another_client_is_refused(): void
    {
        $sibling = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Sibling', 'slug' => 'sibling',
        ]);
        $theirs = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $sibling->id,
            'name' => 'Their project',
            'status' => 'active',
        ]);

        $this->actingAs($this->manager)
            ->from($this->url())
            ->post($this->url(), [
                'spent_on' => '2026-03-14',
                'amount' => 4250,
                'currency' => 'USD',
                'description' => 'Courier',
                'project_id' => $theirs->public_id,
            ])
            ->assertSessionHasErrors('project_id');

        $this->assertSame(0, ClientExpense::query()->count());
    }

    /**
     * Another workspace's expense is not found, and is not confirmed to exist.
     *
     * The finder answers null for both "no such expense" and "another
     * tenant's", because a boundary that answers differently for a row it
     * refuses to show has confirmed the row exists.
     */
    public function test_another_workspaces_expense_cannot_be_reached(): void
    {
        $elsewhere = Workspace::query()->create(['name' => 'Elsewhere', 'slug' => 'elsewhere']);
        $elsewhereCompany = ClientCompany::query()->create([
            'workspace_id' => $elsewhere->id, 'name' => 'Their Client', 'slug' => 'their-client',
        ]);
        $foreign = (new WorkspaceExpenses($elsewhere))->record(
            $elsewhereCompany,
            null,
            new NewExpense(CarbonImmutable::parse('2026-03-14'), 9900, 'USD', 'Their courier'),
        );

        $this->actingAs($this->manager)
            ->post("/workspaces/{$this->workspace->public_id}/expenses/{$foreign->public_id}/approve")
            ->assertNotFound();

        $this->assertSame('draft', $foreign->fresh()?->status);
    }

    public function test_a_member_who_cannot_manage_reads_but_is_offered_nothing(): void
    {
        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        // Reaching the client is a separate question from managing it: this
        // member genuinely works on the project, and still may not sign off
        // what the client is charged.
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $this->project->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);
        $this->expense();

        $this->actingAs($member)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('expenses', 1)
                ->where('permissions.record', false)
                ->where('expenses.0.can_edit', false)
                ->where('expenses.0.can_approve', false)
                ->where('expenses.0.can_discard', false));

        $this->actingAs($member)
            ->post($this->url(), [
                'spent_on' => '2026-03-14',
                'amount' => 4250,
                'currency' => 'USD',
                'description' => 'Courier',
            ])
            ->assertForbidden();

        $this->assertSame(1, ClientExpense::query()->count());
    }

    /**
     * Membership of a workspace is not access to every client in it.
     *
     * The `view` gate passes for an ordinary member, so without a reachability
     * check a member scoped to one client's projects reads another client's
     * spending - the amounts, what they were for, and who signed them off.
     * Found by `TenantRouteReachabilityTest`, which sweeps every client-scoped
     * GET route rather than waiting for someone to think of this one.
     */
    public function test_a_member_who_reaches_no_project_of_this_client_gets_nothing(): void
    {
        $stranger = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $stranger->id, 'role' => 'member']);
        $this->expense(['description' => 'A dinner they should not read about']);

        $this->actingAs($stranger)
            ->get($this->url())
            ->assertNotFound();
    }

    /**
     * Reaching a client is not reading all of its money.
     *
     * A member on one of a client's projects reaches the company, so the
     * reachability check above passes and every one of that client's expenses
     * would follow - the other projects' and the company-level ones included.
     *
     * `BillingRecordAccess::canViewAgreement()` settled the shape: a record
     * naming no project covers work the viewer cannot see, so it is refused on
     * the same reasoning as an invoice with no lineage. An unattributed expense
     * is exactly that, and is a manager's to read.
     */
    public function test_a_project_scoped_member_reads_only_their_projects_expenses(): void
    {
        $theirs = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Their project',
            'status' => 'active',
        ]);

        $member = User::factory()->create();
        $this->workspace->memberships()->create(['user_id' => $member->id, 'role' => 'member']);
        ClientProjectMembership::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_project_id' => $theirs->id,
            'user_id' => $member->id,
            'role' => ProjectRole::Contributor->value,
        ]);

        $expenses = new WorkspaceExpenses($this->workspace);
        $expenses->record($this->company, $theirs, new NewExpense(
            CarbonImmutable::parse('2026-03-14'), 1000, 'USD', 'Theirs: parking',
        ), $this->manager);
        // On a project they do not reach.
        $expenses->record($this->company, $this->project, new NewExpense(
            CarbonImmutable::parse('2026-03-14'), 2000, 'USD', 'Not theirs: a private dinner',
        ), $this->manager);
        // And attributed to no project at all.
        $expenses->record($this->company, null, new NewExpense(
            CarbonImmutable::parse('2026-03-14'), 3000, 'USD', 'Company-wide: an annual subscription',
        ), $this->manager);

        $response = $this->actingAs($member)->get($this->url());

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('expenses', 1)
            ->where('expenses.0.description', 'Theirs: parking')
            // And the projects they may attribute to are the ones they see.
            ->has('projects', 1)
            ->where('projects.0.name', 'Their project'));

        $this->assertInertiaPayloadOmits(
            $response,
            ['Not theirs: a private dinner', 'Company-wide: an annual subscription', 'Expense Project'],
            'Theirs: parking',
        );
    }

    /**
     * An edit that moves the project moves it, and one that does not, does not.
     *
     * `NewExpense` carries no tenant reference, so an update that passed only
     * the facts left `client_project_id` untouched while the form reported the
     * attribution saved. Passing the project unconditionally is the opposite
     * error: an edit to the money alone would silently detach the expense.
     */
    public function test_an_edit_moves_the_project_only_when_it_says_so(): void
    {
        $elsewhere = ClientProject::query()->create([
            'workspace_id' => $this->workspace->id,
            'client_company_id' => $this->company->id,
            'name' => 'Second project',
            'status' => 'active',
        ]);
        $expense = $this->expense();

        $facts = [
            'spent_on' => '2026-03-14',
            'amount' => 5000,
            'currency' => 'USD',
            'description' => 'Courier',
        ];

        // Named: it moves.
        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}", [
                ...$facts,
                'project_id' => $elsewhere->public_id,
            ])
            ->assertRedirect();
        $this->assertSame($elsewhere->id, $expense->fresh()?->client_project_id);

        // Cleared explicitly: it clears.
        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}", [
                ...$facts,
                'project_id' => null,
            ])
            ->assertRedirect();
        $this->assertNull($expense->fresh()?->client_project_id);

        // Absent: an edit to the money alone leaves the attribution alone.
        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}", [
                ...$facts,
                'project_id' => $elsewhere->public_id,
            ])
            ->assertRedirect();
        $this->actingAs($this->manager)
            ->patch("/workspaces/{$this->workspace->public_id}/expenses/{$expense->public_id}", [
                ...$facts,
                'amount' => 6000,
            ])
            ->assertRedirect();
        $this->assertSame($elsewhere->id, $expense->fresh()?->client_project_id);
        $this->assertSame(6000, (int) $expense->fresh()?->amount);
    }

    /**
     * The workspace's calendar and currency, not UTC's and not USD.
     *
     * A new expense defaulting to UTC's date records an evening west of it as
     * tomorrow, and one defaulting to USD misprices every workspace that bills
     * in something else.
     */
    public function test_the_page_sends_the_workspaces_calendar_and_currency(): void
    {
        $this->workspace->forceFill([
            'timezone' => 'America/Los_Angeles',
            'default_currency' => 'GBP',
        ])->save();

        $this->actingAs($this->manager)
            ->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('workspace.timezone', 'America/Los_Angeles')
                ->where('workspace.default_currency', 'GBP'));
    }

    /** Nothing from another client reaches this client's page. */
    public function test_the_page_omits_another_clients_expenses(): void
    {
        $this->expense(['description' => 'Ours: courier']);

        $sibling = ClientCompany::query()->create([
            'workspace_id' => $this->workspace->id, 'name' => 'Sibling', 'slug' => 'sibling',
        ]);
        (new WorkspaceExpenses($this->workspace))->record(
            $sibling,
            null,
            new NewExpense(CarbonImmutable::parse('2026-03-14'), 7700, 'USD', 'Theirs: a secret dinner'),
        );

        $this->assertInertiaPayloadOmits(
            $this->actingAs($this->manager)->get($this->url()),
            ['Theirs: a secret dinner'],
            'Ours: courier',
        );
    }

    /** One page of expenses costs the same number of queries as ten. */
    public function test_the_page_does_not_query_per_expense(): void
    {
        $this->expense();

        $this->assertQueryCountIndependentOfRows(
            fn () => $this->actingAs($this->manager)->get($this->url()),
            function (): void {
                for ($index = 0; $index < 10; $index++) {
                    $this->expense(['description' => 'Extra '.$index]);
                }
            },
        );
    }

    private function url(): string
    {
        return "/workspaces/{$this->workspace->public_id}/clients/{$this->company->public_id}/expenses";
    }

    /** @param array<string, mixed> $overrides */
    private function expense(array $overrides = []): ClientExpense
    {
        $expense = (new WorkspaceExpenses($this->workspace))->record(
            $this->company,
            $this->project,
            new NewExpense(
                CarbonImmutable::parse('2026-03-14'),
                4250,
                'USD',
                is_string($overrides['description'] ?? null) ? $overrides['description'] : 'Courier',
            ),
            $this->manager,
        );

        $this->assertSame(ExpenseStatus::Draft->value, $expense->status);

        return $expense;
    }
}
