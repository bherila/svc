<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\Support\OwnTables;
use Tests\TestCase;

/**
 * A test sees its own database and nothing another test is building (#475).
 *
 * Under `--parallel` on MariaDB, every worker's schema and every probe schema
 * share one server. A whole-database fingerprint that listed every schema read
 * another worker's probe from inside its own snapshot, and failed with 1412 or
 * 1146 whenever that worker's DDL landed mid-test.
 */
final class ProbeDatabaseIsolationTest extends TestCase
{
    use RefreshDatabase, UsesAProbeDatabase;

    private const FOREIGN_TABLE = 'svc_foreign_probe_table';

    public function test_own_tables_lists_this_database_and_no_other_schema_on_the_server(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Another schema on the same connection: SQLite lists `temp`
            // alongside `main`, the way MariaDB lists every database.
            DB::statement('create temporary table '.self::FOREIGN_TABLE.' (id integer)');
        } else {
            $this->bootProbeDatabase('own_tables_probe');
            Schema::connection('own_tables_probe')->create(self::FOREIGN_TABLE, static function (Blueprint $table): void {
                $table->id();
            });
        }

        // The fixture is real: an unscoped listing would have read it.
        $this->assertContains(self::FOREIGN_TABLE, Schema::getTableListing(schemaQualified: false));

        $own = OwnTables::names();

        $this->assertNotContains(self::FOREIGN_TABLE, $own);
        $this->assertContains('workspaces', $own);
        $this->assertSame([], array_values(array_filter($own, static fn (string $name): bool => str_contains($name, '.'))));
        $this->assertSame([], array_values(array_filter($own, static fn (string $name): bool => str_starts_with($name, 'sqlite_'))));
    }
}
