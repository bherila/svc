<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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

    public function test_creating_and_dropping_a_probe_never_commits_the_test_transaction(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('A SQLite probe is a file; creating it runs no DDL on the test connection.');
        }

        $email = 'probe-commit-'.Str::lower(Str::random(10)).'@example.test';
        User::factory()->create(['email' => $email]);

        config(['database.connections.probe_observer' => (array) config('database.connections.'.config('database.default'))]);
        $committed = static fn (): bool => DB::connection('probe_observer')->table('users')->where('email', $email)->exists();

        try {
            $this->assertFalse($committed(), 'The row is still inside the test transaction before any probe exists.');

            $this->bootProbeDatabase('commit_probe');
            $this->assertFalse($committed(), 'Creating the probe schema committed the test transaction.');

            $this->dropProbeDatabase();
            $this->assertFalse($committed(), 'Dropping the probe schema committed the test transaction.');

            $this->assertTrue(User::query()->where('email', $email)->exists(), 'The test still sees its own uncommitted row.');
        } finally {
            DB::purge('probe_observer');
        }
    }
}
