<?php

namespace App\Services\Search;

/**
 * The columns the palette matches against, and the SQL that matches them.
 *
 * An enum rather than a string parameter because the comparison has to be
 * written raw - the query builder has no `ESCAPE` clause - and raw SQL built
 * from a parameter is one refactor away from being built from a request. With
 * the cases enumerated here, the only strings that can reach the query are the
 * literals below, and adding a searchable column is a case rather than a new
 * call site with its own spelling.
 */
enum SearchColumn
{
    case Name;
    case Title;
    case InvoiceNumber;

    /**
     * The escape character, and why it is not a backslash.
     *
     * `ESCAPE` has to be named at all because the drivers disagree without it:
     * MySQL happens to treat a backslash as an escape inside LIKE, SQLite does
     * not, so an unescaped `%` typed by a caller is a wildcard on one of them
     * and the guard passes its own tests while doing nothing.
     *
     * Naming a *backslash* then fails in the other direction, and worse. In
     * MariaDB a backslash escapes the following character inside a string
     * literal, so `ESCAPE '\'` is an unterminated string and the whole
     * statement is a syntax error - every search 500s. SQLite has no such rule
     * and accepts it, which is exactly the wrong way round for catching it:
     * green on the suite's driver, broken on the server's.
     *
     * `!` is special to neither parser, so one spelling works on both. It is
     * escaped in the search term like any other wildcard - see
     * {@see WorkspaceSearch::escapeForLike()}.
     *
     * @return literal-string
     */
    public function likeSql(): string
    {
        return match ($this) {
            self::Name => "name LIKE ? ESCAPE '!'",
            self::Title => "title LIKE ? ESCAPE '!'",
            self::InvoiceNumber => "invoice_number LIKE ? ESCAPE '!'",
        };
    }
}
