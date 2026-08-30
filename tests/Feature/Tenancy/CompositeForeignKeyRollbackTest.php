<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;

/**
 * `down()` has to put the schema back, on the engine that ships.
 *
 * The structural comparison this branch first relied on ran on SQLite, and
 * SQLite cannot see the way this fails. InnoDB will not accept a foreign key it
 * has no index to serve, so where nothing covers `(workspace_id, column)` it
 * creates an index itself - and **dropping the constraint does not drop that
 * index**. A rollback that only drops keys therefore leaves them behind, and the
 * schema silently does not return to what it was. SQLite creates no such index,
 * so the same rollback looks perfect there.
 *
 * This runs a real migrate, a real rollback and a real re-migrate against
 * whichever engine the suite is pointed at, and compares the schema at each
 * step. It does all of it in a **throwaway database of its own** - a temp file on
 * SQLite, a scratch schema on MariaDB - so nothing it does can reach the suite's
 * own database, which is the mistake `ProjectAccessLegacyOrphanTest` had to be
 * fixed for.
 */
final class CompositeForeignKeyRollbackTest extends TestCase
{
    use UsesAProbeDatabase;

    private const CONNECTION = 'schema_rollback_probe';

    /** Objects the three migrations of #113 add, none of which may survive a rollback. */
    private const ADDED_INDEXES = [
        'client_companies_workspace_id_id_unique',
        'client_projects_workspace_id_id_unique',
        'client_company_memberships_workspace_id_id_unique',
        'client_proposals_workspace_id_id_unique',
        'client_agreements_workspace_id_id_unique',
        'client_invoices_workspace_id_id_unique',
        'client_invoice_lines_workspace_id_id_unique',
        'client_invoice_payments_workspace_id_id_unique',
        'client_time_entries_workspace_id_id_unique',
        'client_stripe_customers_workspace_id_id_unique',
        'ccm_ws_company_idx',
        'cp_ws_company_idx',
        'cbs_ws_company_idx',
        'ct_ws_project_idx',
        'cpm_ws_project_idx',
        'cppa_ws_membership_idx',
        'cte_ws_company_idx',
        'cte_ws_project_idx',
        'cilte_ws_line_idx',
        'cspm_ws_customer_idx',
    ];

    public function test_rolling_back_leaves_no_trace_of_the_composite_keys(): void
    {
        $this->bootProbeDatabase(self::CONNECTION);

        $this->migrate();
        $migrated = $this->fingerprint();

        $this->rollback();
        $rolledBack = $this->fingerprint();

        // Nothing this branch creates may survive the rollback. On MariaDB an
        // index InnoDB created to serve a key it was given would appear here,
        // named after that constraint.
        $survivors = [];

        foreach ($rolledBack['indexes'] as $qualified) {
            [, $name] = explode('.', $qualified, 2);

            if (in_array($name, self::ADDED_INDEXES, true) || in_array($name, $this->constraintNames(), true)) {
                $survivors[] = $qualified;
            }
        }

        $this->assertSame([], $survivors, 'These indexes outlived the rollback that was supposed to remove them.');
        $this->assertSame([], $rolledBack['foreign_keys'], 'Composite foreign keys outlived the rollback.');
        $this->assertNotContains(
            'client_company_memberships.workspace_id',
            $rolledBack['columns'],
            'The membership workspace column outlived the rollback.',
        );

        // A rollback that removed too much is just as wrong as one that removed
        // too little, so re-migrating has to land back on the same schema.
        $this->migrate();

        $this->assertSame($migrated, $this->fingerprint(), 'Re-applying the migrations did not reproduce the same schema.');
    }

    /**
     * Everything the schema says about itself, as comparable strings.
     *
     * @return array{columns: list<string>, indexes: list<string>, foreign_keys: list<string>}
     */
    private function fingerprint(): array
    {
        $schema = Schema::connection(self::CONNECTION);
        $columns = [];
        $indexes = [];
        $foreignKeys = [];

        foreach ($this->touchedTables() as $table) {
            if (! $schema->hasTable($table)) {
                continue;
            }

            foreach ($schema->getColumns($table) as $column) {
                $columns[] = $table.'.'.$column['name'].':'.$column['type'].':'.($column['nullable'] ? 'null' : 'notnull');
            }

            foreach ($schema->getIndexes($table) as $index) {
                $indexes[] = $table.'.'.$index['name'].':'.implode(',', $index['columns']).':'.($index['unique'] ? 'unique' : 'index');
            }

            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                // Only this branch's shape: a pair referencing a parent's
                // (workspace_id, id). The composite key `client_project_memberships`
                // already carried since 2026_08_23_210000 points at
                // workspace_memberships (workspace_id, user_id) and is not ours to
                // assert the absence of. SQLite does not report constraint names,
                // so the shape is what identifies them on both engines.
                $referenced = array_map(strtolower(...), $foreignKey['foreign_columns']);

                if ($referenced !== ['workspace_id', 'id']) {
                    continue;
                }

                $foreignKeys[] = $table.'.('.implode(',', $foreignKey['columns']).')->'
                    .$foreignKey['foreign_table'].'('.implode(',', $referenced).')';
            }
        }

        sort($columns);
        sort($indexes);
        sort($foreignKeys);

        return ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $foreignKeys];
    }

    /** @return list<string> */
    private function touchedTables(): array
    {
        return [
            'client_companies', 'client_company_memberships', 'client_company_activity',
            'client_projects', 'client_project_memberships', 'client_portal_project_access',
            'client_proposals', 'client_proposal_items', 'client_agreements',
            'client_agreement_recurring_items', 'client_billing_schedules', 'client_tasks',
            'client_time_entries', 'client_invoices', 'client_invoice_lines',
            'client_invoice_line_time_entries', 'client_invoice_payments',
            'client_invoice_email_deliveries', 'payment_reconciliations',
            'client_stripe_customers', 'client_stripe_payment_methods',
        ];
    }

    /** @return list<string> */
    private function constraintNames(): array
    {
        return [
            'ccm_ws_company_fk', 'cca_ws_company_fk', 'cp_ws_company_fk', 'cpr_ws_company_fk',
            'cpi_ws_proposal_fk', 'cag_ws_company_fk', 'cari_ws_agreement_fk', 'cbs_ws_company_fk',
            'cbs_ws_agreement_fk', 'ct_ws_project_fk', 'cpm_ws_project_fk', 'cppa_ws_project_fk',
            'cppa_ws_membership_fk', 'cte_ws_company_fk', 'cte_ws_project_fk', 'ci_ws_company_fk',
            'cil_ws_invoice_fk', 'cilte_ws_line_fk', 'cilte_ws_time_entry_fk', 'cip_ws_invoice_fk',
            'cied_ws_invoice_fk', 'pr_ws_payment_fk', 'csc_ws_company_fk', 'cspm_ws_company_fk',
            'cspm_ws_customer_fk',
        ];
    }

    private function migrate(): void
    {
        Artisan::call('migrate', ['--database' => self::CONNECTION, '--force' => true]);
    }

    private function rollback(): void
    {
        Artisan::call('migrate:rollback', ['--database' => self::CONNECTION, '--step' => 3, '--force' => true]);
    }
}
