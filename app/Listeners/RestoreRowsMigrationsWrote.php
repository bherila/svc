<?php

namespace App\Listeners;

use Illuminate\Database\Events\SchemaLoaded;
use LogicException;

/**
 * Puts back the rows a schema dump cannot carry.
 *
 * A dump holds table structure and the migrations table, so a migration that also
 * writes rows an empty database needs is recorded as having run while its rows are
 * gone. Each one listed here is run again after a dump loads, which requires it to
 * be safe to repeat. The list is checked by
 * Tests\Feature\Database\SchemaDumpMatchesMigrationsTest, which compares a dump
 * against a real replay.
 */
final class RestoreRowsMigrationsWrote
{
    /**
     * The credential revocation tables refuse every token until the clock and
     * registration rows exist.
     */
    private const array MIGRATIONS = [
        '2026_10_20_230000_create_oauth_credential_lineages',
    ];

    public function handle(SchemaLoaded $event): void
    {
        foreach (self::MIGRATIONS as $name) {
            $up = [require database_path("migrations/{$name}.php"), 'up'];

            /** @infection-ignore-all Only a mistyped entry reaches this, and PHP would stop on the call anyway; it is here for the type. */
            if (! is_callable($up)) {
                throw new LogicException("{$name} cannot be run again.");
            }

            $up();
        }
    }
}
