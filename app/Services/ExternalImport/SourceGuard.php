<?php

namespace App\Services\ExternalImport;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

final class SourceGuard
{
    /** @return array{key: string, connection: string, config: array<string, mixed>, identity: array<string, string>, identity_hash: string} */
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

        $identity = $this->identity($config);

        return [
            'key' => $source,
            'connection' => $connectionName,
            'config' => $config,
            'identity' => $identity,
            'identity_hash' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
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

        if ($source['identity'] === $this->identity($destinationConfig)) {
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
     * @return array<string, string>
     */
    private function identity(array $config): array
    {
        $driver = strtolower((string) ($config['driver'] ?? ''));
        if ($driver === 'sqlite') {
            $database = (string) ($config['database'] ?? $config['url'] ?? '');

            return ['driver' => $driver, 'database' => $this->normalizePath($database)];
        }

        return [
            'driver' => $driver,
            'host' => strtolower((string) ($config['host'] ?? '')),
            'port' => (string) ($config['port'] ?? ''),
            'database' => (string) ($config['database'] ?? ''),
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
