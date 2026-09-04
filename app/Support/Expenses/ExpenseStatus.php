<?php

namespace App\Support\Expenses;

use App\Support\Billing\InvoiceStatus;

/**
 * Where a reimbursable expense sits between being recorded and being billed.
 *
 * The vocabulary is `client_time_entries`' own, on purpose: an expense reaches
 * an invoice through the same gate as billable time, and two lifecycles that
 * mean the same thing but spell it differently is how a guard comes to enumerate
 * one set of strings while the column holds another. That has already cost this
 * codebase real money arithmetic - see {@see InvoiceStatus},
 * which exists because four hand-written lists each omitted the same status.
 *
 * So the strings live here once and the questions callers ask are named methods.
 * Nothing in this slice transitions an expense; approval and the claim/release
 * rules around draft-invoice regeneration wait for the centralized lock
 * discipline in #117. What is settled now is the vocabulary those transitions
 * will move through.
 *
 * ## The two fail-closed reads point in opposite directions, and must
 *
 * A status this code does not recognise cannot be shown to be approved, so
 * {@see isApprovedValue()} answers no: the cost of being wrong is an expense
 * that needs a manager's second look before it can be billed. It cannot be shown
 * to be un-billed either, so {@see hasBeenInvoicedValue()} answers yes: the cost
 * of being wrong the other way is charging a client twice for one receipt.
 *
 * Collapsing both onto one "unknown means draft" default would answer the second
 * question backwards, because draft is the least privileged state to read and
 * the most permissive to write.
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Invoiced = 'invoiced';

    /**
     * Every status, for validation and schema documentation.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * Statuses a manager has passed.
     *
     * `invoiced` counts. Issuing an invoice rewrites approved expenses to
     * `invoiced`, so a query reading the literal `approved` alone forgets every
     * expense it has already billed - which is exactly how the time-entry
     * ledgers came to roll the same capacity forward twice.
     *
     * @return list<string>
     */
    public static function approved(): array
    {
        return [self::Approved->value, self::Invoiced->value];
    }

    /**
     * Statuses an expense may still be billed from.
     *
     * Only `approved`: a draft has not been passed, and an `invoiced` expense is
     * on a client's bill already.
     *
     * @return list<string>
     */
    public static function billable(): array
    {
        return [self::Approved->value];
    }

    /** Has a manager passed a stored status? Unrecognised answers no. */
    public static function isApprovedValue(mixed $value): bool
    {
        $status = self::tryFrom(is_string($value) ? $value : '');

        return $status !== null && in_array($status->value, self::approved(), true);
    }

    /** Has a stored status already reached a client's bill? Unrecognised answers yes. */
    public static function hasBeenInvoicedValue(mixed $value): bool
    {
        $status = self::tryFrom(is_string($value) ? $value : '');

        return $status === null || $status === self::Invoiced;
    }
}
