<?php

namespace Tests\Feature\Square;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SquareMirrorTimestampDefaultsTest extends TestCase
{
    public function test_every_mirror_migration_column_declares_safe_timestamp_defaults_on_mariadb(): void
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mariadb') {
            $this->markTestSkipped('MariaDB guards its legacy implicit timestamp defaults.');
        }
        $this->assertStringContainsString('MariaDB', $connection->selectOne('select version() as version')->version);

        $original = Schema::getFacadeRoot();
        $schema = clone $connection->getSchemaBuilder();
        $unsafe = [];
        $tables = [];
        $schema->blueprintResolver(function (Connection $database, string $table, ?Closure $callback = null) use (&$unsafe, &$tables): Blueprint {
            return new class($database, $table, $callback, $unsafe, $tables) extends Blueprint
            {
                public function __construct(Connection $connection, string $table, ?Closure $callback, private array &$unsafe, private array &$tables)
                {
                    parent::__construct($connection, $table, $callback);
                }

                public function build(): void
                {
                    $this->tables[] = $this->getTable();
                    foreach ($this->getColumns() as $column) {
                        if (in_array($column->type, ['timestamp', 'timestampTz'], true)
                            && ! $column->nullable && $column->default === null && ! $column->useCurrent) {
                            $this->unsafe[] = $this->getTable().'.'.$column->name;
                        }
                    }
                }
            };
        });
        try {
            Schema::swap($schema);
            $migration = require database_path('migrations/2026_10_12_210000_create_square_mirror.php');
            $migration->up();
        } finally {
            Schema::swap($original);
        }

        $this->assertSame(['square_sync_runs', 'square_location_snapshots', 'square_customer_snapshots', 'square_invoice_snapshots'], $tables);
        $this->assertSame([], $unsafe, 'Required timestamps need an explicit default: the first silently gains ON UPDATE and later columns can fail migration.');
    }
}
