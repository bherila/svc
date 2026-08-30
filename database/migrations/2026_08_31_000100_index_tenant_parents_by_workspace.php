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

    public function down(): void
    {
        foreach (array_reverse(self::PARENTS) as $parent) {
            Schema::table($parent, function (Blueprint $table) use ($parent): void {
                $table->dropUnique($parent.'_workspace_id_id_unique');
            });
        }
    }
};
