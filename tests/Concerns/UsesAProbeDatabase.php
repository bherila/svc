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

    protected function bootProbeDatabase(string $connection): void
    {
        $default = (array) config('database.connections.'.config('database.default'));
        $this->probeConnection = $connection;

        if (($default['driver'] ?? null) === 'sqlite') {
            $this->probeDatabase = tempnam(sys_get_temp_dir(), 'svc-probe-').'.sqlite';
            touch($this->probeDatabase);
            config(['database.connections.'.$connection => [
                'driver' => 'sqlite',
                'database' => $this->probeDatabase,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);

            return;
        }

        $this->probeDatabase = 'svc_probe_'.Str::lower(Str::random(12));
        DB::statement('create database `'.$this->probeDatabase.'`');
        config(['database.connections.'.$connection => ['database' => $this->probeDatabase] + $default]);
    }

    protected function dropProbeDatabase(): void
    {
        if ($this->probeDatabase === null || $this->probeConnection === null) {
            return;
        }

        DB::purge($this->probeConnection);

        if (str_ends_with($this->probeDatabase, '.sqlite')) {
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
