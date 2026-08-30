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
 * immediately again.
 *
 * ## Restoration is asserted, not assumed
 *
 * Every caller is a test whose point is that the *application* refuses the row.
 * If the suspension leaked past the fixture, the assertion phase would run
 * without a constraint and the test would go on passing while proving less than
 * it claims - which is the failure mode this repo has already paid for twice: a
 * check that measures its own interference, and a check that reports success for
 * a case it never examined.
 *
 * So the helper probes the engine on the way out and fails if enforcement is not
 * back. That makes restoration a property of all of its call sites rather than
 * of the one test that thought to check.
 */
trait WritesLegacyCrossTenantRows
{
    protected function writingLegacyCrossTenantRows(Closure $callback): mixed
    {
        $this->suspendTenantForeignKeys(true);

        try {
            $result = $callback();
        } finally {
            $this->suspendTenantForeignKeys(false);
        }

        $this->assertTrue(
            $this->tenantForeignKeysAreEnforced(),
            'Foreign key enforcement was not restored after seeding, so everything this test asserts afterwards runs unconstrained.',
        );

        return $result;
    }

    private function suspendTenantForeignKeys(bool $suspend): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('pragma defer_foreign_keys = '.($suspend ? 'on' : 'off'));

            return;
        }

        DB::statement('set foreign_key_checks = '.($suspend ? '0' : '1'));
    }

    private function tenantForeignKeysAreEnforced(): bool
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // Both halves matter: `foreign_keys` off would mean nothing is
            // checked, and `defer_foreign_keys` still on would mean nothing is
            // checked until a commit this transaction never reaches.
            return (int) DB::selectOne('pragma foreign_keys')->foreign_keys === 1
                && (int) DB::selectOne('pragma defer_foreign_keys')->defer_foreign_keys === 0;
        }

        return (int) DB::selectOne('select @@session.foreign_key_checks as enforced')->enforced === 1;
    }
}
