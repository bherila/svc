<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\UsesAProbeDatabase;
use Tests\TestCase;
use Throwable;

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
     * The migration refuses a violating database before it changes anything.
     *
     * `svc:schema:audit-tenant-fks` says the same thing earlier, but a gate that
     * lives in a deployment script is a gate somebody can deploy around, and the
     * cost here is specific: MariaDB commits each DDL statement on its own, so a
     * row discovered at the twentieth key leaves the first nineteen applied and
     * no transaction to roll back.
     *
     * Only `000200` is rolled back, since that is the migration the preflight
     * belongs to: `000000` and `000100` add a column and indexes and carry no
     * cross-tenant risk of their own. The row is written, `000200` is re-applied,
     * and it must throw having changed nothing.
     */
    public function test_the_migration_refuses_a_violating_database_before_touching_it(): void
    {
        $this->bootProbeDatabase(self::CONNECTION);

        $this->migrate();
        $this->rollback('2026_08_31_000200');
        $before = $this->fingerprint();

        $connection = DB::connection(self::CONNECTION);
        $home = $connection->table('workspaces')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Home', 'slug' => 'home',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreign = $connection->table('workspaces')->insertGetId([
            'public_id' => (string) Str::uuid(), 'name' => 'Foreign', 'slug' => 'foreign',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignCompany = $connection->table('client_companies')->insertGetId([
            'public_id' => (string) Str::uuid(), 'workspace_id' => $foreign, 'name' => 'Foreign Client',
            'slug' => 'foreign-client', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $connection->table('client_projects')->insert([
            'public_id' => (string) Str::uuid(), 'workspace_id' => $home, 'client_company_id' => $foreignCompany,
            'name' => 'Smuggled project', 'status' => 'active', 'is_visible_to_client' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->migrate();
            $this->fail('The migration must refuse a database holding a row its keys would reject.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString('Refusing to add composite tenant foreign keys', $exception->getMessage());
            // Counts only: the message reaches a deployment log.
            $this->assertStringNotContainsString('Smuggled project', $exception->getMessage());
            $this->assertStringNotContainsString('Foreign Client', $exception->getMessage());
        }

        $this->assertSame($before, $this->fingerprint(), 'The refused migration changed the schema anyway.');
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

    /** By name, not by a count of how many migrations happen to follow today. */
    private function rollback(string $migration = '2026_08_31_000000'): void
    {
        $this->rollbackProbeTo(self::CONNECTION, $migration);
    }
}
