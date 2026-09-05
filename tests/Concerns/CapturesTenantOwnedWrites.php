<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assert the property, not the call sites.
 *
 * `AGENTS.md` requires every tenant-owned write to be workspace-scoped, and
 * neither `BelongsToWorkspace` nor `ScopesPivotDeletesToWorkspace` can enforce
 * that on a builder write - a relation `update()`, a `detach()`, or any
 * `Model::query()->...->update()` never touches a model instance. Those name
 * the workspace at the call site or not at all.
 *
 * A test that lists call sites only ever finds the ones somebody thought of,
 * which is how #230 fixed four writes and left seventeen: the enumeration in
 * #231 had to be produced by a second search months later. This runs a real
 * flow, reads every statement it issues, and refuses any update or delete
 * against a table the *schema* says is tenant-owned whose predicate does not
 * mention the workspace. A write added tomorrow is in the test the day it
 * appears, without anyone adding it.
 *
 * The vacuity guard is not optional. Two ways this assertion passes while
 * inspecting nothing, both of which happened while #230 was in review: a
 * quoting style the pattern does not know, and a flow that turns out not to
 * write to the table the test is about.
 */
trait CapturesTenantOwnedWrites
{
    /**
     * Run $flow and return every update/delete it issued, as [table, sql].
     *
     * @return list<array{0: string, 1: string}>
     */
    protected function writesIssuedBy(callable $flow): array
    {
        $writes = [];

        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            // Both quoting styles: MariaDB writes `table`, SQLite writes
            // "table", and a pattern that knew only one would match nothing on
            // the other lane and leave the caller asserting over an empty list.
            if (preg_match('/^(?:update|delete\s+from)\s+["`\[]?([a-z_]+)["`\]]?/i', ltrim($query->sql), $matches) === 1) {
                $writes[] = [strtolower($matches[1]), $query->sql];
            }
        });

        $flow();

        return $writes;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $writes  From {@see writesIssuedBy()}.
     * @param  list<string>  $mustTouch  Tables the flow has to have written to, or the assertion inspected nothing.
     */
    protected function assertEveryTenantOwnedWriteNamesItsWorkspace(array $writes, array $mustTouch): void
    {
        $unscoped = [];
        $touched = [];

        foreach ($writes as [$table, $sql]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
                continue;
            }

            $touched[$table] = true;

            // The predicate, not the assignment: `set workspace_id = ?` on an
            // insert-like update would satisfy a naive search of the whole
            // statement while scoping nothing.
            $where = strstr(strtolower($sql), ' where ');

            if ($where === false || ! str_contains($where, 'workspace_id')) {
                $unscoped[] = $sql;
            }
        }

        $this->assertSame([], $unscoped, "These tenant-owned writes name no workspace:\n".implode("\n", $unscoped));

        foreach ($mustTouch as $table) {
            $this->assertArrayHasKey(
                $table,
                $touched,
                $table.' was never written to, so this test proved nothing about it.',
            );
        }
    }
}
