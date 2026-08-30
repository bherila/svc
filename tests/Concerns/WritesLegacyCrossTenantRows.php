<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Write a row the composite tenant keys now refuse.
 *
 * Since #113 a child row whose parent lives in another workspace cannot be
 * stored. That is the point - but a good number of tests exist precisely to
 * prove that the *application* also refuses such a row, and they can only prove
 * it by producing one. Their subject did not go away: a database migrated from
 * before these keys can still hold rows the keys would now refuse, and the
 * scoped query that ignores them is the second line of defence.
 *
 * So the fixture is written with enforcement suspended, and the suspension is
 * deliberately loud. Reaching for this helper anywhere outside such a test means
 * the schema is being argued with rather than tested.
 *
 * ## How the suspension works, per engine
 *
 * MariaDB takes `SET FOREIGN_KEY_CHECKS = 0` inside an open transaction, and
 * restoring it does not re-validate rows already written.
 *
 * SQLite ignores `PRAGMA foreign_keys` inside a transaction - silently, which is
 * why `ProjectAccessLegacyOrphanTest` has to run in its own process without one.
 * `PRAGMA defer_foreign_keys` is honoured there: it postpones every check to
 * COMMIT, which a `RefreshDatabase` transaction never reaches. Turning it back
 * off does not re-check what was deferred, and the next violation is refused
 * immediately again. `CompositeTenantForeignKeyTest` asserts that restoration
 * rather than trusting it.
 */
trait WritesLegacyCrossTenantRows
{
    protected function writingLegacyCrossTenantRows(Closure $callback): mixed
    {
        $this->suspendTenantForeignKeys(true);

        try {
            return $callback();
        } finally {
            $this->suspendTenantForeignKeys(false);
        }
    }

    private function suspendTenantForeignKeys(bool $suspend): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('pragma defer_foreign_keys = '.($suspend ? 'on' : 'off'));

            return;
        }

        DB::statement('set foreign_key_checks = '.($suspend ? '0' : '1'));
    }
}
