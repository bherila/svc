<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database session is UTC whatever the server's default, so a TIMESTAMP
 * stores the instant the app wrote (docs/client-management/timestamps.md).
 */
final class DatabaseSessionTimeZoneTest extends TestCase
{
    public function test_the_session_reads_and_writes_in_utc(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Only MariaDB and MySQL convert TIMESTAMP values through a session zone.');
        }

        $session = DB::selectOne('select @@session.time_zone as zone, now() as now, utc_timestamp() as utc');

        $this->assertSame('+00:00', $session->zone);
        $this->assertSame($session->utc, $session->now);
    }

    public function test_both_server_connections_pin_it(): void
    {
        $this->assertSame('+00:00', config('database.connections.mariadb.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));
    }
}
