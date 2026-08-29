<?php

namespace Tests\Unit;

use App\Console\Commands\DatabaseStatusCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseStatusCommandTest extends TestCase
{
    #[DataProvider('compatibilityCases')]
    public function test_uuid_grammar_boundary_is_explicit(string $driver, string $version, bool $compatible): void
    {
        $this->assertSame(
            $compatible,
            DatabaseStatusCommand::isUuidGrammarCompatible($driver, $version),
        );
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function compatibilityCases(): iterable
    {
        yield 'current production' => ['mariadb', '10.6.28-MariaDB', true];
        yield 'compatibility prefix' => ['mariadb', '5.5.5-10.6.28-MariaDB', true];
        yield 'native uuid boundary' => ['mariadb', '10.7.0-MariaDB', false];
        yield 'distro suffix cannot hide a future server' => ['mariadb', '5.5.5-10.11.8-MariaDB-0ubuntu0.24.04.1', false];
        yield 'future MariaDB' => ['mariadb', '11.8.3-MariaDB', false];
        yield 'wrong Laravel driver' => ['mysql', '10.6.28-MariaDB', false];
        yield 'wrong server family' => ['mariadb', '8.4.0-MySQL', false];
    }
}
