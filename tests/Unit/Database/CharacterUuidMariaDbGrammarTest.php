<?php

namespace Tests\Unit\Database;

use App\Database\CharacterUuidMariaDbConnection;
use Illuminate\Database\Query\Grammars\MariaDbGrammar;
use Illuminate\Database\Schema\Blueprint;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterUuidMariaDbGrammarTest extends TestCase
{
    #[DataProvider('serverVersions')]
    public function test_uuid_and_foreign_uuid_keep_character_storage_across_server_upgrades(string $version): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->with(PDO::ATTR_SERVER_VERSION)->willReturn($version);
        $connection = new CharacterUuidMariaDbConnection($pdo, 'synthetic', '', [
            'driver' => 'mariadb',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ]);
        $connection->useDefaultSchemaGrammar();
        $blueprint = new Blueprint($connection, 'synthetic_children', static function (Blueprint $table): void {
            $table->create();
            $table->uuid('id')->primary();
            $table->foreignUuid('parent_id')->constrained('synthetic_parents');
            $table->json('payload');
        });
        $sql = implode("\n", $blueprint->toSql());

        $this->assertStringContainsString('`id` char(36) not null', $sql);
        $this->assertStringContainsString('`parent_id` char(36) not null', $sql);
        $this->assertStringNotContainsString('`id` uuid', $sql);
        $this->assertStringNotContainsString('`parent_id` uuid', $sql);
        $this->assertStringContainsString('foreign key (`parent_id`) references `synthetic_parents` (`id`)', $sql);
        $this->assertStringContainsString('`payload` json not null', $sql);
        $this->assertTrue($connection->isMaria());
        $this->assertInstanceOf(MariaDbGrammar::class, $connection->getQueryGrammar());
    }

    /** @return iterable<string, array{string}> */
    public static function serverVersions(): iterable
    {
        yield 'legacy server' => ['10.6.28-MariaDB'];
        yield 'native UUID boundary' => ['10.7.0-MariaDB'];
        yield 'upgraded server' => ['11.4.13-MariaDB'];
        yield 'compatibility prefix' => ['5.5.5-11.4.13-MariaDB-0ubuntu0.24.04.1'];
    }
}
