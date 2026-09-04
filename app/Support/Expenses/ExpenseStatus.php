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
 * So the strings live here once and the questions callers ask are named methods,
 * and the legal moves between them live here too - see
 * {@see transitionsTo()}. What is still absent is the
 * `approved` -> `invoiced` edge's *caller*: the invoicing hook is #75's third
 * slice, so this enum knows that move is legal and nothing makes it yet.
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

    /** May a stored status still have its facts rewritten? Unrecognised refuses. */
    public static function isEditableValue(mixed $value): bool
    {
        return self::tryFrom(is_string($value) ? $value : '') === self::Draft;
    }

    /**
     * The statuses this one may move to.
     *
     * The whole lifecycle, in one place, as data rather than as a chain of
     * `if`s spread across the methods that perform the moves. Four edges:
     *
     * - `draft` → `approved`, the gate #75 requires before an expense can be
     *   billed;
     * - `approved` → `draft`, because a manager who approves the wrong receipt
     *   needs a way back that is not a delete, and the expense has touched no
     *   invoice yet;
     * - `approved` → `invoiced`, which the invoicing hook will make;
     * - nothing out of `invoiced`. It is on a client's bill. Changing it there
     *   would change what was billed without touching the bill, which is the
     *   shape of defect the time-entry freeze guards exist to prevent.
     *
     * No self-edges. Approving an approved expense is a caller that has read a
     * stale row, and answering "fine" to it is how a second approval quietly
     * overwrites the first approver and timestamp.
     *
     * @return list<self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Draft => [self::Approved],
            self::Approved => [self::Draft, self::Invoiced],
            self::Invoiced => [],
        };
    }

    /**
     * May a stored status move to this one? Unrecognised refuses.
     *
     * The third fail-closed read, and it points the same way as the other two:
     * a status this code cannot place is not a status it can reason about
     * moving, so the answer is no and the caller gets a refusal it can see
     * rather than a write it cannot undo.
     */
    public static function mayTransitionValue(mixed $from, self $to): bool
    {
        $status = self::tryFrom(is_string($from) ? $from : '');

        return $status !== null && in_array($to, $status->transitionsTo(), true);
    }
}
