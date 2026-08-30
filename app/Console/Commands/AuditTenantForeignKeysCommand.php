<?php

namespace App\Console\Commands;

use App\Support\Tenancy\TenantReference;
use App\Support\Tenancy\TenantReferenceInventory;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-migration gate for the composite tenant foreign keys.
 *
 * Run this before `migrate` in any environment holding real data. Every row it
 * counts is a row the composite key would refuse, so a non-zero count here is a
 * migration that will abort partway rather than a report to read later.
 *
 * It prints counts and schema identifiers only - never a row, an id, a name, or
 * a workspace. The whole point is that it is safe to run against a database of
 * client and billing records and to paste the output into an issue.
 */
final class AuditTenantForeignKeysCommand extends Command
{
    protected $signature = 'svc:schema:audit-tenant-fks
        {--format=text : Output text or json}
        {--allow-pending : Exit successfully even when some references could not be inspected}';

    protected $description = 'Count rows whose tenant-owned parent lives in another workspace';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

        $allowPending = (bool) $this->option('allow-pending');

        $rows = [];
        $violations = 0;
        $pending = 0;

        foreach (TenantReferenceInventory::all() as $reference) {
            $count = $this->countViolations($reference);

            if ($count === null) {
                $pending++;
                $rows[] = [
                    'reference' => $reference->label(),
                    'status' => 'pending',
                    'violations' => null,
                ];

                continue;
            }

            $violations += $count;
            $rows[] = [
                'reference' => $reference->label(),
                'status' => $reference->enforced ? 'enforced' : 'exempt',
                'violations' => $count,
            ];
        }

        $summary = [
            'references' => count($rows),
            'pending' => $pending,
            'violating_references' => count(array_filter($rows, static fn (array $row): bool => ($row['violations'] ?? 0) > 0)),
            'violating_rows' => $violations,
        ];

        if ($format === 'json') {
            $this->line((string) json_encode(['summary' => $summary, 'references' => $rows], JSON_THROW_ON_ERROR));
        } else {
            foreach ($rows as $row) {
                $this->components->twoColumnDetail(
                    $row['reference'].' <fg=gray>('.$row['status'].')</>',
                    $row['violations'] === null ? 'not yet migrated' : (string) $row['violations'],
                );
            }

            $this->newLine();
            $this->components->twoColumnDetail('References checked', (string) $summary['references']);
            $this->components->twoColumnDetail('References not yet migrated', (string) $summary['pending']);
            $this->components->twoColumnDetail('References with violations', (string) $summary['violating_references']);
            $this->components->twoColumnDetail('Violating rows', (string) $summary['violating_rows']);

            if ($pending > 0 && ! $allowPending) {
                $this->newLine();
                $this->components->error(
                    "{$pending} reference(s) could not be inspected on this schema. Migrate first, or pass --allow-pending if this run is deliberately checking a pre-migration database."
                );
            }
        }

        // A check that cannot tell "passed" from "did not run" has to fail. A
        // reference reported as pending is one the schema could not answer, so
        // a clean exit here would tell a deployment that a schema nobody
        // finished inspecting is safe to migrate.
        //
        // --allow-pending is for the one legitimate case: running the audit
        // against a database that has not had 2026_08_31_000000 applied yet, to
        // see what the rest of the schema looks like before scheduling the
        // migration. It is never right in a deployment gate.
        if ($pending > 0 && ! $allowPending) {
            return self::FAILURE;
        }

        return $violations === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Rows whose tenant ownership disagrees with the parent they name.
     *
     * Three shapes count, and the third is the one that is easy to miss:
     *
     * 1. The parent is in a different workspace.
     * 2. The parent is absent — except on a lineage column, where the named row
     *    is allowed to be deleted and the invoice is meant to outlive it. Counting
     *    that would leave the audit permanently red after a deletion the schema
     *    explicitly permits, and a gate that cannot be cleared gets waved through.
     * 3. The parent is populated while the child's own `workspace_id` is null. Only
     *    `stripe_payment_method_states` can be in that state, and only because a
     *    payment-method event can arrive before anything here knows the tenant —
     *    but that is the *all-null* case. A row naming a company while claiming no
     *    workspace is a row whose ownership nothing can decide, and since these
     *    references carry no composite key, this audit is their only detector.
     *
     * Returns null when the schema cannot answer the question yet — the child
     * column or its `workspace_id` has not been migrated in — which is reported as
     * `pending` and, unless `--allow-pending`, fails the run.
     */
    private function countViolations(TenantReference $reference): ?int
    {
        $child = $reference->childTable;
        $parent = $reference->parentTable;

        if (! Schema::hasTable($child) || ! Schema::hasTable($parent)) {
            return null;
        }

        // The parent's own `workspace_id` matters as much as the child's: a
        // pre-migration schema where `client_company_memberships` has no tenant
        // column cannot answer the question about
        // `client_portal_project_access.client_company_membership_id` either, and
        // asking anyway is an error rather than a count.
        if (! Schema::hasColumn($child, 'workspace_id') || ! Schema::hasColumn($child, $reference->childColumn)) {
            return null;
        }

        if (! Schema::hasColumn($parent, 'workspace_id')) {
            return null;
        }

        $column = $child.'.'.$reference->childColumn;

        return DB::table($child)
            ->whereNotNull($column)
            ->where(function (Builder $row) use ($child, $column, $parent, $reference): void {
                // Shape 3: a named parent with no workspace of its own.
                $row->whereNull($child.'.workspace_id')
                    ->orWhere(function (Builder $mismatched) use ($child, $column, $parent, $reference): void {
                        $mismatched->whereNotNull($child.'.workspace_id');

                        // The parent is aliased because one reference is
                        // self-referential (split_from_time_entry_id). Unaliased,
                        // both sides of the correlation bind to the subquery's own
                        // copy of the table and every split row reads as a
                        // violation.
                        if ($reference->parentMayBeAbsent) {
                            // Shape 1 only: an existing parent in the wrong place.
                            $mismatched->whereExists(function (Builder $query) use ($child, $column, $parent): void {
                                $query->selectRaw('1')
                                    ->from($parent.' as tenant_parent')
                                    ->whereColumn('tenant_parent.id', $column)
                                    ->whereColumn('tenant_parent.workspace_id', '!=', $child.'.workspace_id');
                            });

                            return;
                        }

                        // Shapes 1 and 2: no parent agrees with this row.
                        $mismatched->whereNotExists(function (Builder $query) use ($child, $column, $parent): void {
                            $query->selectRaw('1')
                                ->from($parent.' as tenant_parent')
                                ->whereColumn('tenant_parent.id', $column)
                                ->whereColumn('tenant_parent.workspace_id', $child.'.workspace_id');
                        });
                    });
            })
            ->count();
    }
}
