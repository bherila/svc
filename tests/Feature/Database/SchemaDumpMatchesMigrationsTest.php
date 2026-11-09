<?php

namespace Tests\Feature\Database;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A database built from the stored dump must be the one the migrations build.
 *
 * Tests and fresh installs load the dump instead of replaying history, so
 * anything it loses is lost everywhere at once. The replay connection has a name
 * no dump is stored under, so Laravel runs every migration for it.
 */
final class SchemaDumpMatchesMigrationsTest extends TestCase
{
    private const string REPLAYED = 'schema_dump_replayed';

    private const string LOADED = 'schema_dump_loaded';

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::REPLAYED, self::LOADED] as $connection) {
            $file = (string) tempnam(sys_get_temp_dir(), $connection);
            $this->files[] = $file;
            config(["database.connections.{$connection}" => [
                'driver' => 'sqlite',
                'database' => $file,
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
        }

        $this->assertSame(0, Artisan::call('migrate', ['--database' => self::REPLAYED, '--force' => true]));
        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => self::LOADED,
            '--force' => true,
            '--schema-path' => database_path('schema/sqlite-schema.sql'),
        ]));
    }

    protected function tearDown(): void
    {
        foreach ([self::REPLAYED, self::LOADED] as $connection) {
            DB::purge($connection);
        }
        File::delete($this->files);

        parent::tearDown();
    }

    #[Test]
    public function the_dump_has_every_migration_recorded_as_run(): void
    {
        $this->assertSame(
            $this->migrationNames(self::REPLAYED),
            $this->migrationNames(self::LOADED),
        );
    }

    #[Test]
    public function the_dump_builds_the_same_structure_as_the_migrations(): void
    {
        $this->assertSame(
            $this->structure(self::REPLAYED),
            $this->structure(self::LOADED),
            'The dump has drifted from the migrations. Regenerate database/schema/sqlite-schema.sql from a full replay with `php artisan schema:dump`.',
        );
    }

    #[Test]
    public function the_dump_keeps_every_row_a_migration_writes(): void
    {
        $replayed = $this->rows(self::REPLAYED);

        $this->assertNotSame([], $replayed, 'Expected at least the credential revocation rows.');
        $this->assertSame(
            $replayed,
            $this->rows(self::LOADED),
            'A migration writes rows the dump does not carry. Add it to App\\Listeners\\RestoreRowsMigrationsWrote.',
        );
    }

    /** @return array<mixed> */
    private function migrationNames(string $connection): array
    {
        return DB::connection($connection)->table('migrations')->orderBy('migration')->pluck('migration')->all();
    }

    /** @return array<string, array{columns: mixed, indexes: mixed, foreign_keys: mixed}> */
    private function structure(string $connection): array
    {
        $schema = Schema::connection($connection);
        $structure = [];

        foreach ($this->tables($connection) as $table) {
            $structure[$table] = [
                'columns' => $this->sortedByName($schema->getColumns($table)),
                'indexes' => $this->sortedByName($schema->getIndexes($table)),
                'foreign_keys' => $this->sortedByName($schema->getForeignKeys($table)),
            ];
        }

        return $structure;
    }

    /** @return array<string, array<int, array<mixed>>> */
    private function rows(string $connection): array
    {
        $found = [];

        foreach ($this->tables($connection) as $table) {
            if ($table === 'migrations') {
                continue;
            }

            $rows = DB::connection($connection)->table($table)->get()
                ->map(fn (object $row): array => (array) $row)
                ->sortBy(fn (array $row): string => serialize($row))
                ->values()
                ->all();

            if ($rows !== []) {
                $found[$table] = $rows;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function tables(string $connection): array
    {
        $tables = array_column(Schema::connection($connection)->getTables(), 'name');
        sort($tables);

        return $tables;
    }

    /**
     * Unnamed indexes and keys sort by their full content, so that the order SQLite
     * happens to list them in is not a difference.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortedByName(array $items): array
    {
        usort($items, fn (array $a, array $b): int => serialize($a) <=> serialize($b));

        return $items;
    }
}
