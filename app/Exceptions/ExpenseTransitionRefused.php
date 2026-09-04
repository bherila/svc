<?php

namespace App\Exceptions;

use App\Support\Expenses\ExpenseStatus;
use RuntimeException;

/**
 * An expense was asked to do something its stored status does not allow.
 *
 * A `RuntimeException` rather than an `InvalidArgumentException`, and the
 * distinction is the point: a cross-tenant reference is a caller holding ids it
 * never checked, which is always a bug, while this is very often *not* one. Two
 * managers open the same expense, both press approve, and the second request is
 * refused because the row moved under it. Nothing is wrong with that caller; it
 * read the row before the other write landed.
 *
 * So this answers a lost race as much as a bad call, and every message names
 * the status the row is *now* rather than only what was refused - a caller that
 * can see what it lost to re-reads and decides, where one told merely "no"
 * retries the same stale write.
 *
 * The status is rendered as the raw stored value, not as an
 * {@see ExpenseStatus} case, because the refusal a value *outside* the enum
 * earns is the one most worth reading: such a row is the reason the status
 * readers fail closed, and rendering it as "unknown" would hide the only clue
 * to what put it there.
 */
final class ExpenseTransitionRefused extends RuntimeException
{
    public static function move(mixed $storedStatus, ExpenseStatus $to): self
    {
        return new self(sprintf(
            'An expense with status "%s" cannot become "%s".',
            self::render($storedStatus),
            $to->value,
        ));
    }

    public static function edit(mixed $storedStatus): self
    {
        return new self(sprintf(
            'An expense with status "%s" can no longer be edited; only a draft can.',
            self::render($storedStatus),
        ));
    }

    public static function discard(mixed $storedStatus): self
    {
        return new self(sprintf(
            'An expense with status "%s" is on a client bill and cannot be discarded.',
            self::render($storedStatus),
        ));
    }

    private static function render(mixed $storedStatus): string
    {
        return is_string($storedStatus) ? $storedStatus : get_debug_type($storedStatus);
    }
}
