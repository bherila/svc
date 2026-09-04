<?php

namespace Tests\Feature\Expenses;

use App\Exceptions\CrossTenantReference;
use App\Models\ClientExpense;
use App\Queries\Expenses\WorkspaceExpenses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * One tenant's expenses stay one tenant's, whichever way they are asked for.
 *
 * The composite key on `(workspace_id, client_company_id)` already refuses a
 * cross-tenant company at the database - `CompositeTenantForeignKeyTest` proves
 * that with no application code involved. These cases cover what a key cannot:
 * the optional project reference, which is exempt from a composite key because
 * its `ON DELETE SET NULL` rule cannot live inside one (InnoDB errno 1830), and
 * every read, where nothing is refused and the wrong rows simply come back.
 *
 * Each refusal is asserted alongside the same call succeeding inside one
 * workspace. A guard that refuses everything is not isolation, and a test that
 * only ever asks for the refusal cannot tell the two apart.
 */
final class WorkspaceExpenseIsolationTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    public function test_a_workspaces_expenses_are_the_only_ones_it_can_list(): void
    {
        $home = $this->syntheticWorkspace('home');
        $foreign = $this->syntheticWorkspace('foreign');
        $mine = $this->recordSyntheticExpense($home, $this->syntheticCompany($home, 'home'));
        $theirs = $this->recordSyntheticExpense($foreign, $this->syntheticCompany($foreign, 'foreign'));

        $listed = (new WorkspaceExpenses($home))->query()->pluck('id')->all();

        $this->assertSame([$mine->id], $listed);
        $this->assertNotContains($theirs->id, $listed);
        $this->assertSame(2, ClientExpense::query()->count(), 'Both expenses must exist, or the scope proved nothing.');
    }

    public function test_another_workspaces_expense_is_not_found_by_its_public_id(): void
    {
        $home = $this->syntheticWorkspace('home');
        $foreign = $this->syntheticWorkspace('foreign');
        $theirs = $this->recordSyntheticExpense($foreign, $this->syntheticCompany($foreign, 'foreign'));

        $this->assertNull((new WorkspaceExpenses($home))->find($theirs->public_id));
        $this->assertSame(
            $theirs->id,
            (new WorkspaceExpenses($foreign))->find($theirs->public_id)?->id,
            'The owning workspace must still find it, or the lookup is broken rather than scoped.',
        );
    }

    public function test_a_deleted_expense_leaves_the_list(): void
    {
        $home = $this->syntheticWorkspace('home');
        $expense = $this->recordSyntheticExpense($home, $this->syntheticCompany($home, 'home'));

        $expense->delete();

        $this->assertSame([], (new WorkspaceExpenses($home))->query()->pluck('id')->all());
        $this->assertNull((new WorkspaceExpenses($home))->find($expense->public_id));
    }

    public function test_it_refuses_to_charge_an_expense_to_another_workspaces_client(): void
    {
        $home = $this->syntheticWorkspace('home');
        $foreign = $this->syntheticWorkspace('foreign');
        $foreignCompany = $this->syntheticCompany($foreign, 'foreign');

        try {
            (new WorkspaceExpenses($home))->record($foreignCompany, null, $this->syntheticExpenseFacts());
            $this->fail('An expense was charged to another workspace\'s client.');
        } catch (CrossTenantReference) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, ClientExpense::query()->count(), 'The refusal must happen before anything is written.');
    }

    /**
     * The project column carries no composite key, so this refusal is the only
     * thing standing between a workspace's expense and another tenant's project.
     */
    public function test_it_refuses_a_project_from_another_workspace(): void
    {
        $home = $this->syntheticWorkspace('home');
        $foreign = $this->syntheticWorkspace('foreign');
        $homeCompany = $this->syntheticCompany($home, 'home');
        $foreignProject = $this->syntheticProject($this->syntheticCompany($foreign, 'foreign'), 'foreign');

        try {
            (new WorkspaceExpenses($home))->record($homeCompany, $foreignProject, $this->syntheticExpenseFacts());
            $this->fail('An expense was attributed to another workspace\'s project.');
        } catch (CrossTenantReference) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, ClientExpense::query()->count(), 'The refusal must happen before anything is written.');
    }

    /**
     * Same tenant, wrong client. Two of this workspace's own companies are still
     * two clients, and an expense attributed to the wrong one is billed to the
     * wrong one.
     */
    public function test_it_refuses_a_project_belonging_to_a_different_client_of_the_same_workspace(): void
    {
        $home = $this->syntheticWorkspace('home');
        $billedCompany = $this->syntheticCompany($home, 'billed');
        $otherProject = $this->syntheticProject($this->syntheticCompany($home, 'other'), 'other');

        try {
            (new WorkspaceExpenses($home))->record($billedCompany, $otherProject, $this->syntheticExpenseFacts());
            $this->fail('An expense was attributed to another client\'s project.');
        } catch (CrossTenantReference) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, ClientExpense::query()->count(), 'The refusal must happen before anything is written.');
    }

    /**
     * The boundary supplies the tenant columns, so an expense cannot be recorded
     * into a workspace the caller did not construct it for.
     */
    public function test_it_stamps_the_expense_with_its_own_workspace_and_recorder(): void
    {
        $home = $this->syntheticWorkspace('home');
        $company = $this->syntheticCompany($home, 'home');
        $project = $this->syntheticProject($company, 'rollout');
        $manager = $this->syntheticUser('manager');

        $expense = (new WorkspaceExpenses($home))->record(
            $company,
            $project,
            $this->syntheticExpenseFacts(),
            $manager,
        );

        $this->assertSame($home->id, $expense->workspace_id);
        $this->assertSame($home->id, $expense->workspaceId());
        $this->assertSame($company->id, $expense->client_company_id);
        $this->assertSame($project->id, $expense->client_project_id);
        $this->assertSame($manager->id, $expense->created_by_user_id);
        $this->assertNotSame('', $expense->public_id);
    }
}
