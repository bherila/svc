<?php

namespace App\Console\Commands\Billing;

use App\Services\ExternalImport\Fingerprint;
use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Restores columns an earlier import discarded, reading from the same guarded
 * read-only source the importer uses.
 *
 * This exists only to repair rows imported before those columns had anywhere to
 * go. Ordinary imports now map the same fields themselves, so a fresh
 * onboarding never needs this.
 *
 * It is a command rather than a migration because it needs an external database
 * to be reachable, and a fresh clone and CI must still be able to migrate.
 *
 * Safe to re-run: it only ever writes a column that is still empty, so it cannot
 * overwrite a value the destination has since decided, and a partial run simply
 * resumes.
 */
final class BackfillBillingLedgerCommand extends Command
{
    protected $signature = 'svc:billing:backfill-ledger
        {--source=external : Allowlisted read-only source key from config/external-import.php}
        {--workspace= : Restrict to ledger rows imported into one workspace public id}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Restore invoice, line, agreement, and task columns dropped by an earlier import';

    /** Legacy primary keys differ per table; the ledger records them as strings. */
    private const SOURCE_KEYS = [
        'client_invoices' => 'client_invoice_id',
        'client_invoice_lines' => 'client_invoice_line_id',
        'client_agreements' => 'id',
        'client_tasks' => 'id',
        'client_time_entries' => 'id',
    ];

    private string $destination;

    private ?int $workspaceId = null;

    public function handle(SourceGuard $guard): int
    {
        try {
            $source = $guard->resolve((string) $this->option('source'));
            $guard->assertDistinctFromDestination($source);
            $legacy = $guard->connection($source);
        } catch (SourceConfigurationException $e) {
            $this->components->error("Source unusable: {$e->getMessage()}");

            return self::FAILURE;
        }

        // The importer writes to the configured destination, which is not always
        // the default connection. Reading the ledger from anywhere else would
        // either find nothing or touch an unrelated database.
        $this->destination = (string) (Config::get('external-import.destination_connection') ?: Config::get('database.default'));

        if (is_string($workspacePublicId = $this->option('workspace')) && $workspacePublicId !== '') {
            $id = $this->db()->table('workspaces')->where('public_id', $workspacePublicId)->value('id');
            if ($id === null) {
                $this->components->error('No workspace matches that public id.');

                return self::FAILURE;
            }
            $this->workspaceId = (int) $id;
        }

        $dryRun = (bool) $this->option('dry-run');
        $identityHash = (string) $source['identity_hash'];
        $this->components->info($dryRun ? 'Dry run - nothing will be written.' : 'Backfilling from the external source.');

        $totals = [];
        foreach ([
            'invoices' => fn (): array => $this->backfillInvoices($legacy, $identityHash, $dryRun),
            'invoice lines' => fn (): array => $this->backfillInvoiceLines($legacy, $identityHash, $dryRun),
            'agreements' => fn (): array => $this->backfillAgreements($legacy, $identityHash, $dryRun),
            'tasks' => fn (): array => $this->backfillTasks($legacy, $identityHash, $dryRun),
            'time entries' => fn (): array => $this->backfillTimeEntries($legacy, $identityHash, $dryRun),
        ] as $label => $step) {
            $result = $step();
            $totals[$label] = $result;
            $this->components->twoColumnDetail(
                $label,
                sprintf('%d matched, %d %s', $result['matched'], $result['written'], $dryRun ? 'would change' : 'updated'),
            );
        }

        $unmatched = array_sum(array_column($totals, 'unmatched'));
        if ($unmatched > 0) {
            $this->components->warn("{$unmatched} source rows had no ledger mapping for this source and were skipped.");
        }

        $changed = array_sum(array_column($totals, 'changed'));
        if ($changed > 0) {
            $this->components->error(
                "{$changed} source rows no longer match the fingerprint recorded at import and were skipped. ".
                'Reconcile them through the importer before backfilling.'
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->destination);
    }

    /**
     * Legacy key -> destination row id and the fingerprint recorded at import.
     *
     * Scoped to one source identity: ledger rows from different external
     * databases can reuse the same primary keys, and keying on `source_key`
     * alone would let one source's mapping resolve to another's row.
     *
     * @return array<string, array{id:int, fingerprint:string}>
     */
    private function idMap(string $sourceTable, string $destinationTable, string $identityHash): array
    {
        $items = $this->db()->table('external_import_items')
            ->where('source_table', $sourceTable)
            ->where('source_identity_hash', $identityHash)
            ->where('status', 'imported')
            ->whereNotNull('target_public_id')
            ->when($this->workspaceId !== null, fn ($query) => $query->whereIn(
                'external_import_run_id',
                $this->db()->table('external_import_runs')->where('workspace_id', $this->workspaceId)->select('id'),
            ))
            ->get(['source_key', 'target_public_id', 'source_fingerprint']);

        if ($items->isEmpty()) {
            return [];
        }

        $internal = $this->db()->table($destinationTable)
            ->whereIn('public_id', $items->pluck('target_public_id')->all())
            ->when($this->workspaceId !== null, fn ($query) => $query->where('workspace_id', $this->workspaceId))
            ->pluck('id', 'public_id');

        $map = [];
        foreach ($items as $item) {
            $id = $internal->get($item->target_public_id);
            if ($id !== null) {
                $map[(string) $item->source_key] = [
                    'id' => (int) $id,
                    'fingerprint' => (string) $item->source_fingerprint,
                ];
            }
        }

        return $map;
    }

    /**
     * Resolves one source row to its destination id, refusing rows that drifted.
     *
     * The importer treats a fingerprint mismatch as `source_changed` and fails
     * the row. Copying current values onto a record built from an older snapshot
     * would splice two snapshots together inside a financial record, so this
     * refuses for the same reason.
     *
     * @param  array<string, array{id:int, fingerprint:string}>  $map
     * @param  array{matched:int,written:int,unmatched:int,changed:int}  $counters
     */
    private function resolve(array $map, object $row, string $key, array &$counters): ?int
    {
        $mapping = $map[(string) $row->{$key}] ?? null;
        if ($mapping === null) {
            $counters['unmatched']++;

            return null;
        }

        if (Fingerprint::row((array) $row) !== $mapping['fingerprint']) {
            $counters['changed']++;

            return null;
        }

        return $mapping['id'];
    }

    /**
     * Applies one row's worth of values, writing only columns that are still empty.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{matched:int,written:int,unmatched:int,changed:int}  $counters
     */
    private function applyRow(string $table, int $id, array $candidate, bool $dryRun, array &$counters): void
    {
        $current = $this->db()->table($table)->where('id', $id)->first(array_keys($candidate));
        if ($current === null) {
            $counters['unmatched']++;

            return;
        }

        $counters['matched']++;
        $changes = [];
        foreach ($candidate as $column => $value) {
            if ($value === null) {
                continue;
            }
            // Only ever fill a hole. A value the destination already holds is left
            // alone, so an operator's later correction survives a re-run.
            if (($current->{$column} ?? null) === null) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $counters['written']++;
        if (! $dryRun) {
            $this->db()->table($table)
                ->where('id', $id)
                ->when($this->workspaceId !== null, fn ($query) => $query->where('workspace_id', $this->workspaceId))
                ->update($changes);
        }
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function counters(): array
    {
        return ['matched' => 0, 'written' => 0, 'unmatched' => 0, 'changed' => 0];
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function backfillInvoices(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_invoices', 'client_invoices', $identityHash);
        $key = self::SOURCE_KEYS['client_invoices'];

        $legacy->table('client_invoices')->orderBy($key)->chunk(200, function ($rows) use ($map, $key, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $this->resolve($map, $row, $key, $counters);
                if ($id === null) {
                    continue;
                }

                $this->applyRow('client_invoices', $id, [
                    'invoice_kind' => $row->invoice_kind ?? null,
                    'cycle_start' => $this->date($row->cycle_start ?? null),
                    'cycle_end' => $this->date($row->cycle_end ?? null),
                    'paid_on' => $this->date($row->paid_date ?? null),
                    'retainer_hours_included' => $row->retainer_hours_included ?? null,
                    'hours_worked' => $row->hours_worked ?? null,
                    'rollover_hours_used' => $row->rollover_hours_used ?? null,
                    'unused_hours_balance' => $row->unused_hours_balance ?? null,
                    'negative_hours_balance' => $row->negative_hours_balance ?? null,
                    'hours_billed_at_rate' => $row->hours_billed_at_rate ?? null,
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function backfillInvoiceLines(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_invoice_lines', 'client_invoice_lines', $identityHash);
        $agreements = $this->idMap('client_agreements', 'client_agreements', $identityHash);
        $recurring = $this->idMap('client_agreement_recurring_items', 'client_agreement_recurring_items', $identityHash);
        $key = self::SOURCE_KEYS['client_invoice_lines'];

        $legacy->table('client_invoice_lines')->orderBy($key)
            ->chunk(500, function ($rows) use ($map, $agreements, $recurring, $key, $dryRun, &$counters): void {
                foreach ($rows as $row) {
                    $id = $this->resolve($map, $row, $key, $counters);
                    if ($id === null) {
                        continue;
                    }

                    $this->applyRow('client_invoice_lines', $id, [
                        'line_date' => $this->date($row->line_date ?? null),
                        'hours' => $row->hours ?? null,
                        'client_agreement_id' => $agreements[(string) ($row->client_agreement_id ?? '')]['id'] ?? null,
                        'client_agreement_recurring_item_id' => $recurring[(string) ($row->client_agreement_recurring_item_id ?? '')]['id'] ?? null,
                    ], $dryRun, $counters);
                }
            });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function backfillAgreements(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_agreements', 'client_agreements', $identityHash);

        $legacy->table('client_agreements')->orderBy('id')->chunk(200, function ($rows) use ($map, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $this->resolve($map, $row, 'id', $counters);
                if ($id === null) {
                    continue;
                }

                $this->applyRow('client_agreements', $id, [
                    // Hours here are whole or half hours in practice; minutes is exact
                    // for them and matches how the schema stores retainer_minutes.
                    'catch_up_threshold_minutes' => $this->minutes($row->catch_up_threshold_hours ?? null),
                    'period_retainer_minutes' => $this->minutes($row->retainer_hours ?? null),
                    'period_retainer_amount' => isset($row->retainer_fee) ? (int) round((float) $row->retainer_fee * 100) : null,
                    'rollover_months' => isset($row->rollover_months) ? (int) $row->rollover_months : null,
                    'initial_rollover_minutes' => $this->minutes($row->initial_rollover_hours ?? null),
                    // Nullable on purpose: an unset flag has to stay distinguishable
                    // from a deliberate false, or a re-run would revive a policy an
                    // operator had turned off.
                    'bill_overage_interim' => isset($row->bill_overage_interim)
                        ? (bool) $row->bill_overage_interim
                        : null,
                    'first_cycle_proration' => $row->first_cycle_proration ?? null,
                    'agreement_link' => $row->agreement_link ?? null,
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function backfillTasks(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_tasks', 'client_tasks', $identityHash);

        $legacy->table('client_tasks')->orderBy('id')->chunk(200, function ($rows) use ($map, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $this->resolve($map, $row, 'id', $counters);
                if ($id === null) {
                    continue;
                }

                $price = $row->milestone_price ?? null;
                $this->applyRow('client_tasks', $id, [
                    // Source is decimal currency; the schema is integer minor units.
                    'milestone_price_amount' => ($price === null || (float) $price <= 0.0)
                        ? null
                        : (int) round((float) $price * 100),
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int} */
    private function backfillTimeEntries(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_time_entries', 'client_time_entries', $identityHash);

        $legacy->table('client_time_entries')->orderBy('id')->chunk(500, function ($rows) use ($map, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $this->resolve($map, $row, 'id', $counters);
                if ($id === null) {
                    continue;
                }

                $this->applyRow('client_time_entries', $id, [
                    'job_type' => $row->job_type ?? null,
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '' || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function minutes(mixed $hours): ?int
    {
        if ($hours === null) {
            return null;
        }

        return (int) round((float) $hours * 60);
    }
}
