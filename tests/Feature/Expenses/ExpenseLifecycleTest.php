<?php

namespace Tests\Feature\Expenses;

use App\Exceptions\CrossTenantReference;
use App\Exceptions\ExpenseTransitionRefused;
use App\Models\ClientExpense;
use App\Queries\Expenses\WorkspaceExpenses;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use App\Support\Expenses\ExpenseStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSyntheticExpenses;
use Tests\TestCase;

/**
 * Draft to approved and back, and everything the lifecycle refuses.
 *
 * The refusals are most of this file, and deliberately so. An approval gate is
 * only worth having if it can say no, and #75's constraint on this slice is
 * that every guard be mutation-checked by hand: a guard that cannot fail is
 * worthless. So each refusal is asserted next to the same call succeeding on a
 * row that is allowed to make the move, and each one also asserts that the row
 * did **not** change - a guard that threw after writing would pass the first
 * half on its own.
 */
final class ExpenseLifecycleTest extends TestCase
{
    use BuildsSyntheticExpenses;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LockOrderRecorder::stop();

        parent::tearDown();
    }

    public function test_a_manager_approves_a_draft_and_the_row_records_who_and_when(): void
    {
        $workspace = $this->syntheticWorkspace('approving');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'approving'));

        $this->assertSame(ExpenseStatus::Draft->value, $expense->status);

        $approved = (new WorkspaceExpenses($workspace))->approve($expense, $approver);

        $this->assertSame(ExpenseStatus::Approved->value, $approved->status);
        $this->assertTrue($approved->isApproved());
        $this->assertFalse($approved->hasBeenInvoiced());
        $this->assertSame($approver->id, $approved->approved_by_user_id);
        $this->assertNotNull($approved->approved_at);
        $this->assertSame(ExpenseStatus::Approved->value, $expense->refresh()->status, 'The stored row moved, not just the returned copy.');
    }

    /**
     * Approval decides from the locked row, so a stale caller loses rather than
     * overwriting the manager who won.
     *
     * The second call is handed the model as it was read *before* the first
     * approval - the same shape as two managers with the same list open. A
     * guard that read `$expense->status` from its argument would see `draft`
     * and stamp the second approver over the first.
     */
    public function test_a_second_approval_from_a_stale_read_is_refused(): void
    {
        $workspace = $this->syntheticWorkspace('racing');
        $first = $this->syntheticMember($workspace, 'first approver');
        $second = $this->syntheticMember($workspace, 'second approver');
        $expenses = new WorkspaceExpenses($workspace);
        $stale = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'racing'));

        $expenses->approve(clone $stale, $first);

        try {
            $expenses->approve($stale, $second);
            $this->fail('A second approval of an already approved expense must be refused.');
        } catch (ExpenseTransitionRefused $refusal) {
            $this->assertStringContainsString('"approved" cannot become "approved"', $refusal->getMessage());
        }

        $this->assertSame($first->id, $stale->refresh()->approved_by_user_id, 'The first approver stands.');
    }

    public function test_an_approval_can_be_withdrawn_and_the_stamps_go_with_it(): void
    {
        $workspace = $this->syntheticWorkspace('withdrawing');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'withdrawing'));

        $expenses->approve($expense, $approver);
        $returned = $expenses->unapprove($expense->refresh());

        $this->assertSame(ExpenseStatus::Draft->value, $returned->status);
        $this->assertNull($returned->approved_by_user_id, 'A draft that names an approver is a row every later reader has to disbelieve.');
        $this->assertNull($returned->approved_at);
        $this->assertFalse($returned->isApproved());
    }

    public function test_a_draft_cannot_be_withdrawn_because_it_was_never_approved(): void
    {
        $workspace = $this->syntheticWorkspace('never approved');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'never approved'));

        $this->expectException(ExpenseTransitionRefused::class);
        $this->expectExceptionMessage('An expense with status "draft" cannot become "draft".');

        (new WorkspaceExpenses($workspace))->unapprove($expense);
    }

    /**
     * An invoiced expense is on a client's bill, so nothing here moves it.
     *
     * The status is written directly because nothing in this slice issues an
     * invoice - the hook is #75's third - and the guard that refuses these
     * moves has to exist before the code that would otherwise need it.
     */
    public function test_an_invoiced_expense_refuses_every_move_this_boundary_offers(): void
    {
        $workspace = $this->syntheticWorkspace('billed');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'billed'));
        $expense->forceFill(['status' => ExpenseStatus::Invoiced->value])->save();

        $refusals = 0;

        foreach ([
            fn () => $expenses->approve($expense, $approver),
            fn () => $expenses->unapprove($expense),
            fn () => $expenses->update($expense, $this->syntheticExpenseFacts('rewritten', 99_900)),
            fn () => $expenses->discard($expense),
        ] as $move) {
            try {
                $move();
                $this->fail('An invoiced expense must refuse every move.');
            } catch (ExpenseTransitionRefused) {
                $refusals++;
            }
        }

        $this->assertSame(4, $refusals);
        $this->assertSame(ExpenseStatus::Invoiced->value, $expense->refresh()->status);
        $this->assertSame(12_500, $expense->amount, 'The refused edit must not have landed first.');
        $this->assertNull($expense->deleted_at);
    }

    public function test_a_drafts_facts_can_be_rewritten_in_full(): void
    {
        $workspace = $this->syntheticWorkspace('editing');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'editing'));

        $updated = (new WorkspaceExpenses($workspace))->update(
            $expense,
            $this->syntheticExpenseFacts('corrected', 24_000, 'eur', '2026-08-20'),
        );

        $this->assertSame(24_000, $updated->amount);
        $this->assertSame('EUR', $updated->currency, 'The DTO normalises, so the boundary stores what it checked.');
        $this->assertSame('2026-08-20', $updated->spent_on?->format('Y-m-d'));
        $this->assertSame('Synthetic corrected expense', $updated->description);
        $this->assertSame(ExpenseStatus::Draft->value, $updated->status, 'Editing is not a transition.');
    }

    /**
     * An approved expense is what the client will be billed, so it is frozen
     * until somebody withdraws the approval on purpose.
     */
    public function test_an_approved_expense_cannot_be_edited_until_it_is_unapproved(): void
    {
        $workspace = $this->syntheticWorkspace('frozen');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'frozen'));
        $expenses->approve($expense, $approver);

        try {
            $expenses->update($expense->refresh(), $this->syntheticExpenseFacts('sneaky', 99_900));
            $this->fail('An approved expense must not be editable.');
        } catch (ExpenseTransitionRefused $refusal) {
            $this->assertStringContainsString('can no longer be edited', $refusal->getMessage());
        }

        $this->assertSame(12_500, $expense->refresh()->amount);

        // The way through, which is the point of the refusal being a refusal
        // rather than a rejection: the manager withdraws, edits, re-approves.
        $expenses->unapprove($expense->refresh());
        $expenses->update($expense->refresh(), $this->syntheticExpenseFacts('corrected', 99_900));

        $this->assertSame(99_900, $expense->refresh()->amount);
    }

    public function test_an_unapproved_expense_can_be_discarded_and_leaves_the_list(): void
    {
        $workspace = $this->syntheticWorkspace('discarding');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'discarding'));

        $expenses->discard($expense);

        $this->assertSame([], $expenses->query()->pluck('id')->all());
        $this->assertNotNull($expense->refresh()->deleted_at, 'Soft deleted, so the audit trail survives.');
    }

    /**
     * A status the enum cannot place stops everything, and is not repaired into
     * one it can.
     *
     * This is the row the fail-closed readers exist for: an older release, a
     * migration, a hand-run statement. Every move is refused, including the
     * tempting "put it back to draft", and the refusal quotes the value
     * verbatim - rendering it as "unknown" would hide the only clue to what
     * wrote it.
     */
    public function test_an_unrecognised_status_refuses_every_move_and_names_itself(): void
    {
        $workspace = $this->syntheticWorkspace('strange');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'strange'));
        $expense->forceFill(['status' => 'pending_review'])->save();

        foreach ([
            fn () => $expenses->approve($expense, $approver),
            fn () => $expenses->unapprove($expense),
            fn () => $expenses->update($expense, $this->syntheticExpenseFacts()),
            fn () => $expenses->discard($expense),
        ] as $move) {
            try {
                $move();
                $this->fail('An unrecognised status must refuse every move.');
            } catch (ExpenseTransitionRefused $refusal) {
                $this->assertStringContainsString('pending_review', $refusal->getMessage());
            }
        }

        $this->assertSame('pending_review', $expense->refresh()->status);
        $this->assertNull($expense->deleted_at);
    }

    /**
     * The approver has to be a member of the workspace whose money this is.
     *
     * `approved_by_user_id` points at `users`, which is not tenant-owned, so no
     * composite key can refuse a stranger. This check is the only thing between
     * a workspace's expenses and a sign-off from someone with no relationship
     * to it.
     */
    public function test_a_user_outside_the_workspace_cannot_approve_its_expenses(): void
    {
        $workspace = $this->syntheticWorkspace('guarded');
        $elsewhere = $this->syntheticWorkspace('elsewhere');
        $outsider = $this->syntheticMember($elsewhere, 'outsider');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'guarded'));

        try {
            (new WorkspaceExpenses($workspace))->approve($expense, $outsider);
            $this->fail('A non-member must not approve.');
        } catch (CrossTenantReference $refusal) {
            $this->assertStringContainsString('not a member of this workspace', $refusal->getMessage());
        }

        $this->assertSame(ExpenseStatus::Draft->value, $expense->refresh()->status);
        $this->assertNull($expense->approved_by_user_id);

        // The control: enrolled in this workspace, the same user may approve.
        $workspace->memberships()->create(['user_id' => $outsider->id, 'role' => 'admin']);
        $this->assertTrue((new WorkspaceExpenses($workspace))->approve($expense->refresh(), $outsider)->isApproved());
    }

    /**
     * A boundary built for one workspace refuses another's row before it locks
     * anything.
     *
     * Refused as a tenancy error rather than as a missing row, because the
     * caller is holding an id it never checked; answering "not found" invites a
     * retry with a different lookup.
     */
    public function test_another_workspaces_expense_cannot_be_moved_through_this_boundary(): void
    {
        $home = $this->syntheticWorkspace('home');
        $foreign = $this->syntheticWorkspace('foreign');
        $approver = $this->syntheticMember($home, 'approver');
        $theirs = $this->recordSyntheticExpense($foreign, $this->syntheticCompany($foreign, 'foreign'));
        $expenses = new WorkspaceExpenses($home);

        foreach ([
            fn () => $expenses->approve($theirs, $approver),
            fn () => $expenses->unapprove($theirs),
            fn () => $expenses->update($theirs, $this->syntheticExpenseFacts()),
            fn () => $expenses->discard($theirs),
        ] as $move) {
            try {
                $move();
                $this->fail('Another workspace\'s expense must be refused.');
            } catch (CrossTenantReference $refusal) {
                $this->assertStringContainsString('belongs to another workspace', $refusal->getMessage());
            }
        }

        $this->assertSame(ExpenseStatus::Draft->value, $theirs->refresh()->status);
        $this->assertNull($theirs->deleted_at);
    }

    /**
     * A row deleted between the caller's read and the locked one stops, rather
     * than being resurrected by the write that follows.
     */
    public function test_an_expense_deleted_under_the_caller_is_not_written_back(): void
    {
        $workspace = $this->syntheticWorkspace('vanishing');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $stale = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'vanishing'));

        $expenses->discard(clone $stale);

        $this->expectException(ModelNotFoundException::class);

        $expenses->approve($stale, $approver);
    }

    /**
     * Every transition takes the expense lock, and takes it as one.
     *
     * Recorded rather than read off the code: `Locks::forUpdate()` is the only
     * way to take a lock, so an approval that reached the row without one -
     * checking the status and writing on the strength of a read nothing held -
     * records no sequence at all and fails here.
     */
    public function test_each_transition_takes_the_expense_row_lock(): void
    {
        $workspace = $this->syntheticWorkspace('recorded');
        $approver = $this->syntheticMember($workspace, 'approver');
        $expenses = new WorkspaceExpenses($workspace);
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'recorded'));

        LockOrderRecorder::start();

        $expenses->approve($expense, $approver);
        $expenses->unapprove($expense->refresh());
        $expenses->update($expense->refresh(), $this->syntheticExpenseFacts('corrected', 30_000));
        $expenses->discard($expense->refresh());

        $this->assertSame(
            [
                [LockResource::ClientExpense],
                [LockResource::ClientExpense],
                [LockResource::ClientExpense],
                [LockResource::ClientExpense],
            ],
            LockOrderRecorder::sequences(),
            'Four transactions, each taking the expense lock once.',
        );
    }

    /**
     * The locked re-read carries the workspace predicate, not just the key.
     *
     * Asserted on the SQL because the effect is invisible in the outcome: a
     * boundary that locked by primary key alone would refuse the write exactly
     * as this one does, having first taken a real `FOR UPDATE` on another
     * tenant's row and held it for the length of the transaction.
     */
    public function test_the_locking_read_is_scoped_to_the_workspace(): void
    {
        $workspace = $this->syntheticWorkspace('scoped lock');
        $expense = $this->recordSyntheticExpense($workspace, $this->syntheticCompany($workspace, 'scoped lock'));

        $statements = [];
        DB::listen(static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        (new WorkspaceExpenses($workspace))->update($expense, $this->syntheticExpenseFacts('corrected', 30_000));

        $selects = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'select') && str_contains($sql, 'client_expenses'),
        ));

        $this->assertNotEmpty($selects, 'No read of the table was captured, so this asserted nothing.');
        $this->assertStringContainsString('workspace_id', $selects[0]);
    }

    public function test_the_scope_still_only_lists_approved_expenses_it_owns(): void
    {
        $workspace = $this->syntheticWorkspace('listing');
        $foreign = $this->syntheticWorkspace('foreign');
        $approver = $this->syntheticMember($workspace, 'approver');
        $company = $this->syntheticCompany($workspace, 'listing');
        $expenses = new WorkspaceExpenses($workspace);

        $approved = $this->recordSyntheticExpense($workspace, $company);
        $this->recordSyntheticExpense($workspace, $company);
        $theirs = $this->recordSyntheticExpense($foreign, $this->syntheticCompany($foreign, 'foreign'));
        (new WorkspaceExpenses($foreign))->approve($theirs, $this->syntheticMember($foreign, 'their approver'));

        $expenses->approve($approved, $approver);

        $this->assertSame(
            [$approved->id],
            $expenses->query()->approved()->pluck('id')->all(),
            'One approved expense of this workspace\'s two, and none of the other tenant\'s.',
        );
        $this->assertSame(2, ClientExpense::query()->approved()->count(), 'Both approvals must exist, or the scope proved nothing.');
    }
}
