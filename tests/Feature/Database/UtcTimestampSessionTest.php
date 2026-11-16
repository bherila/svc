<?php

namespace Tests\Feature\Database;

use App\Support\Database\UtcTimestampSession;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * The MariaDB session is UTC, and what was stored under another zone reads the
 * same after the move (UtcTimestampSession).
 *
 * A writer session in '+01:00' stands in for web1's SYSTEM zone in British
 * summer time: a fixed offset needs no time zone tables on the server.
 */
final class UtcTimestampSessionTest extends TestCase
{
    use UsesAProbeDatabase;

    private const WRITER = 'utc_session_writer';

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MariaDB converts TIMESTAMP values through a session zone.');
        }
    }

    public function test_the_connection_reads_and_writes_in_utc(): void
    {
        $session = DB::selectOne('select @@session.time_zone as zone, now() as now, utc_timestamp() as utc');

        $this->assertSame(UtcTimestampSession::ZONE, $session->zone);
        $this->assertSame($session->utc, $session->now);
    }

    public function test_values_written_in_another_zone_read_the_same_after_the_move(): void
    {
        $utc = $this->probe();
        $this->writer()->insert('insert into stamps (id, a, b, d) values (?, ?, ?, ?), (?, ?, ?, ?)', [
            1, '2026-07-01 12:00:00', '2026-01-15 09:30:00', '2026-07-01 12:00:00',
            2, null, '2026-10-25 01:30:00', null,
        ]);
        $this->writer()->insert('insert into plain (id, d) values (1, ?)', ['2026-07-01 12:00:00']);
        $this->assertSame('2026-07-01 11:00:00', $this->stamps($utc)[1]['a'], 'The UTC session should read the hour the old zone took off.');

        $changed = UtcTimestampSession::reexpress($utc, '+01:00');

        $this->assertSame(['refreshed' => 0, 'stamps' => 2], $changed);
        $this->assertSame([
            1 => ['a' => '2026-07-01 12:00:00', 'b' => '2026-01-15 09:30:00', 'd' => '2026-07-01 12:00:00'],
            2 => ['a' => null, 'b' => '2026-10-25 01:30:00', 'd' => null],
        ], $this->stamps($utc));
        $this->assertSame('2026-07-01 12:00:00', $utc->selectOne('select d from plain where id = 1')->d, 'A table without TIMESTAMP columns is not touched.');
    }

    public function test_a_column_that_updates_itself_keeps_its_value(): void
    {
        $utc = $this->probe();
        $this->writer()->insert('insert into refreshed (id, a, touched) values (1, ?, ?)', ['2026-07-01 12:00:00', '2026-06-01 08:00:00']);

        UtcTimestampSession::reexpress($utc, '+01:00');

        $row = $utc->selectOne('select a, touched from refreshed where id = 1');
        $this->assertSame(['2026-07-01 12:00:00', '2026-06-01 08:00:00'], [$row->a, $row->touched]);
    }

    public function test_a_server_already_on_utc_changes_nothing(): void
    {
        $utc = $this->probe();
        $utc->insert('insert into stamps (id, a) values (1, ?)', ['2026-07-01 12:00:00']);

        $this->assertSame([], UtcTimestampSession::reexpress($utc, '+00:00'));
        $this->assertSame('2026-07-01 12:00:00', $this->stamps($utc)[1]['a']);
    }

    public function test_a_zone_the_server_cannot_convert_to_changes_nothing(): void
    {
        $utc = $this->probe();
        $this->writer()->insert('insert into stamps (id, a) values (1, ?)', ['2026-07-01 12:00:00']);

        try {
            UtcTimestampSession::reexpress($utc, 'Nowhere/Atlantis');
            $this->fail('An unknown zone should be refused.');
        } catch (RuntimeException $refused) {
            $this->assertStringContainsString('Nowhere/Atlantis', $refused->getMessage());
        }
        $this->assertSame('2026-07-01 11:00:00', $this->stamps($utc)[1]['a']);
    }

    public function test_a_session_outside_utc_is_refused_before_it_converts_twice(): void
    {
        $this->probe();
        $this->writer()->insert('insert into stamps (id, a) values (1, ?)', ['2026-07-01 12:00:00']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Clear the configuration cache');

        UtcTimestampSession::reexpress($this->writer(), '+01:00');
    }

    private function probe(): Connection
    {
        $this->bootProbeDatabase('utc_session_probe');
        config(['database.connections.'.self::WRITER => ['timezone' => '+01:00'] + (array) config('database.connections.utc_session_probe')]);
        DB::purge(self::WRITER);
        $utc = DB::connection('utc_session_probe');
        $utc->statement('create table stamps (id int primary key, a timestamp null, b timestamp null, d datetime null)');
        $utc->statement('create table refreshed (id int primary key, a timestamp null, touched timestamp not null default current_timestamp on update current_timestamp)');
        $utc->statement('create table plain (id int primary key, d datetime null)');

        return $utc;
    }

    private function writer(): Connection
    {
        return DB::connection(self::WRITER);
    }

    /** @return array<int, array{a: ?string, b: ?string, d: ?string}> */
    private function stamps(Connection $db): array
    {
        $rows = [];
        foreach ($db->select('select id, a, b, d from stamps order by id') as $row) {
            $rows[(int) $row->id] = ['a' => $row->a, 'b' => $row->b, 'd' => $row->d];
        }

        return $rows;
    }

    protected function tearDown(): void
    {
        DB::purge(self::WRITER);
        $this->dropProbeDatabase();

        parent::tearDown();
    }
}
