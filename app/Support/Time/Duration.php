<?php

declare(strict_types=1);

namespace App\Support\Time;

/**
 * The one parser and formatter for a length of time, in whole minutes.
 *
 * The twin of `resources/js/lib/duration.ts`; both read
 * `tests/Fixtures/duration-cases.json`, so a change to one that the other does
 * not follow fails a test rather than showing two screens two answers.
 *
 * Strict types are on so a dropped cast is a TypeError, not a silent coercion.
 */
final class Duration
{
    /** Longer than any single entry could be; keeps arithmetic far from overflow. */
    private const int MAX_MINUTES = 99999 * 60;

    /**
     * Read `2:30`, `2.5`, `2.5h`, `2h30m`, `2h 30m`, `150m` or `150min` as minutes.
     *
     * Null for anything negative, empty or ambiguous (`2,5`, `2h30`, `1h 90m`)
     * rather than a guess, so a caller leaves the field alone instead of writing
     * a number the person did not mean.
     */
    public static function parse(string $input): ?int
    {
        $minutes = self::parseText(strtolower(trim($input)));

        return $minutes !== null && $minutes <= self::MAX_MINUTES ? $minutes : null;
    }

    public static function format(int $minutes, DurationStyle $style): string
    {
        return match ($style) {
            DurationStyle::Decimal => self::decimal($minutes),
            DurationStyle::Clock => self::clock($minutes),
        };
    }

    /** Invoices ignore the reader's preference. */
    public static function forInvoice(int $minutes): string
    {
        return self::decimal($minutes);
    }

    private static function parseText(string $text): ?int
    {
        if (preg_match('/^(?<h>\d{1,5}):(?<m>[0-5]\d)$/', $text, $m) === 1) {
            return self::wholeMinutes($m['h'], $m['m']);
        }

        if (preg_match('/^(?<n>\d{1,5}(?:\.\d+)?)\s*m(?:in(?:ute)?s?)?$/', $text, $m) === 1) {
            return (int) round((float) $m['n']);
        }

        if (preg_match('/^(?<n>\d{1,5}(?:\.\d+)?)\s*(?:h|hr|hrs|hour|hours)?$/', $text, $m) === 1) {
            return (int) round((float) $m['n'] * 60);
        }

        if (preg_match('/^(?<h>\d{1,5})\s*(?:h|hr|hrs|hour|hours)\s*(?<m>\d{1,2})\s*m(?:in(?:ute)?s?)?$/', $text, $m) === 1) {
            return (int) $m['m'] < 60 ? self::wholeMinutes($m['h'], $m['m']) : null;
        }

        return null;
    }

    /**
     * Hours and minutes, both digit strings the pattern already vouched for.
     *
     * @infection-ignore-all The `(int)` casts here are equivalent mutants: PHP
     * multiplies and adds numeric strings as integers even under strict types
     * (strictness covers arguments and returns, not arithmetic), so dropping a
     * cast cannot change the result.
     */
    private static function wholeMinutes(string $hours, string $minutes): int
    {
        return (int) $hours * 60 + (int) $minutes;
    }

    private static function decimal(int $minutes): string
    {
        $text = rtrim(rtrim(number_format($minutes / 60, 2, '.', ''), '0'), '.');

        return ($text === '-0' ? '0' : $text).' h';
    }

    private static function clock(int $minutes): string
    {
        $total = abs($minutes);

        return ($minutes < 0 ? '-' : '').intdiv($total, 60).':'.str_pad((string) ($total % 60), 2, '0', STR_PAD_LEFT);
    }
}
