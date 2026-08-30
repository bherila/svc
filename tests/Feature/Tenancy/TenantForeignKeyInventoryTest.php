<?php

namespace Tests\Feature\Tenancy;

use App\Support\Tenancy\TenantReference;
use App\Support\Tenancy\TenantReferenceInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The inventory and the schema have to keep agreeing.
 *
 * `TenantReferenceInventory` is what the audit command counts and what the
 * documentation explains, but it is a hand-written list, and a hand-written list
 * of a schema goes stale the first time someone adds a table. These assertions
 * are what make it a description of the database rather than a claim about it.
 *
 * The last case is the one that earns its keep: it walks the live schema looking
 * for tenant-owned columns nobody listed, so a new table arrives as a failure
 * here rather than as a cross-tenant defect two releases later.
 */
final class TenantForeignKeyInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_enforced_reference_is_a_real_composite_foreign_key(): void
    {
        $missing = [];

        foreach (TenantReferenceInventory::all() as $reference) {
            if (! $reference->enforced) {
                continue;
            }

            $found = false;

            foreach (Schema::getForeignKeys($reference->childTable) as $foreignKey) {
                $columns = array_map(strtolower(...), $foreignKey['columns']);
                $foreignColumns = array_map(strtolower(...), $foreignKey['foreign_columns']);

                if ($columns !== ['workspace_id', $reference->childColumn]) {
                    continue;
                }

                if (strtolower((string) $foreignKey['foreign_table']) !== $reference->parentTable) {
                    continue;
                }

                if ($foreignColumns !== ['workspace_id', 'id']) {
                    continue;
                }

                $found = true;
                break;
            }

            if (! $found) {
                $missing[] = $reference->label();
            }
        }

        $this->assertSame([], $missing, 'These references claim to be enforced but no composite foreign key implements them.');
    }

    /**
     * InnoDB can only serve a composite key from an index on the parent whose
     * leftmost columns are the referenced ones. SQLite needs the same uniqueness
     * for a different reason - it refuses a foreign key that does not name a
     * unique parent key at all.
     */
    public function test_every_referenced_parent_carries_the_unique_workspace_index(): void
    {
        foreach (TenantReferenceInventory::parentTables() as $parent) {
            $indexes = array_values(array_filter(
                Schema::getIndexes($parent),
                static fn (array $index): bool => $index['unique'] === true
                    && array_map(strtolower(...), $index['columns']) === ['workspace_id', 'id'],
            ));

            $this->assertNotSame([], $indexes, "{$parent} has no unique (workspace_id, id) index for a composite key to reference.");
        }
    }

    public function test_no_reference_names_a_column_the_schema_does_not_have(): void
    {
        foreach (TenantReferenceInventory::all() as $reference) {
            $this->assertTrue(Schema::hasTable($reference->childTable), "Unknown child table {$reference->childTable}.");
            $this->assertTrue(Schema::hasTable($reference->parentTable), "Unknown parent table {$reference->parentTable}.");
            $this->assertTrue(
                Schema::hasColumn($reference->childTable, $reference->childColumn),
                "{$reference->childTable} has no column {$reference->childColumn}.",
            );
            $this->assertTrue(
                Schema::hasColumn($reference->parentTable, 'workspace_id'),
                "{$reference->parentTable} is not tenant-owned and cannot be a composite parent.",
            );

            if (! $reference->enforced) {
                $this->assertNotSame('', $reference->note, $reference->label().' is exempt without a reason.');
            }
        }
    }

    /**
     * A tenant-owned column pointing at a tenant-owned table, absent from the
     * inventory, is either a missing composite key or a missing exemption. Both
     * are findings; neither should be discovered by a customer.
     */
    public function test_the_inventory_covers_every_tenant_owned_reference_in_the_schema(): void
    {
        $known = [];

        foreach (TenantReferenceInventory::all() as $reference) {
            $known[$reference->childTable.'.'.$reference->childColumn] = true;
        }

        $tenantTables = array_values(array_filter(
            array_map(static fn (array $table): string => $table['name'], Schema::getTables()),
            static fn (string $table): bool => Schema::hasColumn($table, 'workspace_id'),
        ));

        $unlisted = [];

        foreach ($tenantTables as $table) {
            foreach ($this->tenantReferencingColumns($table, $tenantTables) as $column => $parent) {
                if (! isset($known[$table.'.'.$column])) {
                    $unlisted[] = $table.'.'.$column.' -> '.$parent;
                }
            }
        }

        sort($unlisted);

        $this->assertSame(
            [],
            $unlisted,
            'These tenant-owned columns name a tenant-owned parent but are not in TenantReferenceInventory. '
            .'Add a composite key, or add an exemption with a reason.',
        );
    }

    /**
     * Columns of `$table` that reference another tenant-owned table.
     *
     * Two sources, because neither alone is complete. A declared foreign key
     * names its parent outright. A column with no foreign key at all - which is
     * how the invoice pivot could point at any time entry in the database - is
     * found by convention instead: `client_time_entry_id` names
     * `client_time_entries`.
     *
     * A self-referential column counts too. `split_from_time_entry_id` points at
     * `client_time_entries` from `client_time_entries`, and a lineage root in
     * another workspace is exactly as wrong as any other cross-tenant parent.
     * Skipping self-references would let the next one reach production without a
     * key or an audited exemption, and this one is only listed because it was
     * written out by hand. Only `workspace_id` is excluded, since that is the
     * tenant column itself rather than a reference to a tenant-owned row.
     *
     * @param  list<string>  $tenantTables
     * @return array<string, string>
     */
    private function tenantReferencingColumns(string $table, array $tenantTables): array
    {
        $found = [];

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (count($foreignKey['columns']) !== 1) {
                continue;
            }

            $parent = strtolower((string) $foreignKey['foreign_table']);
            $column = strtolower((string) $foreignKey['columns'][0]);

            if ($column === 'workspace_id' || ! in_array($parent, $tenantTables, true)) {
                continue;
            }

            $found[$column] = $parent;
        }

        foreach (Schema::getColumnListing($table) as $column) {
            if ($column === 'workspace_id' || ! str_ends_with($column, '_id')) {
                continue;
            }

            $parent = Str::plural(Str::beforeLast($column, '_id'));

            if (! in_array($parent, $tenantTables, true)) {
                continue;
            }

            $found[$column] = $parent;
        }

        return $found;
    }

    public function test_the_exemption_notes_name_the_engine_rule_they_rest_on(): void
    {
        foreach (TenantReferenceInventory::all() as $reference) {
            if ($reference->enforced) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/1830/',
                $reference->note,
                $reference->label().' is exempt without citing the InnoDB rule that makes the key inexpressible.',
            );
        }
    }

    public function test_an_enforced_reference_always_names_its_constraint(): void
    {
        foreach (TenantReferenceInventory::all() as $reference) {
            if ($reference->enforced) {
                $this->assertIsString($reference->constraintName, $reference->label().' is enforced but unnamed.');
                $this->assertLessThanOrEqual(
                    64,
                    strlen((string) $reference->constraintName),
                    'MariaDB refuses an identifier longer than 64 characters.',
                );

                continue;
            }

            $this->assertNull($reference->constraintName);
        }
    }

    public function test_constraint_names_are_unique_across_the_schema(): void
    {
        $names = array_values(array_filter(array_map(
            static fn (TenantReference $reference): ?string => $reference->constraintName,
            TenantReferenceInventory::all(),
        )));

        $this->assertSame(count($names), count(array_unique($names)), 'Foreign key names are schema-global in MariaDB.');
    }
}
