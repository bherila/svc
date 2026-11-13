<?php

namespace App\Support\Concurrency;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Records which locks a transaction took, in the order it took them.
 *
 * Off unless a test turns it on, and doing nothing is the whole of its
 * production behaviour: {@see Locks::acquire()} calls `record()`, which returns
 * immediately. Recording could have been made conditional on the environment
 * instead, but "off unless something explicitly asked" is a claim this file can
 * make on its own, and an environment check is a claim about configuration.
 *
 * ## What a sequence is
 *
 * One outermost application transaction. Locks taken inside a nested
 * transaction belong to the outer one - a savepoint does not release them - so
 * every acquisition lands in the frame the outermost `DB::transaction()` opened,
 * and the frame is archived when that transaction commits or rolls back.
 *
 * "Outermost" cannot mean transaction level 1, because `RefreshDatabase` wraps
 * every test in a transaction of its own: under it, application transactions
 * begin at level 2 and the whole test would otherwise read as one sequence.
 * So `start()` records the level it was called at, and a frame opens at that
 * level plus one. A test that starts recording outside its own transaction
 * therefore sees exactly the transactions the code under test opened.
 *
 * A lock taken outside any transaction is its own one-element sequence. It is
 * also close to meaningless - the lock is released at the end of the implicit
 * transaction, which is the statement - so recording it as a sequence is how
 * that shows up somewhere rather than nowhere.
 *
 * ## Per connection
 *
 * A transaction belongs to a connection, and so does every lock it takes. The
 * floor, the open frame and the maintenance exemption are therefore kept per
 * connection name: a second connection beginning or ending a transaction never
 * archives, splits or exempts the first's sequence, which would let an
 * inversion spread across the two pieces escape detection. A lock names its
 * connection ({@see Locks}); one that names none is the default connection's.
 */
final class LockOrderRecorder
{
    private static bool $recording = false;

    /**
     * The transaction depth each connection was at when recording started.
     * A connection first used afterwards started at zero.
     *
     * @var array<string, int>
     */
    private static array $baselines = [];

    /**
     * Each connection's transaction currently being recorded, oldest
     * acquisition first, in the order the transactions opened.
     *
     * @var array<string, list<LockAcquisition>>
     */
    private static array $frames = [];

    /**
     * Every completed transaction's sequence, in the order they finished.
     *
     * @var list<list<LockAcquisition>>
     */
    private static array $sequences = [];

    /** @var array<string, string> */
    private static array $exclusiveCommand = [];

    /** @var list<string|null> */
    private static array $exclusiveCommands = [];

    /** Begin recording, treating each open connection's current transaction depth as its floor. */
    public static function start(): void
    {
        self::listen();
        self::$baselines = [];
        foreach (DB::getConnections() as $name => $connection) {
            self::$baselines[$name] = $connection->transactionLevel();
        }
        self::$frames = [];
        self::$sequences = [];
        self::$exclusiveCommand = [];
        self::$exclusiveCommands = [];
        self::$recording = true;
    }

    /** Stop recording and forget everything, so one test cannot see another's. */
    public static function stop(): void
    {
        self::$recording = false;
        self::$baselines = [];
        self::$frames = [];
        self::$sequences = [];
        self::$exclusiveCommand = [];
        self::$exclusiveCommands = [];
    }

    /**
     * Note that a lock on this resource is about to be taken on a connection.
     *
     * The early return is the production path in its entirety.
     */
    public static function record(LockResource $resource, LockMode $mode = LockMode::Exclusive, string $file = '', int $line = 0, ?string $connection = null): void
    {
        if (! self::$recording) {
            return;
        }

        $acquisition = new LockAcquisition($resource, $mode, $file, $line);
        $connection ??= DB::getDefaultConnection();
        if (! isset(self::$frames[$connection])) {
            self::$sequences[] = [$acquisition];
            self::$exclusiveCommands[] = null;

            return;
        }

        self::$frames[$connection][] = $acquisition;
    }

    public static function maintenanceExclusive(string $command, ?string $connection = null): void
    {
        // A frame exists only during active recording; outside-frame calls
        // cannot assign an exemption to any transaction.
        if (! self::$recording) {
            return;
        }
        $connection ??= DB::getDefaultConnection();
        if (isset(self::$frames[$connection])) {
            self::$exclusiveCommand[$connection] = $command;
        }
    }

    /**
     * Every transaction recorded since `start()`, including those still open.
     *
     * Open frames are included because a test that asserts inside the
     * transaction it is exercising - which is the only way to observe a
     * sequence that ends in a refusal - would otherwise see nothing at all.
     *
     * @return list<list<LockResource>>
     */
    public static function sequences(): array
    {
        return array_map(static fn (array $sequence): array => array_map(
            static fn (LockAcquisition $lock): LockResource => $lock->resource,
            $sequence,
        ), self::acquisitions());
    }

    /** @return list<list<LockAcquisition>> */
    public static function acquisitions(): array
    {
        $sequences = self::$sequences;
        foreach (self::$frames as $frame) {
            if ($frame !== []) {
                $sequences[] = $frame;
            }
        }

        return $sequences;
    }

    /** @return list<string|null> */
    public static function maintenanceExemptions(): array
    {
        $commands = self::$exclusiveCommands;
        foreach (self::$frames as $connection => $frame) {
            if ($frame !== []) {
                $commands[] = self::$exclusiveCommand[$connection] ?? null;
            }
        }

        return $commands;
    }

    /** Archive a connection's open frame, if it took any lock, and close it. */
    private static function archive(string $connection): void
    {
        $frame = self::$frames[$connection] ?? [];
        if ($frame !== []) {
            self::$sequences[] = $frame;
            self::$exclusiveCommands[] = self::$exclusiveCommand[$connection] ?? null;
        }
        unset(self::$frames[$connection], self::$exclusiveCommand[$connection]);
    }

    /**
     * Register the transaction listeners for this recording run.
     *
     * Registered here rather than in a service provider so that an application
     * that never records never gains a listener. They are cheap, but a listener
     * that exists only to check a flag is exactly the kind of thing that gets
     * read later as "the recorder runs in production".
     *
     * Not guarded against registering twice, deliberately. A second `start()`
     * adds a second pair, and both pairs then do the same work in order: the
     * opening pair opens an empty frame twice, and the closing pair archives
     * once, because the first of them closes the frame and the second finds
     * nothing to archive. Laravel builds a fresh dispatcher for every test, so
     * they cannot accumulate beyond one. An earlier revision carried a
     * `$listening` flag and a `forgetListeners()` to reset it; both were
     * unobservable by construction - no test could distinguish the guarded from
     * the unguarded behaviour - and unobservable state is the kind that goes on
     * being maintained long after it stops being true.
     */
    private static function listen(): void
    {
        Event::listen(function (TransactionBeginning $event): void {
            $connection = $event->connectionName;
            // A nested transaction is a savepoint, and a savepoint releases no
            // locks, so its locks belong to the outermost frame. Only a
            // transaction opened at its connection's floor starts a sequence.
            if (self::$recording && $event->connection->transactionLevel() === (self::$baselines[$connection] ?? 0) + 1) {
                // Laravel runs after-commit callbacks before it announces the
                // commit, so a callback that opens a transaction begins here
                // while the frame of the one that just committed is still
                // open. That transaction has committed and released its
                // locks: archive its frame rather than lose it. Only this
                // connection's - another connection's open transaction holds
                // its locks still.
                self::archive($connection);
                self::$frames[$connection] = [];
            }
        });

        Event::listen(function (TransactionCommitted|TransactionRolledBack $event): void {
            // And symmetrically: a sequence is archived only when the
            // transaction that opened it ends, not when a savepoint inside it
            // is released.
            $connection = $event->connectionName;
            if (self::$recording && $event->connection->transactionLevel() === (self::$baselines[$connection] ?? 0)) {
                self::archive($connection);
            }
        });
    }
}
