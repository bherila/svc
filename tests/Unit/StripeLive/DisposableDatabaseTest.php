<?php

namespace Tests\Unit\StripeLive;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;
use Tests\StripeLive\Support\DisposableDatabase;

/** The Stripe test-mode suite never refreshes a database that is not SQLite in memory. */
final class DisposableDatabaseTest extends TestCase
{
    public function test_sqlite_in_memory_passes(): void
    {
        DisposableDatabase::assertDisposable('sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null], false);
        DisposableDatabase::assertDisposable('sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'url' => ''], false);
        $this->addToAssertionCount(1);
    }

    public function test_a_persistent_sqlite_file_is_refused_before_anything_in_it_is_touched(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'svc-stripe-live-sentinel-');
        $this->assertIsString($file);
        try {
            $pdo = new PDO('sqlite:'.$file);
            $pdo->exec('CREATE TABLE sentinel (note TEXT)');
            $pdo->exec("INSERT INTO sentinel (note) VALUES ('keep me')");

            try {
                DisposableDatabase::assertDisposable('sqlite', ['driver' => 'sqlite', 'database' => $file, 'url' => null], false);
                $this->fail('A persistent SQLite database must be refused.');
            } catch (RuntimeException $refusal) {
                $this->assertStringContainsString('only runs on SQLite in memory', $refusal->getMessage());
                $this->assertStringContainsString('a persistent database', $refusal->getMessage());
                $this->assertStringNotContainsString($file, $refusal->getMessage());
            }

            $this->assertSame('keep me', $pdo->query('SELECT note FROM sentinel')->fetchColumn());
        } finally {
            @unlink($file);
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>|null}> */
    public static function refusedConnections(): iterable
    {
        yield 'mariadb' => [['driver' => 'mariadb', 'database' => 'svc', 'url' => null]];
        yield 'mysql named :memory:' => [['driver' => 'mysql', 'database' => ':memory:', 'url' => null]];
        yield 'sqlite with a url' => [['driver' => 'sqlite', 'database' => ':memory:', 'url' => 'sqlite:///var/data/svc.sqlite']];
        yield 'no connection' => [null];
        yield 'no driver' => [['database' => ':memory:']];
    }

    /** @param  array<string, mixed>|null  $connection */
    #[DataProvider('refusedConnections')]
    public function test_anything_else_is_refused(?array $connection): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nothing was changed.');
        DisposableDatabase::assertDisposable('default', $connection, false);
    }

    public function test_cached_configuration_is_refused_even_for_memory(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cached configuration');
        DisposableDatabase::assertDisposable('sqlite', ['driver' => 'sqlite', 'database' => ':memory:', 'url' => null], true);
    }

    public function test_the_suite_configuration_forces_every_environment_value(): void
    {
        $xml = new SimpleXMLElement((string) file_get_contents(dirname(__DIR__, 3).'/phpunit.stripe-live.xml'));
        $envs = $xml->xpath('/phpunit/php/env');
        $this->assertNotEmpty($envs);
        $values = [];
        foreach ($envs as $env) {
            $this->assertSame('true', (string) $env['force'], 'phpunit.stripe-live.xml must force '.$env['name'].'.');
            $values[(string) $env['name']] = (string) $env['value'];
        }
        $this->assertSame(['sqlite', ':memory:', ''], [$values['DB_CONNECTION'] ?? null, $values['DB_DATABASE'] ?? null, $values['DB_URL'] ?? null]);
    }
}
