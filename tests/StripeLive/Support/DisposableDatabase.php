<?php

namespace Tests\StripeLive\Support;

use RuntimeException;

/**
 * The database gate of the Stripe test-mode suite. People are asked to run the
 * suite by hand with a real Stripe key, and RefreshDatabase runs
 * `migrate:fresh` on whatever connection is configured. A test-only Stripe key
 * protects Stripe, not the database: an exported DB_DATABASE pointing at a
 * developer's persistent SQLite file, or a cached configuration that ignores
 * phpunit.stripe-live.xml, would be wiped. So the suite refuses to refresh any
 * database but SQLite in memory, and checks the configuration it actually
 * resolved, not the one it asked for.
 */
final class DisposableDatabase
{
    /**
     * @param  array<string, mixed>|null  $connection  the resolved settings of the default connection
     *
     * @throws RuntimeException when the database is anything but SQLite in memory, or configuration is cached
     */
    public static function assertDisposable(string $name, ?array $connection, bool $configurationCached): void
    {
        if ($configurationCached) {
            throw new RuntimeException('The Stripe test-mode suite refuses to run with cached configuration (bootstrap/cache/config.php): phpunit.stripe-live.xml cannot set the database then. Run `php artisan config:clear` first.');
        }
        $driver = is_array($connection) ? ($connection['driver'] ?? null) : null;
        $database = is_array($connection) ? ($connection['database'] ?? null) : null;
        $url = is_array($connection) ? ($connection['url'] ?? null) : null;
        if ($driver !== 'sqlite' || $database !== ':memory:' || (is_string($url) && $url !== '')) {
            throw new RuntimeException(sprintf(
                'The Stripe test-mode suite refreshes its database and only runs on SQLite in memory; the "%s" connection resolved to driver %s, database %s. Nothing was changed.',
                $name,
                is_string($driver) ? $driver : 'unknown',
                is_string($database) ? ($database === ':memory:' ? ':memory:' : 'a persistent database') : 'unknown',
            ));
        }
    }
}
