<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * The database session's zone, pinned to UTC, and the one-off move onto it.
 *
 * MariaDB stores a TIMESTAMP as an instant and converts it through the
 * session's `time_zone` on every read and write; DATETIME it stores as
 * written. The app writes UTC strings, so the conversion is only harmless
 * while the session is UTC. Without a `timezone` on the connection the
 * session took the server's default - on web1 SYSTEM, which follows the OS
 * clock in Europe/London - and a UTC string written in British summer time
 * was stored an hour early. The app read back what it wrote; `mysqldump`,
 * `UNIX_TIMESTAMP()`, a restore onto another server, and any SQL `NOW()`
 * saw the hour, and the hour the clocks go forward could not be stored at
 * all.
 *
 * `config/database.php` now pins the session to {@see self::ZONE}.
 * {@see self::reexpress()} rewrites what was stored under the old zone so a
 * UTC session reads every value exactly as the old session did.
 */
final class UtcTimestampSession
{
    public const ZONE = '+00:00';

    /** A summer and a winter instant: a zone that converts neither is UTC. */
    private const PROBES = ['2026-07-01 12:00:00', '2026-01-15 12:00:00'];

    /**
     * Re-express every TIMESTAMP value in the connection's database that was
     * written by sessions in `$writtenIn`, so that this UTC session reads the
     * string those sessions read.
     *
     * Each value is rendered as `$writtenIn` rendered it - `CONVERT_TZ` from
     * UTC, the engine's own conversion, so DST is decided exactly as it was
     * on every read - and stored under UTC. One statement per table assigns
     * every TIMESTAMP column at once, so no `ON UPDATE CURRENT_TIMESTAMP`
     * column moves. DATETIME columns never went through the session zone and
     * are left alone.
     *
     * Fails closed, before writing, when this session is not UTC (a cached
     * configuration would reread the old zone and convert twice) and when the
     * zone cannot express a stored value (`CONVERT_TZ` returns NULL for a
     * zone the server has no table for, and would erase it).
     *
     * @return array<string, int> rows changed, by table
     */
    public static function reexpress(Connection $db, string $writtenIn): array
    {
        $session = $db->selectOne('select @@session.time_zone as zone')->zone ?? null;
        if ($session !== self::ZONE) {
            throw new RuntimeException("The session's time_zone is ".var_export($session, true).', not '.self::ZONE.'. Clear the configuration cache before migrating.');
        }

        $converted = array_map(
            static fn (string $probe): mixed => $db->selectOne("select convert_tz(?, '+00:00', ?) as v", [$probe, $writtenIn])->v ?? null,
            self::PROBES,
        );
        if (in_array(null, $converted, true)) {
            throw new RuntimeException("The server cannot convert to the time zone '{$writtenIn}', so its stored values cannot be re-expressed.");
        }
        if ($converted === self::PROBES) {
            return [];
        }

        $columns = [];
        foreach ($db->select(
            "select table_name as t, column_name as c from information_schema.columns where table_schema = ? and data_type = 'timestamp' order by table_name, ordinal_position",
            [$db->getDatabaseName()],
        ) as $row) {
            $columns[(string) $row->t][] = (string) $row->c;
        }

        $quote = static fn (string $identifier): string => '`'.str_replace('`', '``', $identifier).'`';
        foreach ($columns as $table => $names) {
            $lost = implode(' or ', array_map(
                static fn (string $c): string => "({$quote($c)} is not null and convert_tz({$quote($c)}, '+00:00', ?) is null)",
                $names,
            ));
            if ($db->selectOne("select count(*) as n from {$quote($table)} where {$lost}", array_fill(0, count($names), $writtenIn))->n > 0) {
                throw new RuntimeException("{$table} holds a value '{$writtenIn}' cannot express; nothing was re-expressed.");
            }
        }

        // All or nothing: a second run over a half-converted database would
        // shift the converted half again.
        return $db->transaction(static function () use ($db, $columns, $quote, $writtenIn): array {
            $changed = [];
            foreach ($columns as $table => $names) {
                $sets = implode(', ', array_map(
                    static fn (string $c): string => "{$quote($c)} = convert_tz({$quote($c)}, '+00:00', ?)",
                    $names,
                ));
                $changed[$table] = $db->update("update {$quote($table)} set {$sets}", array_fill(0, count($names), $writtenIn));
            }

            return $changed;
        });
    }
}
