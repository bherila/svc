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
}
