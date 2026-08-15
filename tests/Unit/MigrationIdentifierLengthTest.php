<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * MySQL truncates nothing: identifier names over 64 characters fail the whole
 * migration with SQLSTATE 1059 at deploy time. The test suite runs on SQLite,
 * which happily accepts longer names, so without this guard the failure is
 * invisible until production `artisan migrate` (which is exactly how the
 * engagement-workflow migration failed on 2026-08-15). Give composite indexes
 * an explicit short name when the generated one would exceed the limit.
 */
class MigrationIdentifierLengthTest extends TestCase
{
    private const int MYSQL_IDENTIFIER_LIMIT = 64;

    public function test_all_generated_migration_identifier_names_fit_mysql(): void
    {
        $offenders = [];

        foreach (glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $tables = $this->tableSpans($source);

            preg_match_all('/->(index|unique)\\(\\[([^\\]]+)\\]\\)(?!\\s*,)/', $source, $composites, PREG_OFFSET_CAPTURE);
            foreach ($composites[0] as $i => [, $offset]) {
                $type = $composites[1][$i][0];
                $columns = array_map(
                    static fn (string $column): string => trim($column, " '\""),
                    explode(',', $composites[2][$i][0]),
                );
                $name = $this->tableAt($tables, (int) $offset).'_'.implode('_', $columns).'_'.$type;
                if (strlen($name) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $offenders[] = basename($file).': '.$name.' ('.strlen($name).')';
                }
            }

            preg_match_all("/foreignId\\('(\\w+)'\\)[^;]*constrained/", $source, $foreigns, PREG_OFFSET_CAPTURE);
            foreach ($foreigns[0] as $i => [, $offset]) {
                $name = $this->tableAt($tables, (int) $offset).'_'.$foreigns[1][$i][0].'_foreign';
                if (strlen($name) > self::MYSQL_IDENTIFIER_LIMIT) {
                    $offenders[] = basename($file).': '.$name.' ('.strlen($name).')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Generated identifier names exceed MySQL's 64-character limit; pass an explicit short name:\n".implode("\n", $offenders),
        );
    }

    /** @return list<array{string, int, int}> */
    private function tableSpans(string $source): array
    {
        preg_match_all("/Schema::(?:create|table)\\('(\\w+)'/", $source, $matches, PREG_OFFSET_CAPTURE);
        $spans = [];
        foreach ($matches[1] as $i => [$table, $offset]) {
            $end = $matches[1][$i + 1][1] ?? strlen($source);
            $spans[] = [$table, (int) $offset, (int) $end];
        }

        return $spans;
    }

    /** @param list<array{string, int, int}> $spans */
    private function tableAt(array $spans, int $offset): string
    {
        $current = '';
        foreach ($spans as [$table, $start, $end]) {
            if ($offset >= $start && $offset < $end) {
                $current = $table;
            }
        }

        return $current;
    }
}
