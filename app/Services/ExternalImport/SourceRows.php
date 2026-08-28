<?php

namespace App\Services\ExternalImport;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The rows a source table still has.
 *
 * The predecessor soft-deletes. The import read every row regardless, so
 * everything the source had thrown away arrived as live data: of 78 invoices it
 * brought across 49 deleted ones, and of 822 invoice lines it brought across
 * 764.
 *
 * That is not a tidiness problem. Deleted lines were counted into invoice
 * totals, which made 14 invoices disagree with the sum of their own lines -
 * read, twice and by two reviewers, as corruption in the source. The source is
 * consistent. Filtered to what it still has, every invoice reconciles.
 *
 * It also produced ten periods holding up to seven invoices each, every extra
 * one a deleted draft; the generator refused to build those months because a
 * deleted invoice appeared to occupy the period, and the replay then reported
 * the resulting empty invoice as a divergence.
 *
 * So the rule lives here rather than at each call site. A source table without
 * the column is unfiltered, which is the same behaviour as before.
 */
final class SourceRows
{
    /**
     * Tables whose deleted rows are still needed.
     *
     * A recurring item is imported as `is_active = false` when the source has
     * deleted it - deliberately, because a live historical invoice line points
     * at the item that produced its charge. Dropping the row would resolve that
     * reference to null and lose the link between a charge and its reason,
     * which is worse than carrying an inactive row.
     *
     * The rule is narrow on purpose: the question is not "was it deleted" but
     * "does anything still reference it". Add a table here only when something
     * live points at its deleted rows.
     *
     * @var list<string>
     */
    private const KEEP_DELETED = [
        'client_agreement_recurring_items',
    ];

    /**
     * The runtime connection name is passed rather than read off the
     * connection: `ConnectionInterface` does not expose one, and the source may
     * be running under a temporary name the guard assigned it.
     *
     * @param  array<int, string>|null  $columns  the table's columns, when the caller already has them
     */
    public static function for(
        ConnectionInterface $source,
        string $connectionName,
        string $table,
        ?array $columns = null,
    ): Builder {
        $query = $source->table($table);

        if (in_array($table, self::KEEP_DELETED, true)) {
            return $query;
        }

        $columns ??= Schema::connection($connectionName)->getColumnListing($table);

        return in_array('deleted_at', $columns, true)
            ? $query->whereNull($table.'.deleted_at')
            : $query;
    }
}
