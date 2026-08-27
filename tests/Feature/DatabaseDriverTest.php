<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The suite is running against the database it thinks it is.
 *
 * The default suite runs on in-memory SQLite while production is MariaDB, and
 * SQLite accepts values MariaDB rejects. That is not a hypothetical: the
 * generator wrote `1:30` into a decimal column and 404 tests stayed green,
 * because SQLite stores whatever it is handed. Only a run against the real
 * engine, in strict mode, turns that into a failure.
 *
 * So a second CI job runs the whole suite on MariaDB. The risk with a second
 * job is that it quietly falls back to SQLite - a mistyped env var, a service
 * container that never came up - and reports a green run that proved nothing.
 * That is the same failure shape as the bug it exists to catch.
 *
 * These tests make the job's own premise checkable. Set DB_EXPECT_DRIVER and
 * the run refuses to pass unless it really is on that driver, with strict mode
 * really on.
 */
final class DatabaseDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_connection_is_the_driver_the_run_asked_for(): void
    {
        $expected = getenv('DB_EXPECT_DRIVER');

        if ($expected === false || $expected === '') {
            $this->markTestSkipped('No DB_EXPECT_DRIVER set; this run makes no claim about its driver.');
        }

        $this->assertSame(
            $expected,
            DB::connection()->getDriverName(),
            'This run was meant to exercise a specific database driver and did not. '.
            'A green result here would say nothing about the engine it claimed to test.',
        );
    }

    /**
     * Strict mode is the whole point of running on MariaDB.
     *
     * Without STRICT_TRANS_TABLES, MariaDB truncates and coerces rather than
     * refusing, so a bad write lands as silently as it does on SQLite. The
     * server's own sql_mode on the production host does not include it -
     * Laravel's `strict` connection flag sets it per session, so this asserts
     * the application's connection rather than the server's default.
     */
    public function test_a_mysql_connection_runs_in_strict_mode(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Strict mode is a MySQL/MariaDB concept.');
        }

        $mode = (string) DB::selectOne('select @@session.sql_mode as mode')->mode;

        $this->assertStringContainsString(
            'STRICT_TRANS_TABLES',
            $mode,
            'Without strict mode a bad write is truncated instead of refused, which is '.
            'exactly the blindness this job exists to remove.',
        );
    }

    /**
     * The defect that started this: a `h:mm` string into a decimal column.
     *
     * On SQLite this insert succeeds and stores the text verbatim. On MariaDB
     * in strict mode it raises. Pinning it here means the difference between
     * the two engines is a stated fact rather than something discovered in
     * production.
     */
    public function test_a_decimal_column_refuses_a_time_string_on_mysql(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('SQLite accepts this write; that is the problem being documented.');
        }

        DB::statement('create temporary table driver_probe (quantity decimal(16,4) not null)');

        try {
            $this->expectException(QueryException::class);
            DB::table('driver_probe')->insert(['quantity' => '1:30']);
        } finally {
            DB::statement('drop temporary table if exists driver_probe');
        }
    }
}
