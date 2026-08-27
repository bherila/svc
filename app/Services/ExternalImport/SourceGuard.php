<?php

namespace App\Services\ExternalImport;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Decides which external database an import may read, and which one the ledger
 * says it already read.
 *
 * The identity hash is the link between those two questions: it is recorded on
 * every ledger row at import, and recomputed here from configuration. A hash
 * that does not match means the configured database is not the one the rows
 * came from, and the import refuses rather than silently reconciling against a
 * stranger.
 *
 * That is right for the ordinary case and wrong for one real one: a source can
 * be *moved*. The database this system was imported from no longer holds the
 * client-management tables - they were removed from it, which is why a restore
 * exists. The rows are intact in the restore, but its name differs, so the hash
 * differs, so the ledger resolves to nothing and every repair is blocked
 * forever.
 *
 * `restore_of_database` is how that is said out loud. It changes only the name
 * used for hashing; the connection still reads the database actually
 * configured. Two properties keep it from becoming a hole:
 *
 * - It must be declared. Nothing infers it, so substitution is never silent.
 *   For sqlite it names a path rather than a database, which says the same
 *   thing: this file stands where that one stood.
 * - It is checked, not trusted. Every ledger row carries a fingerprint of the
 *   source row as it was at import; callers verify those, and a restore that is
 *   not the same data fails on the fingerprints rather than on the name.
 */
final class SourceGuard
{
    /** @return array{key: string, connection: string, config: array<string, mixed>, identity: array<string, string>, identity_hash: string, declared_restore_of: ?string} */
    public function resolve(string $source): array
    {
        $sources = Config::get('external-import.sources', []);
        $entry = is_array($sources) ? ($sources[$source] ?? null) : null;

        if (! is_array($entry)) {
            throw new SourceConfigurationException('source_not_allowlisted');
        }

        if (($entry['read_only'] ?? false) !== true) {
            throw new SourceConfigurationException('source_not_explicitly_read_only');
        }

        $connectionName = (string) ($entry['connection'] ?? '');
        if ($connectionName === '') {
            throw new SourceConfigurationException('source_connection_missing');
        }

        $configured = Config::get("database.connections.{$connectionName}", []);
        $extra = $entry['config'] ?? [];
        $extra = is_array($extra) ? array_filter($extra, static fn (mixed $value): bool => $value !== null) : [];
        $config = array_replace(is_array($configured) ? $configured : [], $extra);
        $driver = $config['driver'] ?? null;
        if (! is_string($driver) || $driver === '') {
            throw new SourceConfigurationException('source_driver_missing');
        }

        if (($config['driver'] ?? '') !== 'sqlite' && (($config['database'] ?? null) === null || (string) $config['database'] === '')) {
            throw new SourceConfigurationException('source_database_missing');
        }

        $restoreOf = $entry['restore_of_database'] ?? null;
        $restoreOf = is_string($restoreOf) && $restoreOf !== '' ? $restoreOf : null;

        $identity = $this->identity($config, $restoreOf);

        return [
            'key' => $source,
            'connection' => $connectionName,
            'config' => $config,
            'identity' => $identity,
            'identity_hash' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
            'declared_restore_of' => $restoreOf,
        ];
    }

    /** @param array{config: array<string, mixed>, identity: array<string, string>} $source */
    public function assertDistinctFromDestination(array $source): void
    {
        $destinationName = Config::get('external-import.destination_connection') ?: Config::get('database.default');
        $destinationConfig = Config::get("database.connections.{$destinationName}", []);

        if (! is_array($destinationConfig) || $destinationConfig === []) {
            throw new SourceConfigurationException('destination_connection_missing');
        }

        // Compared on where the source actually is, not on the name it declares
        // itself a restore of - otherwise declaring a restore could let a source
        // read the destination it is about to write.
        if ($this->identity($source['config']) === $this->identity($destinationConfig)) {
            throw new SourceConfigurationException('source_is_destination');
        }
    }

    /** @param array{config: array<string, mixed>, identity_hash: string} $source */
    public function connection(array $source): ConnectionInterface
    {
        return DB::build(array_replace($source['config'], ['name' => $this->runtimeName($source)]));
    }

    /** @param array{config: array<string, mixed>, identity_hash: string} $source */
    public function runtimeName(array $source): string
    {
        return 'external_import_source_'.substr($source['identity_hash'], 0, 16);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  string|null  $restoreOf  Database name the ledger recorded, when this one is a declared restore of it.
     * @return array<string, string>
     */
    private function identity(array $config, ?string $restoreOf = null): array
    {
        $driver = strtolower((string) ($config['driver'] ?? ''));
        if ($driver === 'sqlite') {
            // The declaration names a path here rather than a database, which is
            // the same statement: this file stands where that one stood.
            $database = $restoreOf ?? (string) ($config['database'] ?? $config['url'] ?? '');

            return ['driver' => $driver, 'database' => $this->normalizePath($database)];
        }

        return [
            'driver' => $driver,
            'host' => strtolower((string) ($config['host'] ?? '')),
            'port' => (string) ($config['port'] ?? ''),
            'database' => $restoreOf ?? (string) ($config['database'] ?? ''),
        ];
    }

    private function normalizePath(string $path): string
    {
        if ($path === ':memory:') {
            return $path;
        }

        return realpath($path) ?: $path;
    }
}
