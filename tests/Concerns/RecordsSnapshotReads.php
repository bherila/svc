<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records ordinary (consistent) reads of a table that the same transaction has
 * already row-locked (docs/client-management/concurrency.md).
 *
 * Under REPEATABLE READ a locking read sees the latest committed rows, while
 * an ordinary read sees the transaction's snapshot, which can be older: fixed
 * by the transaction's first ordinary read, possibly in a caller before any
 * lock. A transaction that checks state with locking reads and then derives a
 * write from an ordinary read of the same table can write back state older
 * than what it checked - the class of the buy-down attach that renewed a
 * held-work basis, and totalled lines, from its caller's snapshot.
 *
 * MariaDB only: the SQL is where a locking read shows itself, and SQLite
 * renders none. Frames are application transactions above the test's own.
 */
trait RecordsSnapshotReads
{
    /** @var array<string, string> `opener > reader table #shape` => an example statement */
    private array $snapshotReads = [];

    private string $snapshotOpener = 'unknown';

    /** @var array<string, true> */
    private array $snapshotLockedTables = [];

    private ?int $snapshotBaseline = null;

    private bool $snapshotListening = false;

    protected function startRecordingSnapshotReads(): void
    {
        $this->snapshotReads = [];
        $this->snapshotLockedTables = [];
        $this->snapshotBaseline = DB::transactionLevel();
        if ($this->snapshotListening) {
            return;
        }
        $this->snapshotListening = true;
        Event::listen(function (TransactionBeginning $event): void {
            if ($this->snapshotBaseline !== null && $event->connection->transactionLevel() === $this->snapshotBaseline + 1) {
                $this->snapshotLockedTables = [];
                $this->snapshotOpener = self::applicationSite();
            }
        });
        Event::listen(function (TransactionCommitted|TransactionRolledBack $event): void {
            if ($this->snapshotBaseline !== null && $event->connection->transactionLevel() <= $this->snapshotBaseline) {
                $this->snapshotLockedTables = [];
            }
        });
        Event::listen(function (QueryExecuted $query): void {
            if ($this->snapshotBaseline === null || $query->connection->transactionLevel() <= $this->snapshotBaseline) {
                return;
            }
            $sql = strtolower($query->sql);
            if (! str_starts_with(ltrim($sql), 'select')) {
                return;
            }
            $tables = self::tablesRead($sql);
            if (str_contains($sql, ' for update') || str_contains($sql, ' lock in share mode')) {
                foreach ($tables as $table) {
                    $this->snapshotLockedTables[$table] = true;
                }

                return;
            }
            foreach ($tables as $table) {
                if (isset($this->snapshotLockedTables[$table])) {
                    $this->snapshotReads[self::snapshotReadKey($this->snapshotOpener, self::applicationSite(), $table, $query->sql)] ??= $query->sql;
                }
            }
        });
    }

    protected function stopRecordingSnapshotReads(): void
    {
        $this->snapshotBaseline = null;
        $this->snapshotLockedTables = [];
    }

    /**
     * Each transaction opener, reader, table and statement shape seen, as
     * `opener > reader table #shape`.
     *
     * @return array<string, string> => an example statement
     */
    protected function snapshotReads(): array
    {
        ksort($this->snapshotReads);

        return $this->snapshotReads;
    }

    /**
     * The key a read is pinned by. Opener, reader and table name the place; the
     * shape names the statement, so a second, different ordinary read added
     * inside a method already pinned is a new key and fails until it is
     * reviewed (#661). A method's line is not part of it: the key survives
     * edits elsewhere in the file.
     */
    public static function snapshotReadKey(string $opener, string $reader, string $table, string $sql): string
    {
        return $opener.' > '.$reader.' '.$table.' #'.substr(sha1(self::sqlShape($sql)), 0, 8);
    }

    /**
     * A statement with its values taken out: literals and bindings become
     * `?`, and a list of them one `(?)`, since how many rows an `in` names is
     * data, not shape.
     */
    public static function sqlShape(string $sql): string
    {
        $shape = strtolower($sql);
        $shape = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", '?', $shape);
        $shape = (string) preg_replace('/(?<![\\w`])\\d+(?:\\.\\d+)?(?![\\w`])/', '?', $shape);
        $shape = (string) preg_replace('/\\(\\s*\\?(?:\\s*,\\s*\\?)*\\s*\\)/', '(?)', $shape);

        return trim((string) preg_replace('/\\s+/', ' ', $shape));
    }

    /** @return list<string> */
    private static function tablesRead(string $sql): array
    {
        preg_match_all('/\b(?:from|join)\s+`([a-z0-9_]+)`/', $sql, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The innermost application frame, as `Class::method` without the `App\\`
     * prefix; a closure is named by the method it is written in, without its
     * line, so the key survives edits elsewhere in the file.
     */
    private static function applicationSite(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';
            if (! str_starts_with($class, 'App\\')) {
                continue;
            }
            $function = $frame['function'];
            if (preg_match('/\{closure:(?:[^{}]*::)?(\w+)\(\)(?::\d+)?\}/', $function, $closure) === 1) {
                $function = $closure[1];
            }

            return substr($class, 4).'::'.$function;
        }

        return 'unknown';
    }
}
