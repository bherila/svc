<?php

namespace App\Support\Billing;

/**
 * What state an invoice is in, and what that state permits.
 *
 * This exists because the predecessor's column was
 * `enum('draft','issued','paid','void')` and this schema's is a varchar with a
 * fifth value, `partially_paid`. Code ported from a world with four states
 * writes exhaustive four-element lists, and every one of them is silently wrong
 * here - not by throwing, but by quietly omitting a partially paid invoice from
 * a guard that should have caught it.
 *
 * That has already happened repeatedly: a partially paid invoice could be reset
 * to draft and rebuilt, a partially paid cadence invoice did not block interim
 * generation, and a partially paid retainer period could be sold a second time.
 * Each was a separately-written list missing the same string.
 *
 * So the vocabulary lives here once, and the questions callers actually ask are
 * named methods rather than array literals. Adding a sixth status means editing
 * one file and deciding which sets it belongs to, instead of finding every
 * `whereIn` that happens to enumerate statuses.
 */
enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Void = 'void';

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
     * Statuses that may no longer be rewritten.
     *
     * A void invoice is included: it was deliberately cancelled, and silently
     * regenerating it would undo that decision.
     *
     * @return list<string>
     */
    public static function settled(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value, self::Paid->value, self::Void->value];
    }

    /**
     * Statuses where the client has actually been charged.
     *
     * Distinct from {@see settled()} by excluding void, and from
     * {@see collectible()} by including fully paid. This is the set to use when
     * asking "did this invoice bill anyone?" - a draft has not, so counting one
     * as billed means those hours are never charged at all.
     *
     * @return list<string>
     */
    public static function charged(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value, self::Paid->value];
    }

    /**
     * Statuses with money still outstanding.
     *
     * @return list<string>
     */
    public static function collectible(): array
    {
        return [self::Issued->value, self::PartiallyPaid->value];
    }

    /**
     * Statuses that count toward balances and history.
     *
     * Everything except void, which by definition never happened.
     *
     * @return list<string>
     */
    public static function live(): array
    {
        return array_values(array_diff(self::all(), [self::Void->value]));
    }

    public function isSettled(): bool
    {
        return in_array($this->value, self::settled(), true);
    }

    public function hasCharged(): bool
    {
        return in_array($this->value, self::charged(), true);
    }

    /**
     * Parse a stored value, defaulting to draft.
     *
     * A row carrying something unrecognised is treated as the least privileged
     * state rather than throwing, so one bad row cannot stop a billing run.
     */
    public static function fromStored(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Draft;
    }
}
