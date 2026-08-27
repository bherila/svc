<?php

namespace Tests\Feature\ExternalImport;

use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A source that has moved can be declared, and the declaration is checked.
 *
 * Every ledger row records the identity of the database it came from, and an
 * import refuses to reconcile against a database whose identity differs. That
 * is correct until the source moves: the database this system imported from no
 * longer holds the client-management tables, so the rows survive only in a
 * restore under a different name, whose identity therefore differs, so the
 * ledger resolves to nothing and no repair can ever run again.
 *
 * `restore_of_database` states the equivalence instead of inferring it. These
 * tests pin the three properties that keep it from being a hole: it is never
 * implicit, it moves only the name and not the connection, and it cannot be
 * used to aim a source at its own destination.
 */
final class DeclaredRestoreSourceTest extends TestCase
{
    private const ORIGINAL = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'source_as_imported'];

    private const RESTORE = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => '3306', 'database' => 'source_restored_elsewhere'];

    public function test_without_a_declaration_a_renamed_database_is_a_different_source(): void
    {
        $original = $this->resolveWith(self::ORIGINAL, null);
        $renamed = $this->resolveWith(self::RESTORE, null);

        $this->assertNotSame(
            $original['identity_hash'],
            $renamed['identity_hash'],
            'A different database must not silently answer for the one the ledger recorded',
        );
        $this->assertNull($renamed['declared_restore_of']);
    }

    public function test_a_declared_restore_matches_the_ledger_of_the_database_it_names(): void
    {
        $original = $this->resolveWith(self::ORIGINAL, null);
        $declared = $this->resolveWith(self::RESTORE, 'source_as_imported');

        $this->assertSame(
            $original['identity_hash'],
            $declared['identity_hash'],
            'The declaration exists so ledger rows recorded against the original resolve',
        );
        $this->assertSame('source_as_imported', $declared['declared_restore_of']);
    }

    /**
     * The declaration must move the identity only. Reading the database it
     * names - which may not exist, or may be a live system - would be the
     * opposite of the intent.
     */
    public function test_the_declaration_does_not_change_which_database_is_read(): void
    {
        $declared = $this->resolveWith(self::RESTORE, 'source_as_imported');

        $this->assertSame('source_restored_elsewhere', $declared['config']['database']);
    }

    /**
     * Equivalence to the destination is judged on where the source actually is.
     * Otherwise declaring a restore would be a way to point an importer at the
     * database it is about to write.
     */
    public function test_a_declaration_cannot_disguise_the_destination_as_a_source(): void
    {
        Config::set('database.connections.probe_destination', self::RESTORE);
        Config::set('external-import.destination_connection', 'probe_destination');

        // Reads the destination, but claims to be a restore of something else.
        $source = $this->resolveWith(self::RESTORE, 'source_as_imported');

        $this->expectException(SourceConfigurationException::class);
        $this->expectExceptionMessage('source_is_destination');

        app(SourceGuard::class)->assertDistinctFromDestination($source);
    }

    /**
     * For sqlite the declaration names a path rather than a database. Supported
     * on the same terms - refusing it here would have left the whole mechanism
     * untestable, which is a worse outcome than the case it guarded against.
     */
    public function test_a_sqlite_source_can_declare_the_path_it_stands_in_for(): void
    {
        $original = $this->resolveWith(['driver' => 'sqlite', 'database' => '/tmp/source-as-imported.sqlite'], null);
        $declared = $this->resolveWith(
            ['driver' => 'sqlite', 'database' => '/tmp/source-restored.sqlite'],
            '/tmp/source-as-imported.sqlite',
        );

        $this->assertSame($original['identity_hash'], $declared['identity_hash']);
        $this->assertSame('/tmp/source-restored.sqlite', $declared['config']['database']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function resolveWith(array $config, ?string $restoreOf): array
    {
        Config::set('external-import.sources.probe', [
            'connection' => 'probe_connection',
            'read_only' => true,
            'restore_of_database' => $restoreOf,
            'config' => $config,
        ]);
        Config::set('database.connections.probe_connection', $config);

        return app(SourceGuard::class)->resolve('probe');
    }
}
