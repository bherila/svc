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
     * Every currently recognized status except void, which by definition never
     * happened. This list is deliberately explicit: a newly-added case stays
     * excluded until billing code decides whether acting on it is safe.
     *
     * @return list<string>
     */
    public static function live(): array
    {
        return [self::Draft->value, self::Issued->value, self::PartiallyPaid->value, self::Paid->value];
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
     *
     * Do not use this to decide whether an invoice may be changed. Draft is the
     * least privileged state to *read* and the most permissive to *write*, so
     * collapsing an unknown status into it answers "may this be rewritten?" with
     * yes - exactly backwards. {@see isSettledValue()} and {@see hasChargedValue()}
     * ask those questions safely.
     */
    public static function fromStored(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Draft;
    }

    /**
     * May a stored status no longer be rewritten?
     *
     * An unrecognised value answers yes. This guards regeneration, and a status
     * this code does not understand is one it cannot show is safe to overwrite -
     * refusing to touch it costs a manual step, and overwriting it could rewrite
     * what a client has already paid against.
     */
    public static function isSettledValue(mixed $value): bool
    {
        $status = self::tryFrom((string) $value);

        return $status === null || $status->isSettled();
    }

    /**
     * Has a stored status charged the client?
     *
     * Unrecognised answers yes, for the same reason.
     */
    public static function hasChargedValue(mixed $value): bool
    {
        $status = self::tryFrom((string) $value);

        return $status === null || $status->hasCharged();
    }
}
