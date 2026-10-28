<?php

namespace Tests\Feature;

use App\Console\Commands\Database\DatabaseStatusCommand;
use App\Database\CharacterUuidMariaDbConnection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DatabaseStatusGrammarTest extends TestCase
{
    #[DataProvider('grammarCases')]
    public function test_status_requires_the_installed_character_grammar_on_new_servers(bool $characterGrammar, string $version, bool $compatible): void
    {
        $pdo = $this->createMock(PDO::class);
        $connection = $characterGrammar
            ? new CharacterUuidMariaDbConnection($pdo, 'synthetic', '', ['driver' => 'mariadb'])
            : new MariaDbConnection($pdo, 'synthetic', '', ['driver' => 'mariadb']);
        DB::shouldReceive('connection')->andReturn($connection);
        DB::shouldReceive('selectOne')->with('select version() as version')->once()->andReturn((object) ['version' => $version]);

        $exit = Artisan::call('svc:database:status', ['--format' => 'json']);
        $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($compatible ? DatabaseStatusCommand::SUCCESS : DatabaseStatusCommand::FAILURE, $exit);
        $this->assertSame('mariadb', $status['driver']);
        $this->assertSame($version, $status['server_version']);
        $this->assertSame($compatible, $status['uuid_grammar_compatible']);
    }

    /** @return iterable<string, array{bool, string, bool}> */
    public static function grammarCases(): iterable
    {
        yield 'new server with explicit grammar' => [true, '11.4.13-MariaDB', true];
        yield 'new server with stock grammar remains refused' => [false, '11.4.13-MariaDB', false];
        yield 'legacy server with stock character storage' => [false, '10.6.28-MariaDB', true];
        yield 'unrecognized version fails even with explicit grammar' => [true, 'MariaDB-unknown', false];
    }
}
