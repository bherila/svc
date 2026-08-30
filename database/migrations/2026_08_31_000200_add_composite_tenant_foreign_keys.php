<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Composite `(workspace_id, parent_id)` keys, so a cross-tenant reference cannot
 * be stored at all.
 *
 * Every one of these sits *alongside* the existing single-column foreign key.
 * None is dropped. An index or key that reads as redundant on SQLite can be the
 * only thing InnoDB has to serve a constraint, and removing one is refused with
 * errno 1553 - which the SQLite lane cannot see. If the single-column keys are
 * ever revisited, that is a separate change with the MariaDB job as its judge.
 *
 * ## Why each key's delete rule mirrors the single-column key it joins
 *
 * Two keys from the same child column to the same parent are both evaluated on a
 * parent delete, so they have to agree. CASCADE beside CASCADE deletes the same
 * row twice, which is a no-op; RESTRICT beside RESTRICT refuses twice. A RESTRICT
 * beside a CASCADE would block a delete the schema allows today.
 *
 * ## Why nullable columns are exempt
 *
 * A composite key pairs the column with `workspace_id`, which is NOT NULL on
 * every child table here, and InnoDB refuses `ON DELETE SET NULL` on a key
 * containing a NOT NULL column (errno 1830). So a nullable column whose existing
 * rule is SET NULL cannot get a matching composite key, and giving it RESTRICT
 * instead would contradict the rule already on it. Those columns are listed as
 * exemptions in `App\Support\Tenancy\TenantReferenceInventory`, are still counted
 * by `svc:schema:audit-tenant-fks`, and are explained in
 * `docs/client-management/tenant-foreign-keys.md`.
 *
 * ## Before running this against real data
 *
 * `php artisan svc:schema:audit-tenant-fks` counts the rows each key below would
 * refuse. It prints counts only and exits non-zero if there are any. A non-zero
 * result is a migration that will abort partway through, not a report to file.
 */
return new class extends Migration
{
    /**
     * child table => list of [child column, parent table, constraint name, delete rule].
     *
     * Deliberately literal rather than read from
     * `App\Support\Tenancy\TenantReferenceInventory`: a migration records what it
     * did to a database that has already been migrated, and must not change
     * meaning when the inventory grows. `TenantForeignKeyInventoryTest` asserts
     * the two agree with the live schema.
     *
     * Grouped by child table so SQLite rebuilds each table once rather than once
     * per key.
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string}>>
     */
    private const REFERENCES = [
        'client_company_memberships' => [
            ['client_company_id', 'client_companies', 'ccm_ws_company_fk', 'cascade'],
        ],
        'client_company_activity' => [
            ['client_company_id', 'client_companies', 'cca_ws_company_fk', 'cascade'],
        ],
        'client_projects' => [
            ['client_company_id', 'client_companies', 'cp_ws_company_fk', 'cascade'],
        ],
        'client_proposals' => [
            ['client_company_id', 'client_companies', 'cpr_ws_company_fk', 'cascade'],
        ],
        'client_proposal_items' => [
            ['client_proposal_id', 'client_proposals', 'cpi_ws_proposal_fk', 'cascade'],
        ],
        'client_agreements' => [
            ['client_company_id', 'client_companies', 'cag_ws_company_fk', 'cascade'],
        ],
        'client_agreement_recurring_items' => [
            ['client_agreement_id', 'client_agreements', 'cari_ws_agreement_fk', 'cascade'],
        ],
        'client_billing_schedules' => [
            ['client_company_id', 'client_companies', 'cbs_ws_company_fk', 'restrict'],
            // No single-column key today. The column is NOT NULL and unique per
            // workspace, so the schedule is already an attribute of exactly one
            // agreement; cascade states what the data already means.
            ['client_agreement_id', 'client_agreements', 'cbs_ws_agreement_fk', 'cascade'],
        ],
        'client_tasks' => [
            ['client_project_id', 'client_projects', 'ct_ws_project_fk', 'cascade'],
        ],
        'client_project_memberships' => [
            ['client_project_id', 'client_projects', 'cpm_ws_project_fk', 'cascade'],
        ],
        'client_portal_project_access' => [
            ['client_project_id', 'client_projects', 'cppa_ws_project_fk', 'cascade'],
            ['client_company_membership_id', 'client_company_memberships', 'cppa_ws_membership_fk', 'cascade'],
        ],
        'client_time_entries' => [
            ['client_company_id', 'client_companies', 'cte_ws_company_fk', 'cascade'],
            ['client_project_id', 'client_projects', 'cte_ws_project_fk', 'cascade'],
        ],
        'client_invoices' => [
            ['client_company_id', 'client_companies', 'ci_ws_company_fk', 'restrict'],
        ],
        'client_invoice_lines' => [
            ['client_invoice_id', 'client_invoices', 'cil_ws_invoice_fk', 'cascade'],
        ],
        'client_invoice_line_time_entries' => [
            ['client_invoice_line_id', 'client_invoice_lines', 'cilte_ws_line_fk', 'cascade'],
            // No single-column key today, which is how a pivot row could name a
            // time entry from another tenant. Detaching on delete matches the
            // pivot's meaning: the row records that an entry was billed on a line.
            ['client_time_entry_id', 'client_time_entries', 'cilte_ws_time_entry_fk', 'cascade'],
        ],
        'client_invoice_payments' => [
            ['client_invoice_id', 'client_invoices', 'cip_ws_invoice_fk', 'cascade'],
        ],
        'client_invoice_email_deliveries' => [
            ['client_invoice_id', 'client_invoices', 'cied_ws_invoice_fk', 'cascade'],
        ],
        'payment_reconciliations' => [
            ['client_invoice_payment_id', 'client_invoice_payments', 'pr_ws_payment_fk', 'cascade'],
        ],
        'client_stripe_customers' => [
            ['client_company_id', 'client_companies', 'csc_ws_company_fk', 'restrict'],
        ],
        'client_stripe_payment_methods' => [
            ['client_company_id', 'client_companies', 'cspm_ws_company_fk', 'restrict'],
            ['client_stripe_customer_id', 'client_stripe_customers', 'cspm_ws_customer_fk', 'cascade'],
        ],
    ];

    /**
     * child table => [child column, index name] for the keys with no covering index.
     *
     * InnoDB will not accept a foreign key it cannot serve, so where no existing
     * index has `(workspace_id, column)` as its leftmost prefix it creates one
     * itself, named after the constraint. **Dropping the constraint does not drop
     * that index**, so a rollback that only drops keys leaves the implicit
     * indexes behind and the schema does not return to what it was.
     *
     * Creating them explicitly is the fix rather than hunting the implicit names
     * afterwards: InnoDB then has an index already and creates nothing, up() and
     * down() name the same objects, and the index becomes a reviewable part of
     * the schema instead of a side effect.
     *
     * SQLite creates no such index and is happy either way, which is why this
     * whole class of drift is invisible on that lane. `CompositeForeignKeyRollbackTest`
     * runs a real migrate/rollback comparison on whichever engine it is given.
     *
     * The other fifteen references are already covered - by
     * `cca_workspace_company_created_idx`, `cari_workspace_agreement_idx`,
     * `billing_schedule_agreement_unique`, `invoice_time_entry_once`,
     * `cip_workspace_invoice_status_idx` and their peers - and get nothing new.
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const COVERING_INDEXES = [
        'client_company_memberships' => [['client_company_id', 'ccm_ws_company_idx']],
        'client_projects' => [['client_company_id', 'cp_ws_company_idx']],
        'client_billing_schedules' => [['client_company_id', 'cbs_ws_company_idx']],
        'client_tasks' => [['client_project_id', 'ct_ws_project_idx']],
        'client_project_memberships' => [['client_project_id', 'cpm_ws_project_idx']],
        'client_portal_project_access' => [['client_company_membership_id', 'cppa_ws_membership_idx']],
        'client_time_entries' => [
            ['client_company_id', 'cte_ws_company_idx'],
            ['client_project_id', 'cte_ws_project_idx'],
        ],
        'client_invoice_line_time_entries' => [['client_invoice_line_id', 'cilte_ws_line_idx']],
        'client_stripe_payment_methods' => [['client_stripe_customer_id', 'cspm_ws_customer_idx']],
    ];

    public function up(): void
    {
        // Indexes first: InnoDB only creates one of its own when it finds none it
        // can use, so having them in place is what stops the implicit index that
        // a rollback could not then remove.
        foreach (self::COVERING_INDEXES as $child => $indexes) {
            Schema::table($child, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as [$column, $name]) {
                    $table->index(['workspace_id', $column], $name);
                }
            });
        }

        foreach (self::REFERENCES as $child => $references) {
            Schema::table($child, function (Blueprint $table) use ($references): void {
                foreach ($references as [$column, $parent, $name, $onDelete]) {
                    $foreign = $table->foreign(['workspace_id', $column], $name)
                        ->references(['workspace_id', 'id'])
                        ->on($parent);

                    if ($onDelete === 'cascade') {
                        $foreign->cascadeOnDelete();
                    } else {
                        $foreign->restrictOnDelete();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Several of these keys need an explicit name because Laravel's generated
        // one would exceed MariaDB's 64-character identifier limit, and only the
        // MariaDB grammar can drop a foreign key by name. SQLite drops one by
        // rebuilding the table without the matching column pair, so it is given
        // the columns instead.
        $byName = Schema::getConnection()->getDriverName() !== 'sqlite';

        foreach (array_reverse(self::REFERENCES, true) as $child => $references) {
            Schema::table($child, function (Blueprint $table) use ($references, $byName): void {
                foreach (array_reverse($references) as [$column, , $name]) {
                    $table->dropForeign($byName ? $name : ['workspace_id', $column]);
                }
            });
        }

        // Only after the keys are gone: InnoDB refuses to drop an index a foreign
        // key still depends on, with errno 1553.
        foreach (array_reverse(self::COVERING_INDEXES, true) as $child => $indexes) {
            Schema::table($child, function (Blueprint $table) use ($indexes): void {
                foreach (array_reverse($indexes) as [, $name]) {
                    $table->dropIndex($name);
                }
            });
        }
    }
};
