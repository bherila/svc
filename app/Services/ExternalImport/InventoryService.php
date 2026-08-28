<?php

namespace App\Services\ExternalImport;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

final class InventoryService
{
    /**
     * @param  list<array<string, mixed>>  $specs
     * @return array<string, array<string, mixed>>
     */
    public function inspect(ConnectionInterface $source, array $specs, string $runtimeConnectionName): array
    {
        $tables = [];
        $seen = [];
        $sourceKeys = [];
        foreach ($specs as $spec) {
            $sourceKeys[(string) $spec['source_table']] = (string) $spec['source_key'];
        }

        foreach ($specs as $spec) {
            $table = (string) $spec['source_table'];
            if (isset($seen[$table]) || ! Schema::connection($runtimeConnectionName)->hasTable($table)) {
                continue;
            }
            $seen[$table] = true;

            $columns = Schema::connection($runtimeConnectionName)->getColumnListing($table);
            $key = (string) $spec['source_key'];
            // What the source still has, not what it once had. The
            // predecessor soft-deletes, and counting deleted rows here made the
            // inventory disagree with what the import would actually write.
            $rows = fn (): Builder => SourceRows::for($source, $runtimeConnectionName, $table, $columns);
            $rowCount = (int) $rows()->count();
            $dateColumn = null;
            foreach (($spec['date_columns'] ?? []) as $candidate) {
                if (in_array($candidate, $columns, true)) {
                    $dateColumn = $candidate;
                    break;
                }
            }
            $dateRange = ['from' => null, 'to' => null];
            if (is_string($dateColumn)) {
                $dateRange = ['from' => $rows()->min($dateColumn), 'to' => $rows()->max($dateColumn)];
            }

            $keyPresent = in_array($key, $columns, true);
            $distinct = $keyPresent ? (int) $rows()->distinct()->count($key) : 0;
            $duplicates = $keyPresent ? max(0, $rowCount - $distinct) : 0;
            $fingerprintRows = $keyPresent
                ? $rows()->orderBy($key)->cursor()->map(fn ($row): array => (array) $row)
                : [];
            $highWater = [
                'max_key' => $keyPresent ? $rows()->max($key) : null,
                'max_updated_at' => in_array('updated_at', $columns, true) ? $rows()->max('updated_at') : null,
            ];

            $tables[$table] = [
                'row_count' => $rowCount,
                'date_range' => $dateRange,
                'orphan_count' => $this->orphanCount($source, $runtimeConnectionName, $spec, $seen, $sourceKeys),
                'duplicate_count' => $duplicates,
                'fingerprint' => Fingerprint::rows($fingerprintRows),
                'high_water_mark' => $highWater,
                'key_column_present' => $keyPresent,
            ];
        }

        return $tables;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, bool>  $seen
     * @param  array<string, string>  $sourceKeys
     */
    private function orphanCount(ConnectionInterface $source, string $runtimeConnectionName, array $spec, array $seen, array $sourceKeys): int
    {
        $parents = array_values(array_filter($spec['parents'] ?? [], fn (array $parent): bool => ($parent['required'] ?? false) && isset($seen[$parent['source_table']])));
        if ($parents === []) {
            return 0;
        }

        $child = $spec['source_table'];

        return (int) SourceRows::for($source, $runtimeConnectionName, $child)->where(function ($query) use ($parents, $child, $sourceKeys): void {
            foreach ($parents as $index => $parent) {
                $column = $parent['source_column'];
                $parentTable = $parent['source_table'];
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}(function ($condition) use ($column, $parentTable, $child, $sourceKeys): void {
                    $parentKey = $sourceKeys[$parentTable] ?? 'id';
                    $condition->whereNull($column)->orWhereNotExists(fn ($subquery) => $subquery->selectRaw('1')->from($parentTable)->whereColumn("{$parentTable}.{$parentKey}", "{$child}.{$column}"));
                });
            }
        })->count();
    }
}
