<?php

namespace App\Console\Commands\Billing;

use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Restores columns the external import discarded, reading from the same
 * guarded read-only source the importer uses.
 *
 * This is deliberately not an importer and not a migration:
 *
 * - Not an importer, because every row already exists here. Re-running the
 *   importer would contend with a system that has been live since the cutover;
 *   this only ever writes columns that are still empty, so it cannot overwrite
 *   anything SVC has since decided.
 * - Not a migration, because it needs an external database to be reachable.
 *   A fresh clone and CI must be able to migrate without one.
 *
 * Safe to re-run. Rows already filled are skipped, so a partial run simply
 * resumes.
 */
final class BackfillBillingLedgerCommand extends Command
{
    protected $signature = 'svc:billing:backfill-ledger
        {--source=external : Allowlisted read-only source key from config/external-import.php}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Restore invoice, line, agreement, and task columns dropped during the external import';

    /** Legacy primary keys differ per table; the ledger records them as strings. */
    private const SOURCE_KEYS = [
        'client_invoices' => 'client_invoice_id',
        'client_invoice_lines' => 'client_invoice_line_id',
        'client_agreements' => 'id',
        'client_tasks' => 'id',
    ];

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

        $dryRun = (bool) $this->option('dry-run');
        $this->components->info($dryRun ? 'Dry run - nothing will be written.' : 'Backfilling from the external source.');

        $totals = [];
        foreach ([
            'invoices' => fn (): array => $this->backfillInvoices($legacy, $dryRun),
            'invoice lines' => fn (): array => $this->backfillInvoiceLines($legacy, $dryRun),
            'agreements' => fn (): array => $this->backfillAgreements($legacy, $dryRun),
            'tasks' => fn (): array => $this->backfillTasks($legacy, $dryRun),
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
            $this->components->warn("{$unmatched} source rows had no ledger mapping and were skipped.");
        }

        return self::SUCCESS;
    }

    /**
     * Legacy key -> SVC internal id, via the import ledger's public-id mapping.
     *
     * @return array<string, int>
     */
    private function idMap(string $sourceTable, string $destinationTable): array
    {
        $publicIds = DB::table('external_import_items')
            ->where('source_table', $sourceTable)
            ->where('status', 'imported')
            ->whereNotNull('target_public_id')
            ->pluck('target_public_id', 'source_key');

        if ($publicIds->isEmpty()) {
            return [];
        }

        $internal = DB::table($destinationTable)
            ->whereIn('public_id', $publicIds->values()->all())
            ->pluck('id', 'public_id');

        $map = [];
        foreach ($publicIds as $sourceKey => $publicId) {
            $id = $internal->get($publicId);
            if ($id !== null) {
                $map[(string) $sourceKey] = (int) $id;
            }
        }

        return $map;
    }

    /**
     * Applies one row's worth of values, writing only columns that are still empty.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{matched:int,written:int,unmatched:int}  $counters
     */
    private function applyRow(string $table, int $id, array $candidate, bool $dryRun, array &$counters): void
    {
        $current = DB::table($table)->where('id', $id)->first(array_keys($candidate));
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
            // Only ever fill a hole. A value SVC already holds is left alone.
            if (($current->{$column} ?? null) === null) {
                $changes[$column] = $value;
            }
        }

        if ($changes === []) {
            return;
        }

        $counters['written']++;
        if (! $dryRun) {
            DB::table($table)->where('id', $id)->update($changes);
        }
    }

    /** @return array{matched:int,written:int,unmatched:int} */
    private function backfillInvoices(ConnectionInterface $legacy, bool $dryRun): array
    {
        $counters = ['matched' => 0, 'written' => 0, 'unmatched' => 0];
        $map = $this->idMap('client_invoices', 'client_invoices');
        $key = self::SOURCE_KEYS['client_invoices'];

        $legacy->table('client_invoices')->orderBy($key)->chunk(200, function ($rows) use ($map, $key, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $map[(string) $row->{$key}] ?? null;
                if ($id === null) {
                    $counters['unmatched']++;

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

    /** @return array{matched:int,written:int,unmatched:int} */
    private function backfillInvoiceLines(ConnectionInterface $legacy, bool $dryRun): array
    {
        $counters = ['matched' => 0, 'written' => 0, 'unmatched' => 0];
        $map = $this->idMap('client_invoice_lines', 'client_invoice_lines');
        $agreements = $this->idMap('client_agreements', 'client_agreements');
        $recurring = $this->idMap('client_agreement_recurring_items', 'client_agreement_recurring_items');
        $key = self::SOURCE_KEYS['client_invoice_lines'];

        $legacy->table('client_invoice_lines')->orderBy($key)
            ->chunk(500, function ($rows) use ($map, $agreements, $recurring, $key, $dryRun, &$counters): void {
                foreach ($rows as $row) {
                    $id = $map[(string) $row->{$key}] ?? null;
                    if ($id === null) {
                        $counters['unmatched']++;

                        continue;
                    }

                    $this->applyRow('client_invoice_lines', $id, [
                        'line_date' => $this->date($row->line_date ?? null),
                        'hours' => $row->hours ?? null,
                        'client_agreement_id' => $agreements[(string) ($row->client_agreement_id ?? '')] ?? null,
                        'client_agreement_recurring_item_id' => $recurring[(string) ($row->client_agreement_recurring_item_id ?? '')] ?? null,
                    ], $dryRun, $counters);
                }
            });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int} */
    private function backfillAgreements(ConnectionInterface $legacy, bool $dryRun): array
    {
        $counters = ['matched' => 0, 'written' => 0, 'unmatched' => 0];
        $map = $this->idMap('client_agreements', 'client_agreements');

        $legacy->table('client_agreements')->orderBy('id')->chunk(200, function ($rows) use ($map, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $map[(string) $row->id] ?? null;
                if ($id === null) {
                    $counters['unmatched']++;

                    continue;
                }

                $this->applyRow('client_agreements', $id, [
                    // Hours here are whole or half hours in practice; minutes is exact for them
                    // and matches how SVC stores retainer_minutes.
                    'catch_up_threshold_minutes' => $this->minutes($row->catch_up_threshold_hours ?? null),
                    'rollover_months' => isset($row->rollover_months) ? (int) $row->rollover_months : null,
                    'initial_rollover_minutes' => $this->minutes($row->initial_rollover_hours ?? null),
                    'first_cycle_proration' => $row->first_cycle_proration ?? null,
                    'agreement_link' => $row->agreement_link ?? null,
                ], $dryRun, $counters);

                // Boolean with a non-null default: applyRow's "only fill a hole" rule
                // cannot see it as empty, so set it explicitly when the source says true.
                if (! $dryRun && (bool) ($row->bill_overage_interim ?? false)) {
                    DB::table('client_agreements')->where('id', $id)->update(['bill_overage_interim' => true]);
                }
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int} */
    private function backfillTasks(ConnectionInterface $legacy, bool $dryRun): array
    {
        $counters = ['matched' => 0, 'written' => 0, 'unmatched' => 0];
        $map = $this->idMap('client_tasks', 'client_tasks');

        $legacy->table('client_tasks')->orderBy('id')->chunk(200, function ($rows) use ($map, $dryRun, &$counters): void {
            foreach ($rows as $row) {
                $id = $map[(string) $row->id] ?? null;
                if ($id === null) {
                    $counters['unmatched']++;

                    continue;
                }

                $price = $row->milestone_price ?? null;
                $this->applyRow('client_tasks', $id, [
                    // Source is decimal currency; SVC is integer minor units throughout.
                    'milestone_price_amount' => ($price === null || (float) $price <= 0.0)
                        ? null
                        : (int) round((float) $price * 100),
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
