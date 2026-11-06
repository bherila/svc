<?php

declare(strict_types=1);

namespace App\Support\Submission;

/**
 * The approved timesheet as CSV (docs/ux-overhaul.md section 7e).
 *
 * Pure: it takes the rows the query presents and returns text. Two things it is
 * careful about, because the file is opened in a spreadsheet by someone else:
 * a cell that begins with `=`, `+`, `-`, `@`, a tab or a carriage return is
 * written with a leading apostrophe so it is read as text and never as a
 * formula, and fields are quoted by the standard rules, so a description with a
 * comma, quote or line break stays in its cell.
 */
final class TimesheetCsv
{
    /** @var list<string> */
    public const array HEADER = ['Date', 'Project', 'Task', 'Description', 'Hours', 'Minutes', 'Worked by', 'Approved by', 'Approved how', 'Approved on'];

    /**
     * @param  list<array<string, mixed>>  $entries  the query's rows
     */
    public static function render(array $entries): string
    {
        $lines = [self::line(self::HEADER)];
        foreach ($entries as $entry) {
            $lines[] = self::line([
                (string) $entry['worked_on'],
                (string) ($entry['project'] ?? ''),
                (string) ($entry['task'] ?? ''),
                (string) $entry['description'],
                self::hours((int) $entry['minutes']),
                (string) (int) $entry['minutes'],
                (string) ($entry['worked_by'] ?? ''),
                (string) ($entry['approved_by'] ?? ''),
                (string) $entry['approved_via'],
                substr((string) ($entry['approved_at'] ?? ''), 0, 10),
            ]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /** Whole minutes as decimal hours to two places: 90 is `1.50`. */
    public static function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::cell(...), $cells));
    }

    private static function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'".$value;
        }

        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
