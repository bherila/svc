<?php

namespace Tests\Unit\Expenses;

use App\Support\Expenses\ExpenseStatus;
use PHPUnit\Framework\TestCase;

/**
 * The lifecycle vocabulary, and the two fail-closed reads over it.
 *
 * The pair of unknown-value cases is the point of this file. They point in
 * opposite directions on purpose, and a change that made them agree would look
 * like a simplification and would either strand every expense in review or bill
 * one twice.
 */
final class ExpenseStatusTest extends TestCase
{
    public function test_it_names_every_state_an_expense_can_be_in(): void
    {
        $this->assertSame(['draft', 'approved', 'invoiced'], ExpenseStatus::all());
    }

    /**
     * Billing rewrites `approved` to `invoiced`, so a set meaning "a manager has
     * passed this" that stopped at the literal `approved` would forget every
     * expense already charged - the mistake that made the time-entry ledgers
     * roll the same capacity forward twice.
     */
    public function test_approved_includes_what_has_already_been_billed(): void
    {
        $this->assertSame(['approved', 'invoiced'], ExpenseStatus::approved());
    }

    public function test_only_an_approved_expense_may_still_be_billed(): void
    {
        $this->assertSame(['approved'], ExpenseStatus::billable());
    }

    public function test_a_stored_status_is_approved_only_when_it_says_so(): void
    {
        $this->assertFalse(ExpenseStatus::isApprovedValue('draft'));
        $this->assertTrue(ExpenseStatus::isApprovedValue('approved'));
        $this->assertTrue(ExpenseStatus::isApprovedValue('invoiced'));
    }

    /** An unrecognised status cannot be shown to have been approved, so it has not been. */
    public function test_an_unrecognised_status_is_not_approved(): void
    {
        $this->assertFalse(ExpenseStatus::isApprovedValue('pending_review'));
        $this->assertFalse(ExpenseStatus::isApprovedValue(null));
        $this->assertFalse(ExpenseStatus::isApprovedValue(''));
    }

    public function test_a_stored_status_says_whether_the_client_has_been_billed(): void
    {
        $this->assertFalse(ExpenseStatus::hasBeenInvoicedValue('draft'));
        $this->assertFalse(ExpenseStatus::hasBeenInvoicedValue('approved'));
        $this->assertTrue(ExpenseStatus::hasBeenInvoicedValue('invoiced'));
    }

    /**
     * And here the unknown answers the other way: nothing can show the client
     * has not already paid for this receipt, and charging twice is the worse of
     * the two mistakes.
     */
    public function test_an_unrecognised_status_is_treated_as_already_billed(): void
    {
        $this->assertTrue(ExpenseStatus::hasBeenInvoicedValue('pending_review'));
        $this->assertTrue(ExpenseStatus::hasBeenInvoicedValue(null));
        $this->assertTrue(ExpenseStatus::hasBeenInvoicedValue(''));
    }

    /**
     * The whole lifecycle, edge by edge, as one table.
     *
     * Written out in full rather than as "draft goes to approved, and so on",
     * because the edges that must *not* exist are the ones worth pinning: an
     * `invoiced` expense with any outgoing edge changes what a client was billed
     * without touching the bill, and a self-edge on `approved` lets a second
     * approval overwrite the first approver and timestamp.
     */
    public function test_the_lifecycle_has_exactly_four_edges(): void
    {
        $this->assertSame([ExpenseStatus::Approved], ExpenseStatus::Draft->transitionsTo());
        $this->assertSame(
            [ExpenseStatus::Draft, ExpenseStatus::Invoiced],
            ExpenseStatus::Approved->transitionsTo(),
        );
        $this->assertSame([], ExpenseStatus::Invoiced->transitionsTo());
    }

    /** No status may move to itself. A repeat is a caller working from a stale read. */
    public function test_no_status_transitions_to_itself(): void
    {
        foreach (ExpenseStatus::cases() as $case) {
            $this->assertNotContains($case, $case->transitionsTo(), $case->value.' must not move to itself');
        }
    }

    public function test_a_stored_status_may_only_make_a_move_the_lifecycle_allows(): void
    {
        $this->assertTrue(ExpenseStatus::mayTransitionValue('draft', ExpenseStatus::Approved));
        $this->assertTrue(ExpenseStatus::mayTransitionValue('approved', ExpenseStatus::Draft));
        $this->assertTrue(ExpenseStatus::mayTransitionValue('approved', ExpenseStatus::Invoiced));

        $this->assertFalse(ExpenseStatus::mayTransitionValue('draft', ExpenseStatus::Invoiced));
        $this->assertFalse(ExpenseStatus::mayTransitionValue('draft', ExpenseStatus::Draft));
        $this->assertFalse(ExpenseStatus::mayTransitionValue('approved', ExpenseStatus::Approved));
        $this->assertFalse(ExpenseStatus::mayTransitionValue('invoiced', ExpenseStatus::Draft));
        $this->assertFalse(ExpenseStatus::mayTransitionValue('invoiced', ExpenseStatus::Approved));
    }

    /**
     * The third fail-closed read, pointing the same way as the other two.
     *
     * A status this code cannot place is not one it can reason about moving, so
     * every move out of it is refused - including into `draft`, which is the
     * tempting exception. "Put the strange row back to draft" reads like a
     * repair and is a write nobody reviewed, over a value nobody has explained
     * yet.
     */
    public function test_an_unrecognised_status_may_make_no_move_at_all(): void
    {
        foreach (ExpenseStatus::cases() as $to) {
            $this->assertFalse(ExpenseStatus::mayTransitionValue('pending', $to));
            $this->assertFalse(ExpenseStatus::mayTransitionValue('', $to));
            $this->assertFalse(ExpenseStatus::mayTransitionValue(null, $to));
            $this->assertFalse(ExpenseStatus::mayTransitionValue(7, $to));
        }
    }

    /** Only a draft may have its facts rewritten, and an unreadable status may not. */
    public function test_only_a_draft_is_editable(): void
    {
        $this->assertTrue(ExpenseStatus::isEditableValue('draft'));
        $this->assertFalse(ExpenseStatus::isEditableValue('approved'));
        $this->assertFalse(ExpenseStatus::isEditableValue('invoiced'));
        $this->assertFalse(ExpenseStatus::isEditableValue('pending'));
        $this->assertFalse(ExpenseStatus::isEditableValue(null));
    }
}
