<?php

namespace App\Services\ExternalImport;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Checks that a database declared a restore still says what it said at import.
 *
 * The ordinary check is a hash of the whole source row, taken at import and
 * compared on any later read. That is the right check for a source nobody
 * touches, and the wrong one for a source that kept being used: it collapses
 * "an operator renumbered the invoices and tidied up" and "the money is
 * different" into the same answer, and offers no way to tell them apart.
 *
 * This asks a narrower question with a more useful answer. Every row the
 * importer wrote is a copy of the source row as it was, so comparing the source
 * against the destination says exactly which columns have moved since - by
 * name, with a count, instead of a yes or no.
 *
 * Columns the destination holds as null are skipped: those are the holes the
 * backfill exists to fill, and there is nothing to compare them against. Their
 * trustworthiness rests on the rest of the row still agreeing, which is what
 * this measures.
 *
 * Nothing here decides what is acceptable. It reports, the caller decides, and
 * the caller is made to name any drift it will tolerate.
 */
final class RestoreAgreementVerifier
{
    /**
     * Destination column => how the importer derived it from the source row.
     *
     * Only columns carrying a copy of source data. Foreign keys are omitted -
     * they are remapped identifiers rather than values, so a difference would
     * say nothing about whether the source changed.
     *
     * @return array<string, array<string, callable(array<string, mixed>): mixed>>
     */
    public static function comparableColumns(): array
    {
        return [
            'client_invoices' => [
                'invoice_number' => fn (array $r): mixed => $r['invoice_number'] ?? null,
                'status' => fn (array $r): mixed => $r['status'] ?? null,
                'issue_date' => fn (array $r): mixed => self::date($r['issue_date'] ?? null),
                'due_date' => fn (array $r): mixed => self::date($r['due_date'] ?? null),
                'service_period_start' => fn (array $r): mixed => self::date($r['period_start'] ?? null),
                'service_period_end' => fn (array $r): mixed => self::date($r['period_end'] ?? null),
                'subtotal_amount' => fn (array $r): mixed => self::minor($r['invoice_total'] ?? null),
                'total_amount' => fn (array $r): mixed => self::minor($r['invoice_total'] ?? null),
                'notes' => fn (array $r): mixed => $r['notes'] ?? null,
            ],
            'client_invoice_lines' => [
                // These mirror the importer's defaults exactly. Without
                // that, a source column the importer defaulted reads as
                // null here and looks like the source has been cleared.
                'description' => fn (array $r): mixed => $r['description'] ?? 'External invoice line',
                'type' => fn (array $r): mixed => $r['line_type'] ?? 'adjustment',
                'unit_amount' => fn (array $r): mixed => self::minor($r['unit_price'] ?? null),
                'total_amount' => fn (array $r): mixed => self::minor($r['line_total'] ?? null),
                'sort_order' => fn (array $r): mixed => $r['sort_order'] ?? null,
                'line_date' => fn (array $r): mixed => self::date($r['line_date'] ?? null),
            ],
            'client_time_entries' => [
                'worked_on' => fn (array $r): mixed => self::date($r['date_worked'] ?? null),
                'minutes' => fn (array $r): mixed => $r['minutes_worked'] ?? null,
                'description' => fn (array $r): mixed => $r['name'] ?? '',
                'is_billable' => fn (array $r): mixed => self::bool($r['is_billable'] ?? null),
                'is_deferred' => fn (array $r): mixed => self::bool($r['is_deferred_billing'] ?? null),
                'job_type' => fn (array $r): mixed => $r['job_type'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, int>  $idMap  source key => destination row id
     * @return array{compared: int, skipped: int, missing: int, drift: array<string, int>}
     */
    public function verify(
        ConnectionInterface $source,
        string $sourceTable,
        string $sourceKey,
        string $destinationTable,
        string $destinationConnection,
        array $idMap,
    ): array {
        $columns = self::comparableColumns()[$destinationTable] ?? [];
        if ($columns === []) {
            return ['compared' => 0, 'skipped' => 0, 'missing' => 0, 'drift' => []];
        }

        $stored = DB::connection($destinationConnection)
            ->table($destinationTable)
            ->whereIn('id', array_values($idMap))
            ->get()
            ->keyBy('id');

        $compared = 0;
        $skipped = 0;
        $drift = [];
        // Every row the ledger says was imported from here. Walking the source
        // alone meant a restore that had lost rows produced no drift at all -
        // the missing ones were simply never compared, and the backfill then
        // walked the same shortened source and reported nothing unmatched. A
        // partial restore read exactly like a faithful one.
        $expected = array_fill_keys(array_keys($idMap), true);

        foreach ($source->table($sourceTable)->orderBy($sourceKey)->cursor() as $row) {
            $arr = (array) $row;
            $key = (string) ($arr[$sourceKey] ?? '');
            $id = $idMap[$key] ?? null;
            if ($id === null || ! isset($stored[$id])) {
                continue;
            }

            unset($expected[$key]);

            $destination = (array) $stored[$id];
            $compared++;

            foreach ($columns as $column => $derive) {
                if (! array_key_exists($column, $destination) || $destination[$column] === null) {
                    // Nothing was imported here, so there is nothing to disagree
                    // with. This is the shape of every column being backfilled.
                    $skipped++;

                    continue;
                }

                if (! self::same($derive($arr), $destination[$column])) {
                    $drift[$column] = ($drift[$column] ?? 0) + 1;
                }
            }
        }

        return [
            'compared' => $compared,
            'skipped' => $skipped,
            'missing' => count($expected),
            'drift' => $drift,
        ];
    }

    private static function same(mixed $expected, mixed $stored): bool
    {
        if ($expected === null) {
            // The source column is empty where the destination holds a value.
            // That is a real difference - the source has been cleared since -
            // and it is only reachable now that the derivers above mirror the
            // importer's defaults. Returning true here masked it.
            return false;
        }

        if (is_numeric($expected) && is_numeric($stored)) {
            return abs((float) $expected - (float) $stored) < 0.0001;
        }

        // A date column comes back as `2026-03-01` from MySQL and as
        // `2026-03-01 00:00:00` from SQLite, so truncating only the source side
        // made every date look changed on one engine and not the other. Both
        // sides are cut to the day when the expected value is a plain date.
        if (is_string($expected) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expected) === 1) {
            return $expected === substr((string) $stored, 0, 10);
        }

        return (string) $expected === (string) $stored;
    }

    private static function date(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    private static function minor(mixed $value): ?int
    {
        return $value === null ? null : (int) round(((float) $value) * 100);
    }

    private static function bool(mixed $value): ?int
    {
        return $value === null ? null : (int) (bool) $value;
    }
}
