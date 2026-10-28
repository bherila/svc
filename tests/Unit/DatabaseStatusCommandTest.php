<?php

namespace Tests\Unit;

use App\Console\Commands\Database\DatabaseStatusCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseStatusCommandTest extends TestCase
{
    #[DataProvider('compatibilityCases')]
    public function test_uuid_grammar_boundary_is_explicit(string $driver, string $version, bool $compatible, bool $characterUuidGrammar = false): void
    {
        $this->assertSame(
            $compatible,
            DatabaseStatusCommand::isUuidGrammarCompatible($driver, $version, $characterUuidGrammar),
        );
    }

    /** @return iterable<string, array{string, string, bool, bool?}> */
    public static function compatibilityCases(): iterable
    {
        yield 'case-insensitive server suffix' => ['mariadb', '10.6.28-mariadb', true];
        yield 'legacy production' => ['mariadb', '10.6.28-MariaDB', true];
        yield 'compatibility prefix' => ['mariadb', '5.5.5-10.6.28-MariaDB', true];
        yield 'native uuid boundary' => ['mariadb', '10.7.0-MariaDB', false];
        yield 'distro suffix cannot hide a future server' => ['mariadb', '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1', false];
        yield 'future MariaDB' => ['mariadb', '11.8.3-MariaDB', false];
        yield 'wrong Laravel driver' => ['mysql', '10.6.28-MariaDB', false];
        yield 'wrong server family' => ['mariadb', '8.4.0-MySQL', false];
        yield 'upgraded production with character grammar' => ['mariadb', '11.4.13-MariaDB', true, true];
        yield 'native boundary with character grammar' => ['mariadb', '10.7.0-MariaDB', true, true];
        yield 'compatibility prefix with character grammar' => ['mariadb', '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1', true, true];
        yield 'character grammar cannot admit wrong driver' => ['mysql', '11.4.13-MariaDB', false, true];
        yield 'character grammar cannot admit wrong family' => ['mariadb', '8.4.0-MySQL', false, true];
        yield 'character grammar cannot admit malformed version' => ['mariadb', 'MariaDB-unknown', false, true];
    }
}
