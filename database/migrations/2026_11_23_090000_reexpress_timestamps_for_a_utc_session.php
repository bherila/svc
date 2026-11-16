<?php

use App\Support\Database\UtcTimestampSession;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move every stored TIMESTAMP onto the UTC session `config/database.php` now
 * pins ({@see UtcTimestampSession}).
 *
 * Until now each session took the server's default zone - on web1 SYSTEM,
 * Europe/London - and MariaDB converted every TIMESTAMP through it. So the
 * values were written in `@@global.time_zone`, and are re-expressed from it:
 * the app reads exactly what it read before. Where the default is already
 * UTC (CI, a local server on UTC) nothing changes.
 *
 * Production deploys migrate in maintenance mode with cron paused, before the
 * release goes live, so nothing reads under the new zone before this runs.
 * SQLite has no session zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $db = DB::connection();
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $writtenIn = $db->selectOne('select @@global.time_zone as zone')->zone ?? null;
        if (! is_string($writtenIn) || $writtenIn === '') {
            throw new RuntimeException('The server reports no default time zone.');
        }

        UtcTimestampSession::reexpress($db, $writtenIn);
    }

    /**
     * Nothing to undo while the connection stays pinned to UTC: converting
     * back would make every value read an hour off in summer. Unpin the
     * connection first and re-express from UTC to the server default by hand.
     */
    public function down(): void {}
};
