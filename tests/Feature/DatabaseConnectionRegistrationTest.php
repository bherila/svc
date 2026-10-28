<?php

namespace Tests\Feature;

use App\Database\CharacterUuidMariaDbConnection;
use App\Database\Schema\Grammars\CharacterUuidMariaDbGrammar;
use App\Providers\AppServiceProvider;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Tests\TestCase;

final class DatabaseConnectionRegistrationTest extends TestCase
{
    public function test_application_registers_character_uuid_storage_only_for_mariadb(): void
    {
        // Resolvers are static across application lifetimes. Exercise the
        // provider from stock behavior inside this test, independently of boot.
        Connection::resolverFor('mariadb', static fn ($pdo, $database, $prefix, $config): MariaDbConnection => new MariaDbConnection($pdo, $database, $prefix, $config));
        $factory = app(ConnectionFactory::class);
        $configuration = [
            'driver' => 'mariadb', 'database' => 'synthetic', 'strict' => true,
        ];
        $this->assertNotInstanceOf(CharacterUuidMariaDbConnection::class, $factory->make($configuration, 'synthetic_stock'));
        (new AppServiceProvider($this->app))->register();
        // Resolving the lazy connection and its grammars performs no network I/O.
        $mariadb = $factory->make($configuration, 'synthetic_mariadb');
        $this->assertInstanceOf(CharacterUuidMariaDbConnection::class, $mariadb);
        $this->assertInstanceOf(MariaDbConnection::class, $mariadb);
        $mariadb->getSchemaBuilder();
        $this->assertInstanceOf(CharacterUuidMariaDbGrammar::class, $mariadb->getSchemaGrammar());
        $this->assertInstanceOf(MariaDbGrammar::class, $mariadb->getQueryGrammar());
        $this->assertTrue($mariadb->getConfig('strict'));

        $mysql = $factory->make(['driver' => 'mysql', 'database' => 'synthetic'], 'synthetic_mysql');
        $this->assertInstanceOf(MySqlConnection::class, $mysql);
        $this->assertNotInstanceOf(CharacterUuidMariaDbConnection::class, $mysql);
    }
}
