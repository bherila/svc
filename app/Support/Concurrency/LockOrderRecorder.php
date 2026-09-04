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
 */
final class LockOrderRecorder
{
    private static bool $recording = false;

    private static bool $listening = false;

    private static int $baseline = 0;

    /**
     * The transaction currently being recorded, oldest acquisition first.
     *
     * @var list<LockResource>|null
     */
    private static ?array $frame = null;

    /**
     * Every completed transaction's sequence, in the order they finished.
     *
     * @var list<list<LockResource>>
     */
    private static array $sequences = [];

    /** Begin recording, treating the current transaction depth as the floor. */
    public static function start(): void
    {
        self::listen();
        self::$baseline = DB::transactionLevel();
        self::$frame = null;
        self::$sequences = [];
        self::$recording = true;
    }

    /** Stop recording and forget everything, so one test cannot see another's. */
    public static function stop(): void
    {
        self::$recording = false;
        self::$frame = null;
        self::$sequences = [];
    }

    /**
     * Note that a lock on this resource is about to be taken.
     *
     * The early return is the production path in its entirety.
     */
    public static function record(LockResource $resource): void
    {
        if (! self::$recording) {
            return;
        }

        if (self::$frame === null) {
            self::$sequences[] = [$resource];

            return;
        }

        self::$frame[] = $resource;
    }

    /**
     * Every transaction recorded since `start()`, including one still open.
     *
     * The open frame is included because a test that asserts inside the
     * transaction it is exercising - which is the only way to observe a
     * sequence that ends in a refusal - would otherwise see nothing at all.
     *
     * @return list<list<LockResource>>
     */
    public static function sequences(): array
    {
        $sequences = self::$sequences;

        if (self::$frame !== null && self::$frame !== []) {
            $sequences[] = self::$frame;
        }

        return $sequences;
    }

    /**
     * Register the transaction listeners once per application instance.
     *
     * Registered here rather than in a service provider so that an application
     * that never records never gains a listener. They are cheap, but a listener
     * that exists only to check a flag is exactly the kind of thing that gets
     * read later as "the recorder runs in production".
     */
    private static function listen(): void
    {
        if (self::$listening) {
            return;
        }

        self::$listening = true;

        Event::listen(function (TransactionBeginning $event): void {
            if (self::$recording && $event->connection->transactionLevel() === self::$baseline + 1) {
                self::$frame = [];
            }
        });

        $close = function (TransactionCommitted|TransactionRolledBack $event): void {
            if (! self::$recording || $event->connection->transactionLevel() !== self::$baseline) {
                return;
            }

            if (self::$frame !== null && self::$frame !== []) {
                self::$sequences[] = self::$frame;
            }

            self::$frame = null;
        };

        Event::listen($close);
    }

    /**
     * Forget the registered listeners.
     *
     * Laravel builds a fresh application - and so a fresh event dispatcher -
     * for every test, so the listeners registered above do not survive into the
     * next one while this flag would. Called from the recording test's teardown.
     */
    public static function forgetListeners(): void
    {
        self::$listening = false;
        self::$baseline = 0;
    }
}
