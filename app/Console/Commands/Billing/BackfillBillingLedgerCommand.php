<?php

namespace App\Console\Commands\Billing;

use App\Services\ExternalImport\Fingerprint;
use App\Services\ExternalImport\RestoreAgreementVerifier;
use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SourceGuard;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
 *
 * Unlike the replay and the rehearsal, this one does write - that is its
 * purpose. It writes only under `--apply`, and only if every check passes: the
 * whole repair is one transaction, so a source it decides not to trust leaves
 * the ledger exactly as it found it.
 */
final class BackfillBillingLedgerCommand extends Command
{
    protected $signature = 'svc:billing:backfill-ledger
        {--source=external : Allowlisted read-only source key from config/external-import.php}
        {--workspace= : Required. Ledger rows imported into this workspace public id, and no other}
        {--apply : Write the repairs. Without it the command reports what would change and writes nothing}
        {--accept-drift= : Comma-separated destination columns allowed to differ from the source, for a declared restore that kept being used}';

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

    /** The runtime name the guard gave the source, for schema questions. */
    private string $sourceConnection;

    private int $workspaceId;

    /**
     * A declared restore is verified column by column instead.
     *
     * The whole-row hash cannot distinguish a renumbering from a rewrite, so
     * against a source that kept being used it rejects everything. The column
     * comparison answers the same question with names attached, and runs once
     * up front rather than per row.
     */
    private bool $skipRowFingerprint = false;

    public function handle(SourceGuard $guard): int
    {
        try {
            $source = $guard->resolve((string) $this->option('source'));
            $guard->assertDistinctFromDestination($source);
            $legacy = $guard->connection($source);
            $this->sourceConnection = $guard->runtimeName($source);
        } catch (SourceConfigurationException $e) {
            $this->components->error("Source unusable: {$e->getMessage()}");

            return self::FAILURE;
        }

        // The importer writes to the configured destination, which is not always
        // the default connection. Reading the ledger from anywhere else would
        // either find nothing or touch an unrelated database.
        $this->destination = (string) (Config::get('external-import.destination_connection') ?: Config::get('database.default'));

        // Required, not defaulted. Omitting it previously dropped every
        // workspace predicate below, so a repair aimed at one onboarding would
        // walk every tenant imported from the same source.
        $workspacePublicId = $this->option('workspace');
        if (! is_string($workspacePublicId) || $workspacePublicId === '') {
            $this->components->error('--workspace is required; this command writes billing data and must name its tenant.');

            return self::FAILURE;
        }

        $id = $this->db()->table('workspaces')->where('public_id', $workspacePublicId)->value('id');
        if ($id === null) {
            $this->components->error('No workspace matches that public id.');

            return self::FAILURE;
        }
        $this->workspaceId = (int) $id;

        // Reporting is the default and writing is the flag, not the other way
        // round. This command points at production data, and a run that was
        // meant to be a look should not become a write because an operator
        // forgot an option.
        $dryRun = ! (bool) $this->option('apply');
        $identityHash = (string) $source['identity_hash'];
        $this->components->info($dryRun
            ? 'Reporting only - nothing will be written. Pass --apply to write.'
            : 'Backfilling from the external source.');

        // Say it plainly when the source is standing in for another. An
        // operator reading this output should never have to check the
        // environment to learn which database the rows are being matched to.
        // SourceGuard normalises an empty declaration to null, so presence is
        // the whole test.
        $declaredRestore = $source['declared_restore_of'] ?? null;
        if ($declaredRestore !== null) {
            $this->components->warn(sprintf(
                'Reading %s, declared a restore of %s. Ledger rows are matched as if they came from %s, '.
                'and the restore is compared column by column against what the importer wrote.',
                (string) ($source['config']['database'] ?? '?'),
                $declaredRestore,
                $declaredRestore,
            ));
        }

        if ($declaredRestore !== null) {
            $verdict = $this->verifyRestore($legacy, $identityHash);
            if ($verdict !== self::SUCCESS) {
                return $verdict;
            }
        }

        // One transaction over every table. The unmatched and fingerprint
        // checks below can only be answered after the whole source has been
        // walked, and both of them mean "do not repair this ledger" - so the
        // writes have to still be undoable when they are asked. Without this
        // the command committed the tables it had finished, then returned
        // failure, leaving a ledger half repaired from a source it had just
        // decided not to trust.
        $this->db()->beginTransaction();

        try {
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

            $verdict = $this->verdictFor($totals, $declaredRestore);
        } catch (Throwable $e) {
            $this->db()->rollBack();

            throw $e;
        }

        if ($dryRun || $verdict !== self::SUCCESS) {
            $this->db()->rollBack();

            return $verdict;
        }

        $this->db()->commit();

        return self::SUCCESS;
    }

    /**
     * Whether what was found justifies keeping the repairs.
     *
     * @param  array<string, array{matched:int, written:int, unmatched:int, changed:int, deferred:int, unresolved:int}>  $totals
     */
    private function verdictFor(array $totals, ?string $declaredRestore): int
    {

        $unmatched = array_sum(array_column($totals, 'unmatched'));
        if ($unmatched > 0) {
            // A partial source is ordinary - an onboarding may import a subset.
            // A partial *restore* is not: declaring one asserts it holds the
            // rows the ledger recorded, so a gap means the declaration is
            // wrong, and repairing half a ledger from it would be worse than
            // repairing none.
            if ($declaredRestore !== null) {
                $this->components->error(
                    "{$unmatched} source rows had no ledger mapping. A database declared a restore of ".
                    "{$declaredRestore} must contain every row the ledger recorded; this one does not, ".
                    'so the declaration does not hold.'
                );

                return self::FAILURE;
            }

            $this->components->warn("{$unmatched} source rows had no ledger mapping for this source and were skipped.");
        }

        $unresolved = array_sum(array_column($totals, 'unresolved'));
        if ($unresolved > 0) {
            $this->components->error(
                "{$unresolved} rows name a billing link this repair could not resolve. Leaving those milestones ".
                'unlinked would let the next generation run charge for them again, so nothing here is reported as repaired.'
            );

            return self::FAILURE;
        }

        $deferred = array_sum(array_column($totals, 'deferred'));
        if ($deferred > 0) {
            $this->components->warn(
                "{$deferred} rows were filled by something else between this command's read and its write, and were left alone. ".
                'Run again to pick up whatever is still empty.'
            );
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
            ->whereIn(
                'external_import_run_id',
                $this->db()->table('external_import_runs')->where('workspace_id', $this->workspaceId)->select('id'),
            )
            ->get(['source_key', 'target_public_id', 'source_fingerprint']);

        if ($items->isEmpty()) {
            return [];
        }

        $internal = $this->db()->table($destinationTable)
            ->whereIn('public_id', $items->pluck('target_public_id')->all())
            ->where('workspace_id', $this->workspaceId)
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
     * @param  array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int}  $counters
     */
    private function resolve(array $map, object $row, string $key, array &$counters, bool $requireFingerprint = false): ?int
    {
        $mapping = $map[(string) $row->{$key}] ?? null;
        if ($mapping === null) {
            $counters['unmatched']++;

            return null;
        }

        // A verified restore switches the whole-row fingerprint off, because
        // verification has already compared the columns that carry source data
        // and named the drift it accepts. It cannot speak for a remapped
        // foreign key - those are deliberately outside what it compares - so a
        // caller about to write a billing relationship asks for the check back.
        if (($requireFingerprint || ! $this->skipRowFingerprint) && Fingerprint::row((array) $row) !== $mapping['fingerprint']) {
            $counters['changed']++;

            return null;
        }

        return $mapping['id'];
    }

    /**
     * Applies one row's worth of values, writing only columns that are still empty.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int}  $counters
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

        if ($dryRun) {
            $counters['written']++;

            return;
        }

        $query = $this->db()->table($table)
            ->where('id', $id)
            ->where('workspace_id', $this->workspaceId);

        // Fill-only has to be decided in the write, not in the read above.
        // Between the two, generation can fill one of these columns - a
        // milestone's invoice line, say - and an unconditional update would
        // replace that billing decision with a stale value from the source.
        foreach (array_keys($changes) as $column) {
            $query->whereNull($column);
        }

        // A unique column among these can be taken between the read that
        // cleared it and this write - task_invoice_line_once is the one that
        // can, and it is global, so no predicate here can hold it. Losing that
        // race means the same thing the reader would have found a moment
        // later: the line is spoken for and this row is not the repair's to
        // make.
        // Inside a savepoint, because on some engines a failed statement
        // poisons the surrounding transaction: catching the violation would
        // leave every later repair in this run writing into an aborted one.
        // Nesting a transaction here is what Laravel turns into a savepoint.
        //
        // Only for the one column that can collide. Every other repair here
        // writes something no constraint contests, and a savepoint per row
        // across a whole ledger is a cost with nothing behind it.
        if (! array_key_exists('client_invoice_line_id', $changes)) {
            $written = $query->update($changes);
        } else {
            try {
                $written = $this->db()->transaction(static fn (): int => $query->update($changes));
            } catch (UniqueConstraintViolationException) {
                $counters['unresolved']++;

                return;
            }
        }

        if ($written === 0) {
            // Someone filled one of them between the read and this write. Leave
            // it alone and record it as deferred rather than changed - a
            // fingerprint mismatch means the source moved and must block, and
            // this is neither of those.
            $counters['deferred']++;

            return;
        }

        $counters['written']++;
    }

    /**
     * Whether the source records which line billed a milestone at all.
     *
     * Asked of the schema rather than by running a query and reading its
     * failure: any database trouble at all looked like a missing column that
     * way, and answering "no claims here" to a dropped connection would skip
     * the contested-claim prepass and let an ambiguous line be committed.
     */
    private function sourceRecordsTaskLinks(string $sourceConnection): bool
    {
        return Schema::connection($sourceConnection)->hasColumn('client_tasks', 'client_invoice_line_id');
    }

    /**
     * Compare the declared restore against what the importer wrote, and refuse
     * unless every difference has been named.
     */
    private function verifyRestore(ConnectionInterface $legacy, string $identityHash): int
    {
        // Accepted entries are `table.column`. A bare column name is refused
        // rather than guessed at: it would have to mean "on every table", which
        // is exactly the over-acceptance this qualification exists to prevent.
        $accepted = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) ($this->option('accept-drift') ?? '')),
        ), static fn (string $c): bool => $c !== ''));

        $unqualified = array_values(array_filter($accepted, static fn (string $c): bool => ! str_contains($c, '.')));
        if ($unqualified !== []) {
            $this->components->error(sprintf(
                'Name each accepted difference as table.column: %s. A bare column name would waive that '.
                'column on every table, including ones carrying money.',
                implode(', ', $unqualified),
            ));

            return self::FAILURE;
        }

        $verifier = new RestoreAgreementVerifier;
        $drift = [];
        $compared = 0;
        $missing = [];

        foreach (RestoreAgreementVerifier::comparableColumns() as $table => $_) {
            $idMap = array_map(static fn (array $m): int => $m['id'], $this->idMap($table, $table, $identityHash));
            if ($idMap === []) {
                // The ledger recorded nothing from this table, so there is
                // nothing to compare and no reason to touch it in the source.
                continue;
            }

            $result = $verifier->verify(
                $legacy,
                $table,
                self::SOURCE_KEYS[$table],
                $table,
                $this->destination,
                $idMap,
            );
            $compared += $result['compared'];
            if ($result['missing'] > 0) {
                $missing[$table] = $result['missing'];
            }

            foreach ($result['drift'] as $column => $count) {
                // Qualified by table. `total_amount` exists on invoices and on
                // invoice lines, `description` on lines and on time entries, so
                // a flat map let a harmless accepted drift on one table waive a
                // money column on another - and this is the only gate between a
                // rewritten source and the backfill.
                $drift["{$table}.{$column}"] = ($drift["{$table}.{$column}"] ?? 0) + $count;
            }
        }

        $this->components->twoColumnDetail('rows verified against the import', (string) $compared);

        if ($missing !== []) {
            foreach ($missing as $table => $count) {
                $this->components->twoColumnDetail("  {$table}", sprintf('%d imported row(s) absent from the restore', $count));
            }

            $this->components->error(
                'The restore is missing rows the ledger says were imported from this source. It is not the '.
                'same data, so nothing it agrees with proves anything; restore the full source and run again.',
            );

            return self::FAILURE;
        }

        $unexpected = array_diff_key($drift, array_flip($accepted));

        foreach ($drift as $column => $count) {
            $this->components->twoColumnDetail(
                "  {$column}",
                sprintf('%d row(s) differ%s', $count, in_array($column, $accepted, true) ? ' - accepted' : ''),
            );
        }

        if ($unexpected !== []) {
            $this->components->error(sprintf(
                'The restore no longer agrees with what was imported, in %s. Money and dates are '.
                'compared here, so review these before deciding. If the change is intended, re-run with '.
                '--accept-drift=%s.',
                implode(', ', array_keys($unexpected)),
                implode(',', array_keys($unexpected)),
            ));

            return self::FAILURE;
        }

        if ($drift === []) {
            $this->components->info('The restore matches what was imported on every compared column.');
        }

        // Verified by comparison; the whole-row hash would now only re-reject
        // the drift that has just been examined and accepted.
        $this->skipRowFingerprint = true;

        return self::SUCCESS;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
    private function counters(): array
    {
        return ['matched' => 0, 'written' => 0, 'unmatched' => 0, 'changed' => 0, 'deferred' => 0, 'unresolved' => 0];
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
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
                    'starting_unused_hours' => $row->starting_unused_hours ?? null,
                    'starting_negative_hours' => $row->starting_negative_hours ?? null,
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
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

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
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

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
    private function backfillTasks(ConnectionInterface $legacy, string $identityHash, bool $dryRun): array
    {
        $counters = $this->counters();
        $map = $this->idMap('client_tasks', 'client_tasks', $identityHash);
        $lines = $this->idMap('client_invoice_lines', 'client_invoice_lines', $identityHash);

        // Which lines more than one source task claims. A milestone line bills
        // one deliverable, and the schema now says so, so applying these row by
        // row would give the line to whichever task came first and then take
        // the constraint violation on the next - failing a repair that has
        // nothing wrong with it except the rows it cannot decide between.
        // Which of this ledger's lines more than one source task claims.
        //
        // Pivoted on the line rather than on the claimant. Scoping by which
        // tasks the ledger mapped looked right and was not: a task deleted
        // before the original import has no mapping and still argues over the
        // line, and it is the line being ours that makes the argument ours.
        // Another tenant's tasks fighting over another tenant's line never
        // reach this, because that line is not in the map.
        //
        // Read only when the source records claims at all: a table without the
        // column is a schema the row loop below tolerates, and a prepass that
        // queried it regardless would fail the whole command instead.
        $contested = [];
        if ($lines !== [] && $this->sourceRecordsTaskLinks($this->sourceConnection)) {
            // Chunked, because this binds one placeholder per mapped line and
            // a workspace with a long invoice history has more of them than a
            // driver will accept in one statement - the task pass below is
            // chunked for the same reason.
            foreach (array_chunk(array_keys($lines), 500) as $chunk) {
                foreach ($legacy->table('client_tasks')
                    ->selectRaw('client_invoice_line_id as line_key, count(*) as claims')
                    ->whereIn('client_invoice_line_id', $chunk)
                    ->groupBy('client_invoice_line_id')
                    ->havingRaw('count(*) > 1')
                    ->get() as $row) {
                    $contested[(string) $row->line_key] = (int) $row->claims;
                }
            }
        }

        $legacy->table('client_tasks')->orderBy('id')->chunk(200, function ($rows) use ($map, $lines, $dryRun, $contested, &$counters): void {
            foreach ($rows as $row) {
                $sourceLink = $row->client_invoice_line_id ?? null;

                // Writing which line billed a milestone is writing a financial
                // relationship, so this row has to be the row that was
                // imported - not one a restore's accepted drift covered for.
                $id = $this->resolve($map, $row, 'id', $counters, $sourceLink !== null);
                if ($id === null) {
                    continue;
                }

                $resolvedLink = $sourceLink === null ? null : ($lines[(string) $sourceLink]['id'] ?? null);

                // Nothing here says which of the claimants the line billed, so
                // neither gets it and both are reported - but only where this
                // task has a hole to fill. A task already carrying an
                // operator's correction is not competing for anything, and
                // refusing costs the milestone price applyRow() could still
                // restore without touching that link.
                // Only asked when the source names a line. Nothing that
                // consumes this runs otherwise, and applyRow() asks the same
                // question again in the write - so for the many tasks with no
                // claim at all this was a query per row for nothing.
                //
                // Workspace-scoped, not left implicit in the mapping that
                // produced the id: this is a tenant-owned read, and every one
                // of them says so on its own.
                $fillsItsLink = $sourceLink !== null && $this->db()->table('client_tasks')
                    ->where('workspace_id', $this->workspaceId)
                    ->where('id', $id)
                    ->whereNull('client_invoice_line_id')
                    ->exists();

                if ($sourceLink !== null && $fillsItsLink && isset($contested[(string) $sourceLink])) {
                    $counters['unresolved']++;

                    continue;
                }

                // Or already held. An operator can have reconciled that line
                // by hand, and the source knowing nothing about it does not
                // make the line free.
                //
                // Asked with the constraint's own scope rather than this
                // workspace's: task_invoice_line_once is global, so a holder in
                // another workspace would collide just the same, and a check
                // narrower than the rule it predicts is not a prediction. It
                // reads whether a line is spoken for and nothing else.
                //
                // Only where this task has no link of its own. If it already
                // has one, applyRow() fills holes and would leave it alone, so
                // there is no conflicting write to head off - and refusing
                // would cost the milestone price it could still repair.
                //
                // A reader, so it can still be overtaken - an operator or a
                // generation run can take the line between this and the write.
                // applyRow() reports that collision rather than letting it
                // escape, so the two together cover both orderings.
                //
                // This workspace's tasks only, though the index it stands in
                // for is global. A holder in another workspace is malformed and
                // must still stop the write, but task_invoice_line_once stops
                // it on its own and applyRow() records the violation as the
                // same unresolved - so the answer does not change, and reading
                // another tenant's row to reach it would be a cost with no
                // difference behind it.
                if ($resolvedLink !== null && $fillsItsLink
                    && $this->db()->table('client_tasks')
                        ->where('workspace_id', $this->workspaceId)
                        ->where('client_invoice_line_id', $resolvedLink)
                        ->where('id', '!=', $id)
                        ->exists()) {
                    $counters['unresolved']++;

                    continue;
                }

                // The source says this milestone was billed and this repair
                // cannot say by what. Writing null would leave it reading as
                // unbilled, which is how it gets charged a second time - so the
                // repair reports rather than quietly filling in nothing.
                //
                // Unless the destination already holds a link. Then there is no
                // hole to fill, nothing can be double-billed, and failing the
                // whole repair over a line this workspace never imported would
                // roll back every other row for no gain.
                if ($sourceLink !== null && $resolvedLink === null
                    && $this->db()->table('client_tasks')
                        ->where('id', $id)
                        ->where('workspace_id', $this->workspaceId)
                        ->whereNull('client_invoice_line_id')
                        ->exists()) {
                    $counters['unresolved']++;

                    continue;
                }

                $price = $row->milestone_price ?? null;
                $this->applyRow('client_tasks', $id, [
                    // Source is decimal currency; the schema is integer minor units.
                    'milestone_price_amount' => ($price === null || (float) $price <= 0.0)
                        ? null
                        : (int) round((float) $price * 100),
                    // Which line billed this milestone. A task that arrived
                    // without it reads as unbilled, and the next generation run
                    // charges the client for the same deliverable again.
                    'client_invoice_line_id' => $resolvedLink,
                ], $dryRun, $counters);
            }
        });

        return $counters;
    }

    /** @return array{matched:int,written:int,unmatched:int,changed:int,deferred:int,unresolved:int} */
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
