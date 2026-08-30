<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique `(workspace_id, id)` on every table a composite tenant key points at.
 *
 * `id` is already the primary key, so this index adds no uniqueness the schema
 * did not already have. It exists for one reason: InnoDB will only accept a
 * foreign key whose referenced columns are the leftmost prefix of an index on
 * the parent, and `(workspace_id, id)` is not a prefix of anything here.
 *
 * It is therefore load-bearing rather than redundant. A later pass that reads
 * "unique index on a column set containing the primary key" as duplication and
 * drops one of these will be refused by InnoDB with errno 1553 once the keys in
 * `2026_08_31_000200` depend on it - and accepted without comment by SQLite,
 * which is the whole reason the MariaDB job exists.
 */
return new class extends Migration
{
    /**
     * Deliberately literal rather than read from
     * `App\Support\Tenancy\TenantReferenceInventory`: a migration states what it
     * did to a database that has already been migrated, and must not change
     * meaning when the inventory grows.
     *
     * @var list<string>
     */
    private const PARENTS = [
        'client_companies',
        'client_projects',
        'client_company_memberships',
        'client_proposals',
        'client_agreements',
        'client_invoices',
        'client_invoice_lines',
        'client_invoice_payments',
        'client_time_entries',
        'client_stripe_customers',
    ];

    public function up(): void
    {
        foreach (self::PARENTS as $parent) {
            Schema::table($parent, function (Blueprint $table) use ($parent): void {
                $table->unique(['workspace_id', 'id'], $parent.'_workspace_id_id_unique');
            });
        }
    }

    /**
     * Put back the index InnoDB discarded when this one arrived.
     *
     * Each of these tables has its own `workspace_id` key to `workspaces`, and
     * InnoDB served it with an index it created implicitly, named after the
     * constraint. Adding a unique `(workspace_id, id)` gives InnoDB a better index
     * for the same key, and **it drops the implicit one**. Dropping ours then
     * leaves the key with nothing, and MariaDB refuses with errno 1553 - so this
     * migration could be applied but not reversed.
     *
     * Migration order rules out fixing it from the other end: `000000` rolls back
     * after this file, so a table whose key this file displaced cannot wait for
     * that. The index is instead restored here, under the name it originally had,
     * before the unique index goes.
     *
     * Which tables need it is read from the live schema rather than listed,
     * because the question is "does anything else here lead with workspace_id",
     * and the answer changes whenever an index is added elsewhere. Today it is
     * `client_projects` alone: every other parent already has one, and
     * `client_company_memberships` got `ccm_workspace_idx` in `000000`.
     *
     * SQLite creates no implicit index, refuses nothing, and needs none of this.
     */
    public function down(): void
    {
        $restoring = Schema::getConnection()->getDriverName() !== 'sqlite';

        foreach (array_reverse(self::PARENTS) as $parent) {
            if ($restoring && ! $this->hasAnotherWorkspaceIndex($parent)) {
                Schema::table($parent, function (Blueprint $table) use ($parent): void {
                    $table->index('workspace_id', $parent.'_workspace_id_foreign');
                });
            }

            Schema::table($parent, function (Blueprint $table) use ($parent): void {
                $table->dropUnique($parent.'_workspace_id_id_unique');
            });
        }
    }

    /**
     * Whether anything but the index about to be dropped leads with `workspace_id`.
     */
    private function hasAnotherWorkspaceIndex(string $parent): bool
    {
        foreach (Schema::getIndexes($parent) as $index) {
            if ($index['name'] === $parent.'_workspace_id_id_unique') {
                continue;
            }

            $columns = array_map(strtolower(...), $index['columns']);

            if (($columns[0] ?? null) === 'workspace_id') {
                return true;
            }
        }

        return false;
    }
};
