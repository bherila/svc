<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A throwaway database of the test's own, on whichever engine the suite is using.
 *
 * Some things can only be asserted by running real DDL: whether `down()` puts the
 * schema back, or how a pre-migration gate behaves on a schema that has not been
 * migrated yet. Doing that on the suite's own database is not an option - DDL is
 * an implicit commit on MariaDB, so it would escape `RefreshDatabase` and every
 * later test would inherit the damage. That is the mistake
 * `ProjectAccessLegacyOrphanTest` had to be fixed for, and it cost eight
 * unrelated failures.
 *
 * So these tests get their own: a temp file on SQLite, a scratch schema on
 * MariaDB, dropped again in `tearDown()`.
 */
trait UsesAProbeDatabase
{
    private ?string $probeDatabase = null;

    private ?string $probeConnection = null;

    /** Whether the probe is a file to unlink or a schema to drop. */
    private bool $probeIsFile = false;

    protected function bootProbeDatabase(string $connection): void
    {
        $default = (array) config('database.connections.'.config('database.default'));
        $this->probeConnection = $connection;

        if (($default['driver'] ?? null) === 'sqlite') {
            // The file tempnam() creates is the one that gets used, rather than a
            // suffixed sibling: appending an extension would leave the original
            // zero-byte file behind on every run, since only the suffixed path is
            // ever unlinked. SQLite does not care what a database file is called.
            $file = tempnam(sys_get_temp_dir(), 'svc-probe-');

            if ($file === false) {
                $this->fail('Could not create a temporary database for the probe connection.');
            }

            $this->probeDatabase = $file;
            $this->probeIsFile = true;

            config(['database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => $this->probeDatabase,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);

            return;
        }

        $this->probeDatabase = 'svc_probe_'.Str::lower(Str::random(12));
        $this->probeIsFile = false;
        DB::statement('create database `'.$this->probeDatabase.'`');
        config(['database.connections.'.$connection => ['database' => $this->probeDatabase] + $default]);
    }

    protected function dropProbeDatabase(): void
    {
        if ($this->probeDatabase === null || $this->probeConnection === null) {
            return;
        }

        DB::purge($this->probeConnection);

        // Recorded rather than inferred from the path: tempnam() can return a
        // resolved path that does not start with sys_get_temp_dir() - on macOS
        // /private/var/... against /var/... - and guessing wrong here would send a
        // `drop database` to SQLite.
        if ($this->probeIsFile) {
            @unlink($this->probeDatabase);
        } else {
            DB::statement('drop database if exists `'.$this->probeDatabase.'`');
        }

        $this->probeDatabase = null;
        $this->probeConnection = null;
    }

    protected function tearDown(): void
    {
        $this->dropProbeDatabase();

        parent::tearDown();
    }
}
