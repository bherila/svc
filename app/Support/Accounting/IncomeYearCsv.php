<?php

declare(strict_types=1);

namespace App\Support\Accounting;

/**
 * The yearly income export as CSV: a header, one row per month and a total
 * row, carrying exactly the figures of the JSON export. Every cell is a fixed
 * token, a month or an integer, so nothing needs quoting and nothing a
 * client typed reaches the file.
 */
final class IncomeYearCsv
{
    /**
     * @param  array{months: list<array<string, int|string>>, totals: array<string, int>}  $report  an {@see IncomeYear::toArray()}
     * @return list<string> the lines, without line endings
     */
    public static function lines(array $report): array
    {
        $lines = [implode(',', ['month', ...IncomeYear::COLUMNS])];
        foreach ($report['months'] as $month) {
            $lines[] = implode(',', [$month['month'], ...array_map(static fn (string $column): int|string => $month[$column], IncomeYear::COLUMNS)]);
        }
        $lines[] = implode(',', ['total', ...array_map(static fn (string $column): int => $report['totals'][$column], IncomeYear::COLUMNS)]);

        return $lines;
    }
}
