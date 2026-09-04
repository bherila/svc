<?php

namespace Tests\Feature\Expenses;

use App\Models\ClientExpense;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * What the table and the model promise about one expense.
 *
 * Nothing here transitions an expense: approval and the claim/release rules
 * around draft-invoice regeneration wait for the centralized lock discipline in
 * #117. What is asserted is the shape those transitions will move through - the
 * state a new expense starts in, the types its columns read back as, and the two
 * fail-closed questions that will gate billing.
 */
final class ClientExpenseTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    public function test_a_recorded_expense_starts_as_an_unapproved_draft(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $company = $this->syntheticCompany($workspace, 'home');

        $expense = $this->recordSyntheticExpense($workspace, $company);

        $this->assertSame('draft', $expense->status);
        $this->assertNull($expense->approved_at);
        $this->assertNull($expense->approved_by_user_id);
        $this->assertFalse($expense->isApproved());
        $this->assertFalse($expense->hasBeenInvoiced());
    }

    public function test_it_reads_money_dates_and_the_optional_project_back_in_their_own_types(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $company = $this->syntheticCompany($workspace, 'home');

        $expense = $this->recordSyntheticExpense(
            $workspace,
            $company,
            null,
            $this->syntheticExpenseFacts(amount: 12_500, spentOn: '2026-08-15'),
        )->fresh();

        $this->assertNotNull($expense);
        $this->assertSame(12_500, $expense->amount);
        $this->assertSame('USD', $expense->currency);
        $this->assertInstanceOf(CarbonImmutable::class, $expense->spent_on);
        $this->assertSame('2026-08-15', $expense->spent_on->toDateString());
        $this->assertNull($expense->client_project_id);
    }

    public function test_an_expense_may_be_attributed_to_a_project_of_its_own_client(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $company = $this->syntheticCompany($workspace, 'home');
        $project = $this->syntheticProject($company, 'rollout');

        $expense = $this->recordSyntheticExpense($workspace, $company, $project);

        $this->assertSame($project->id, $expense->client_project_id);
        $this->assertSame($project->id, $expense->project?->id);
    }

    /**
     * An expense is a financial record and a claim on money. Removing one has to
     * leave the row behind, or an invoice that billed it loses what it billed.
     */
    public function test_deleting_an_expense_keeps_the_row(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'home'));

        $expense->delete();

        $this->assertSame(0, ClientExpense::query()->count());
        $this->assertSame(1, ClientExpense::withTrashed()->count());
    }

    /**
     * `invoiced` counts as approved, because issuing an invoice rewrites the
     * status. A scope reading the literal `approved` alone would hide every
     * expense already charged to a client.
     */
    public function test_the_approved_scope_includes_expenses_already_billed(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $company = $this->syntheticCompany($workspace, 'home');

        $draft = $this->recordSyntheticExpense($workspace, $company);
        $approved = $this->recordSyntheticExpense($workspace, $company);
        $invoiced = $this->recordSyntheticExpense($workspace, $company);
        $this->setStoredStatus($approved->id, 'approved');
        $this->setStoredStatus($invoiced->id, 'invoiced');

        $found = ClientExpense::query()->approved()->pluck('id')->all();

        sort($found);
        $this->assertSame([$approved->id, $invoiced->id], $found);
        $this->assertNotContains($draft->id, $found);
    }

    /**
     * A status this release does not know can only have come from another one,
     * or from a hand-run statement. Neither is evidence a manager passed the
     * expense, and neither is evidence the client has not already paid for it -
     * so the two questions answer in opposite directions.
     */
    public function test_a_status_the_code_does_not_recognise_fails_closed_both_ways(): void
    {
        $workspace = $this->syntheticWorkspace('home');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'home'));
        $this->setStoredStatus($expense->id, 'pending_review');

        $stored = ClientExpense::query()->findOrFail($expense->id);

        $this->assertFalse($stored->isApproved());
        $this->assertTrue($stored->hasBeenInvoiced());
        $this->assertSame(0, ClientExpense::query()->approved()->count());
    }

    /** Written past the model on purpose: this asserts what a stored row does, not what a setter allows. */
    private function setStoredStatus(int $id, string $status): void
    {
        DB::table('client_expenses')->where('id', $id)->update(['status' => $status]);
    }
}
