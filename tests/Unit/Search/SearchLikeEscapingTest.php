<?php

namespace Tests\Unit\Search;

use App\Services\Search\SearchColumn;
use App\Services\Search\WorkspaceSearch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The LIKE escaping, pinned on the driver that cannot catch it by running.
 *
 * This exists because the same three lines have now been wrong in both
 * directions, and neither way was visible from the suite's own driver:
 *
 * 1. Escaping with `addcslashes` and no `ESCAPE` clause. MySQL treats a
 *    backslash as an escape inside LIKE, SQLite does not - so the guard was
 *    inert on SQLite and a bare `%` matched everything.
 * 2. Adding `ESCAPE '\'`. In MariaDB a backslash escapes the next character
 *    inside a string literal, so that is an unterminated string and every
 *    search became a 500. SQLite accepted it, so the suite stayed green and
 *    only the MariaDB lane failed.
 *
 * Both are properties of the *SQL text*, not of any query result, so they are
 * asserted against the text. A behavioural test can only ever prove the half
 * its driver happens to implement.
 */
class SearchLikeEscapingTest extends TestCase
{
    /**
     * The escape character has to be one neither driver's string-literal
     * parser treats specially. A backslash is the specific value that breaks
     * MariaDB, and it is the value a future edit is most likely to reach for,
     * because it is what `addcslashes` and most escaping helpers emit.
     */
    #[DataProvider('columns')]
    public function test_no_column_escapes_with_a_backslash(SearchColumn $column): void
    {
        $sql = $column->likeSql();

        $this->assertStringNotContainsString('\\', $sql, 'A backslash here is an unterminated string literal in MariaDB');
        $this->assertStringContainsString("ESCAPE '!'", $sql);
    }

    /**
     * An `ESCAPE` clause with nothing escaping is the first failure above, and
     * an escaper whose output does not match the clause is the same defect
     * wearing the other hat. The two have to be read together, so they are
     * asserted together.
     */
    #[DataProvider('columns')]
    public function test_the_escaper_emits_the_character_the_clause_declares(SearchColumn $column): void
    {
        $this->assertStringContainsString(
            "ESCAPE '".substr(WorkspaceSearch::escapeForLike('%'), 0, 1)."'",
            $column->likeSql(),
        );
    }

    public function test_it_neutralises_both_wildcards_and_its_own_escape(): void
    {
        $this->assertSame('!%', WorkspaceSearch::escapeForLike('%'));
        $this->assertSame('!_', WorkspaceSearch::escapeForLike('_'));
        // The escape character first, so a caller typing it gets a literal one
        // rather than a dangling escape that swallows the next character.
        $this->assertSame('!!', WorkspaceSearch::escapeForLike('!'));
        $this->assertSame('50!% !_off!!', WorkspaceSearch::escapeForLike('50% _off!'));
    }

    /** Ordinary text passes through untouched, so the escaping cannot alter a normal search. */
    public function test_it_leaves_ordinary_text_alone(): void
    {
        $this->assertSame('Acme Widgets 2026', WorkspaceSearch::escapeForLike('Acme Widgets 2026'));
    }

    /** @return iterable<string, array{SearchColumn}> */
    public static function columns(): iterable
    {
        foreach (SearchColumn::cases() as $column) {
            yield $column->name => [$column];
        }
    }
}
