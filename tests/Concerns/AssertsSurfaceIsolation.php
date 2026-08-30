<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/**
 * Cross-surface isolation invariants, extracted from the time-sheet suite.
 *
 * Every read surface — an Inertia page, a JSON index — should hold the same
 * three properties: nothing from a record the viewer cannot see appears
 * anywhere in the payload, the number of queries does not grow with the
 * number of rows, and every identifier the surface's queries quote names a
 * real table, a real column, or an alias the query declared.
 *
 * Each assertion refuses to pass vacuously: an empty secret list, an empty
 * payload, zero captured statements, or zero checked identifiers is a
 * failure, not a pass. A guard that can inspect nothing and stay green is
 * the defect this trait exists to prevent.
 */
trait AssertsSurfaceIsolation
{
    /**
     * Nothing from the secret list appears anywhere in an Inertia payload.
     *
     * The scan runs over the serialized page as one string rather than
     * asserting fields, so a leak through any prop — a relation serialized
     * on its parent's authority, an eager load that outran the filter —
     * fails regardless of where it surfaces. The control string proves the
     * surface was not empty for the wrong reason.
     *
     * @param  list<string>  $secrets
     */
    protected function assertInertiaPayloadOmits(TestResponse $response, array $secrets, string $mustContain): void
    {
        $this->assertNotEmpty($secrets, 'No secrets were given, so this asserted nothing.');

        $response->assertInertia(function (AssertableInertia $page) use ($secrets, $mustContain): void {
            $this->assertPayloadOmits((string) json_encode($page->toArray()), $secrets, $mustContain);
        });
    }

    /**
     * Nothing from the secret list appears anywhere in a JSON response body.
     *
     * @param  list<string>  $secrets
     */
    protected function assertJsonPayloadOmits(TestResponse $response, array $secrets, string $mustContain): void
    {
        $this->assertNotEmpty($secrets, 'No secrets were given, so this asserted nothing.');

        $this->assertPayloadOmits((string) $response->getContent(), $secrets, $mustContain);
    }

    /** @param list<string> $secrets */
    private function assertPayloadOmits(string $payload, array $secrets, string $mustContain): void
    {
        $this->assertNotSame('', $payload, 'The payload is empty, so this asserted nothing.');

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $payload,
                "'{$secret}' belongs to a record the viewer cannot see and must not reach the payload.",
            );
        }

        $this->assertStringContainsString(
            $mustContain,
            $payload,
            'The visible control string is absent, so the surface may be empty for the wrong reason.',
        );
    }

    /**
     * Rendering issues the same number of queries however many rows exist.
     *
     * Comparing two renders of different sizes fixes the shape rather than a
     * number, so this neither needs updating when a query is legitimately
     * added nor passes when one starts repeating per row.
     *
     * @param  callable(): void  $render  renders the surface once, asserting success
     * @param  callable(): void  $addRows  grows the data the surface lists
     */
    protected function assertQueryCountIndependentOfRows(callable $render, callable $addRows): void
    {
        $few = $this->queriesDuring($render);

        $this->assertGreaterThan(0, $few, 'The first render issued no queries, so this asserted nothing.');

        $addRows();

        $many = $this->queriesDuring($render);

        $this->assertGreaterThan(0, $many, 'The second render issued no queries, so this asserted nothing.');

        $this->assertSame(
            $few,
            $many,
            'The surface issued more queries for more rows, which is an N+1.',
        );
    }

    /** @param callable(): void $act */
    protected function queriesDuring(callable $act): int
    {
        $connection = DB::connection();
        $wasLogging = $connection->logging();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $act();

            return count($connection->getQueryLog());
        } finally {
            if (! $wasLogging) {
                $connection->disableQueryLog();
            }
        }
    }

    /**
     * @return array<string, array<string, true>>
     */
    private function columnsByTable(): array
    {
        $columnsByTable = [];

        foreach (Schema::getTableListing() as $table) {
            // The listing qualifies names with their schema (`main.workspaces`
            // on SQLite); the grammar quotes the bare name.
            $bare = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
            $columns = [];

            foreach (Schema::getColumnListing($bare) as $column) {
                $columns[strtolower($column)] = true;
            }

            $columnsByTable[strtolower($bare)] = $columns;
        }

        return $columnsByTable;
    }

    /**
     * Every identifier the surface's queries quote names a real table, a
     * real column, or an alias the query itself declared.
     *
     * An unresolvable double-quoted identifier degrades to a string literal
     * on SQLite — `where "typo_column" is not null` reads as a non-empty
     * string and admits every row — while MariaDB refuses it outright, so
     * the local suite cannot see the class of bug at all. This is the
     * cheapest check that can. It reads either engine's quoting so it
     * asserts the same thing on both.
     *
     * A table's columns are admitted per statement, and only when the
     * statement itself names that table — a column borrowed from an
     * unrelated table fails even though it exists somewhere in the schema.
     * One scope is still not checked: a derived table that narrows its
     * inner projection can drop a column the outer query names, and telling
     * those levels apart needs a real SQL parser rather than this grammar
     * scan. The MariaDB CI lane is the runtime backstop there — these same
     * queries execute on it, and an unresolved identifier is a hard error
     * rather than a string.
     *
     * @param  callable(): void  $act  renders the surface, asserting success
     */
    protected function assertQueriesNameOnlyRealIdentifiers(callable $act): void
    {
        $columnsByTable = $this->columnsByTable();

        /** @var list<string> $statements */
        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $act();

        $this->assertNotEmpty($statements, 'No queries were captured, so this asserted nothing.');

        $checked = 0;

        foreach ($statements as $statement) {
            $allowed = [];
            // Either grammar's quoting — reading only SQLite's would make this
            // assert nothing on the engine that catches the bug outright — and
            // aliases declared bare (`... AS row_rank`), which the grammar
            // leaves unquoted inside raw window expressions.
            preg_match_all('/\bas\s+(?:[`"]([^`"]+)[`"]|([a-z_][a-z0-9_]*))/i', $statement, $aliases);

            foreach ([...$aliases[1], ...$aliases[2]] as $alias) {
                if ($alias !== '') {
                    $allowed[strtolower($alias)] = true;
                }
            }

            preg_match_all('/[`"]([^`"]+)[`"]/', $statement, $identifiers);

            foreach ($identifiers[1] as $identifier) {
                $normalized = strtolower($identifier);

                if (isset($columnsByTable[$normalized])) {
                    $allowed[$normalized] = true;
                    $allowed += $columnsByTable[$normalized];
                }
            }

            foreach ($identifiers[1] as $identifier) {
                $checked++;
                $this->assertArrayHasKey(
                    strtolower($identifier),
                    $allowed,
                    "`{$identifier}` is not a column, table or alias, so this predicate is silently a string on SQLite: {$statement}",
                );
            }
        }

        $this->assertGreaterThan(0, $checked, 'No identifiers were read, so this asserted nothing.');
    }
}
