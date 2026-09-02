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
     * `ESCAPE` is named explicitly because the drivers disagree without it.
     * MySQL happens to treat a backslash as an escape inside LIKE; SQLite does
     * not, so an unescaped `%` typed by a caller would be a wildcard on the
     * driver the suite runs on and the guard would pass its own tests while
     * doing nothing.
     *
     * @return literal-string
     */
    public function likeSql(): string
    {
        return match ($this) {
            self::Name => "name LIKE ? ESCAPE '\\'",
            self::Title => "title LIKE ? ESCAPE '\\'",
            self::InvoiceNumber => "invoice_number LIKE ? ESCAPE '\\'",
        };
    }
}
