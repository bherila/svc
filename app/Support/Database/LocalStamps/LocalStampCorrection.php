<?php

namespace App\Support\Database\LocalStamps;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Rewrites the values {@see LocalStampClassifier} finds were written in a
 * workspace's wall clock before #712 to the UTC instant they meant, and
 * reports the ones it cannot decide.
 *
 * Only workspaces whose zone was off UTC while the wall clock was stored are
 * visited. Rows the retired importer wrote carry their source's times and are
 * skipped. Writes go through the query builder, so no `updated_at` moves, and
 * the whole run is one transaction: a second run would read corrected values
 * of companion-less tables as local again.
 */
final class LocalStampCorrection
{
    /** Values later than this are #712's own UTC writes whatever the zone. */
    private const SCAN_UNTIL = '2026-11-15 00:00:00';

    /**
     * @param  list<LocalStampSpec>  $specs
     * @return array{corrected: array<string, int>, ambiguous: array<string, list<int>>}
     */
    public static function run(Connection $db, array $specs): array
    {
        return $db->transaction(static function () use ($db, $specs): array {
            $report = ['corrected' => [], 'ambiguous' => []];
            foreach (self::offUtcWorkspaces($db) as $workspaceId => $classifier) {
                foreach ($specs as $spec) {
                    self::correctSpec($db, $spec, $workspaceId, $classifier, $report);
                }
            }
            ksort($report['corrected']);
            ksort($report['ambiguous']);

            return $report;
        });
    }

    /** @return array<int, LocalStampClassifier> */
    private static function offUtcWorkspaces(Connection $db): array
    {
        $classifiers = [];
        foreach ($db->table('workspaces')->orderBy('id')->get(['id', 'timezone']) as $workspace) {
            $zone = is_string($workspace->timezone) ? trim($workspace->timezone) : '';
            try {
                $classifier = new LocalStampClassifier(new DateTimeZone($zone === '' ? 'UTC' : $zone));
            } catch (Exception) {
                // WorkspaceClock would have failed on this zone too, so nothing
                // was written in it.
                continue;
            }
            if ($classifier->isOffUtc()) {
                $classifiers[(int) $workspace->id] = $classifier;
            }
        }

        return $classifiers;
    }

    /** @param  array{corrected: array<string, int>, ambiguous: array<string, list<int>>}  $report */
    private static function correctSpec(Connection $db, LocalStampSpec $spec, int $workspaceId, LocalStampClassifier $classifier, array &$report): void
    {
        $select = array_values(array_unique(array_filter(['id', $spec->companion, ...$spec->columns])));
        // An expiry is set days ahead of the write it came from.
        $until = CarbonImmutable::parse(self::SCAN_UNTIL, 'UTC')->addDays($spec->companionShiftDays)->format('Y-m-d H:i:s');
        $rows = $db->table($spec->table)
            ->where($spec->tenant, $workspaceId)
            ->where(function (Builder $candidates) use ($spec, $until): void {
                foreach ($spec->columns as $column) {
                    $candidates->orWhere(fn (Builder $set): Builder => $set->whereNotNull($column)->where($column, '<', $until));
                }
            });
        if ($spec->mayBeImported) {
            $rows->whereNotExists(fn (Builder $imported): Builder => $imported->selectRaw('1')->from('external_import_items')
                ->whereColumn('external_import_items.target_public_id', $spec->table.'.public_id'));
        }

        $rows->select($select)->chunkById(500, static function ($chunk) use ($db, $spec, $classifier, $until, &$report): void {
            foreach ($chunk as $row) {
                $values = (array) $row;
                $companion = $spec->companion === null ? null : $values[$spec->companion];
                $updates = [];
                foreach ($spec->columns as $column) {
                    $stored = $values[$column];
                    if (! is_string($stored) || $stored >= $until) {
                        continue;
                    }
                    $verdict = $classifier->classify($spec, $stored, is_string($companion) ? $companion : null);
                    $key = $spec->table.'.'.$column;
                    if ($verdict->kind === LocalStampVerdict::CORRECTED) {
                        $updates[$column] = $verdict->stored();
                        $report['corrected'][$key] = ($report['corrected'][$key] ?? 0) + 1;
                        Log::info('Corrected a timestamp written in workspace-local time before #712.', [
                            'table' => $spec->table, 'id' => (int) $values['id'], 'column' => $column, 'stored' => $stored, 'corrected' => $verdict->stored(),
                        ]);
                    } elseif ($verdict->kind === LocalStampVerdict::AMBIGUOUS) {
                        $report['ambiguous'][$key][] = (int) $values['id'];
                    }
                }
                if ($updates !== []) {
                    $db->table($spec->table)->where('id', $values['id'])->update($updates);
                }
            }
        });
    }
}
