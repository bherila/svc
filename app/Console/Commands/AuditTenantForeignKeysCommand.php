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
    protected $signature = 'svc:schema:audit-tenant-fks {--format=text : Output text or json}';

    protected $description = 'Count rows whose tenant-owned parent lives in another workspace';

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['text', 'json'], true)) {
            $this->error('The --format option must be text or json.');

            return self::INVALID;
        }

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
        }

        return $violations === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Rows whose parent is absent, or present in a different workspace.
     *
     * Returns null when the schema cannot answer the question yet - the child
     * column or its `workspace_id` has not been migrated in - which is reported
     * as `pending` rather than as a pass.
     */
    private function countViolations(TenantReference $reference): ?int
    {
        $child = $reference->childTable;
        $parent = $reference->parentTable;

        if (! Schema::hasTable($child) || ! Schema::hasTable($parent)) {
            return null;
        }

        if (! Schema::hasColumn($child, 'workspace_id') || ! Schema::hasColumn($child, $reference->childColumn)) {
            return null;
        }

        // The parent is aliased because one reference is self-referential
        // (client_time_entries.split_from_time_entry_id). Unaliased, both sides of
        // the correlation would bind to the subquery's own copy of the table, and
        // every row with a split parent would be counted as a violation - a check
        // that reports a number nobody can act on is worse than no check.
        return DB::table($child)
            ->whereNotNull($child.'.workspace_id')
            ->whereNotNull($child.'.'.$reference->childColumn)
            ->whereNotExists(function (Builder $query) use ($child, $parent, $reference): void {
                $query->selectRaw('1')
                    ->from($parent.' as tenant_parent')
                    ->whereColumn('tenant_parent.id', $child.'.'.$reference->childColumn)
                    ->whereColumn('tenant_parent.workspace_id', $child.'.workspace_id');
            })
            ->count();
    }
}
