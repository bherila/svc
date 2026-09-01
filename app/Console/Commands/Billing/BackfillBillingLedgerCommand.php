<?php

namespace App\Console\Commands\Billing;

use App\Services\ExternalImport\Fingerprint;
use App\Services\ExternalImport\RestoreAgreementVerifier;
use App\Services\ExternalImport\SourceConfigurationException;
use App\Services\ExternalImport\SourceGuard;
use App\Support\Billing\InvoiceLineType;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
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
        {--accept-drift= : Comma-separated destination columns allowed to differ from the source, for a declared restore that kept being used}
        {--skip-table=* : Source tables to leave entirely alone, by name. Their rows are neither repaired nor allowed to fail the run}';

    protected $description = 'Restore invoice, line, agreement, and task columns dropped by an earlier import';

    /** Legacy primary keys differ per table; the ledger records them as strings. */
    /**
     * Tables SVC itself removes rows from in ordinary use.
     *
     * Editing a draft invoice hard-deletes its lines and recreates them
     * (InvoiceLifecycleService::update), so a ledgered line legitimately stops
     * resolving. Nothing else here removes an imported row, so one that no
     * longer resolves means something is wrong rather than something happened.
     *
     * Time entries are deliberately not on this list even though they soft
     * delete. The lookup below goes through the query builder rather than the
     * model, so no global scope applies and a soft-deleted row resolves like
     * any other - it never needs the waiver, and granting it anyway would waive
     * the case the waiver is not for: an entry that is genuinely gone.
     *
     * @var list<string>
     */
    private const DELETED_IN_ORDINARY_USE = [
        'client_invoice_lines',
    ];

    /**
     * What a line's type is taken to be when the source does not record one.
     *
     * The importer reads such a line as an adjustment, and the restore verifier
     * agrees, so this reads it the same way rather than inventing a third
     * answer.
     */
    private const UNTYPED_SOURCE_LINE = 'adjustment';

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

    /**
     * Source tables this run will not read at all.
     *
     * A property rather than a parameter because *every* stage has to honour
     * it, preflight included. Threading it through only the repair loop was the
     * first version, and it left the option promising more than it delivered:
     * restore verification and ledger resolution both walk `SOURCE_KEYS`
     * unconditionally, so a problem in a skipped table still rolled back every
     * other table's repairs.
     *
     * @var list<string>
     */
    private array $skippedTables = [];

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
        // Parsed before anything reads the source, because the preflight
        // stages honour it too. A bad table name has to stop the run here,
        // rather than after a verification that was already narrowed by it.
        $skipped = $this->skippedTables();

        if ($skipped === null) {
            return self::INVALID;
        }

        $this->skippedTables = $skipped;

        if ($skipped !== []) {
            // Said out loud, and in the terms that matter: what is not being
            // repaired, rather than what is being allowed through.
            $this->components->warn(sprintf(
                'Leaving %s alone. Rows in %s are neither read, repaired, nor able to fail this run - '.
                'whatever they were going to fill stays empty, and the source still holds it.',
                implode(', ', $skipped),
                count($skipped) === 1 ? 'it' : 'them',
            ));
        }

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
            // Inside the transaction on purpose. Asked before it, a row deleted
            // in the gap would be seen by the repairs and not by this gate -
            // and an unmatched row is now a warning, so the run would commit
            // over exactly the loss this refuses. Sharing the transaction's
            // snapshot means the gate sees what the repairs see.
            $verdict = $this->verdictForLedgerResolution($legacy, $identityHash, ! $dryRun);

            if ($verdict === self::SUCCESS) {
                $totals = [];
                foreach ([
                    'invoices' => ['client_invoices', fn (): array => $this->backfillInvoices($legacy, $identityHash, $dryRun)],
                    'invoice lines' => ['client_invoice_lines', fn (): array => $this->backfillInvoiceLines($legacy, $identityHash, $dryRun)],
                    'agreements' => ['client_agreements', fn (): array => $this->backfillAgreements($legacy, $identityHash, $dryRun)],
                    'tasks' => ['client_tasks', fn (): array => $this->backfillTasks($legacy, $identityHash, $dryRun)],
                    'time entries' => ['client_time_entries', fn (): array => $this->backfillTimeEntries($legacy, $identityHash, $dryRun)],
                ] as $label => [$table, $step]) {
                    // A skipped table is not run at all, so it contributes no
                    // repairs and no verdict. That is the whole distinction
                    // from a waiver: nothing about this table's rows is being
                    // declared acceptable, they are simply not being touched.
                    if (in_array($table, $skipped, true)) {
                        $this->components->twoColumnDetail($label, 'skipped, not read');

                        continue;
                    }

                    $result = $step();
                    $totals[$label] = $result;
                    $this->components->twoColumnDetail(
                        $label,
                        sprintf('%d matched, %d %s', $result['matched'], $result['written'], $dryRun ? 'would change' : 'updated'),
                    );
                }

                $verdict = $this->verdictFor($totals);
            }
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
     * The source tables to leave entirely alone, or null if one is not a table.
     *
     * Deliberately not a waiver. `--accept-drift` says "this difference is
     * acceptable"; this says "do not read this table". Nothing about a skipped
     * table's rows is declared trustworthy - they are not consulted, nothing is
     * written from them, and whatever they were going to fill stays empty with
     * the source still holding it.
     *
     * That distinction matters because the two are reached from the same place.
     * When the fingerprint guard refuses a row that would have written a
     * financial relationship, the tempting move is to waive the guard. Skipping
     * the table instead keeps the guard intact everywhere it still runs, and
     * leaves a hole an operator can see rather than a check they turned off.
     *
     * @return list<string>|null
     */
    private function skippedTables(): ?array
    {
        /** @var list<string> $named */
        $named = (array) $this->option('skip-table');
        $skipped = [];

        foreach ($named as $table) {
            $table = trim((string) $table);

            if ($table === '') {
                continue;
            }

            if (! array_key_exists($table, self::SOURCE_KEYS)) {
                $this->error(sprintf(
                    'There is no source table named %s. This command reads: %s.',
                    $table,
                    implode(', ', array_keys(self::SOURCE_KEYS)),
                ));

                return null;
            }

            $skipped[] = $table;
        }

        return array_values(array_unique($skipped));
    }

    /**
     * The source tables this run will actually read.
     *
     * Every stage asks this rather than `SOURCE_KEYS` directly, so a skipped
     * table is invisible to restore verification and ledger resolution as well
     * as to the repair loop. Without that the option kept only half its promise:
     * the repairs skipped the table, and a preflight failure in it still rolled
     * back everything else.
     *
     * @return array<string, string>
     */
    private function tablesInPlay(): array
    {
        return array_diff_key(self::SOURCE_KEYS, array_flip($this->skippedTables));
    }

    /**
     * Whether every ledger row still names a destination row this can repair.
     */
    private function verdictForLedgerResolution(ConnectionInterface $legacy, string $identityHash, bool $lock): int
    {
        // A ledger row saying "imported" whose destination row cannot be found
        // is a different thing from a source row the ledger never recorded, and
        // it used to hide inside the same counter. The backfill sees only that
        // the source key does not resolve, so both arrive as `unmatched` - and
        // now that an unmatched row is reported rather than fatal, an
        // unrepairable financial row would pass silently. Separate them here,
        // where the ledger can still be asked which of the two it is.
        $fatal = [];
        foreach ($this->tablesInPlay() as $table => $_) {
            $beyond = $this->ledgerTargetsBeyondRepair($table, $table, $identityHash, $lock);
            $removable = in_array($table, self::DELETED_IN_ORDINARY_USE, true);

            if ($beyond['unnamed'] > 0) {
                // Waived by table is not good enough here. An ordinary deletion
                // explains a row that was named and has gone; nothing explains
                // one that was never named, so the waiver does not reach it.
                $this->components->twoColumnDetail("  {$table}", sprintf('%d ledger row(s) name no destination at all', $beyond['unnamed']));
                $fatal[$table] = true;
            }

            if ($beyond['lost'] > 0) {
                // Waiving by table alone would cover a mapping that has simply
                // stopped meaning anything, so the waiver has to be earned: an
                // edit takes an invoice's lines all together, and a loss beside
                // surviving siblings is not one.
                $unexplained = $removable
                    ? $this->lostLinesAnEditDoesNotExplain($legacy, $identityHash, $beyond['lost_keys'])
                    : $beyond['lost'];

                if (! $removable) {
                    $detail = sprintf('%d ledger row(s) name a destination this workspace does not have', $beyond['lost']);
                } elseif ($unexplained === 0) {
                    $detail = sprintf('%d ledger row(s) name a destination this workspace does not have - removable in ordinary use', $beyond['lost']);
                } else {
                    $detail = sprintf('%d of %d lost ledger row(s) are not accounted for by an ordinary edit', $unexplained, $beyond['lost']);
                }

                $this->components->twoColumnDetail("  {$table}", $detail);

                if ($unexplained > 0) {
                    $fatal[$table] = true;
                }
            }

        }

        if ($fatal !== []) {
            $this->components->error(sprintf(
                'The ledger says rows were imported into %s that this workspace cannot follow to a destination. '.
                'Nothing here removes them, so the destination has lost data the repair cannot restore; repairing '.
                'the rest would report a clean run over a ledger that is not.',
                implode(', ', array_keys($fatal)),
            ));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Whether what was found justifies keeping the repairs.
     *
     * @param  array<string, array{matched:int, written:int, unmatched:int, changed:int, deferred:int, unresolved:int}>  $totals
     */
    private function verdictFor(array $totals): int
    {

        $unmatched = array_sum(array_column($totals, 'unmatched'));
        if ($unmatched > 0) {
            // Ordinary, and evidence of nothing. An onboarding may import a
            // subset, and every source imported so far soft-deletes: the
            // importer never ledgers a row the source has thrown away, so a
            // healthy source has more rows than the ledger by exactly that
            // many. Against the migrated data that is 997 rows - 49 invoices,
            // 764 lines and 184 time entries, all deleted at the source.
            //
            // This used to fail a declared restore, which inverted the
            // assertion it was making. A restore asserts that every *ledger*
            // row is in the source, not that every source row is in the
            // ledger; the first is a claim about completeness and the second is
            // a claim the importer's own filtering makes false. The right
            // direction is checked before any of this runs, in verifyRestore(),
            // over every table this command repairs.
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

        $comparable = RestoreAgreementVerifier::comparableColumns();

        // Every table this command repairs, not only the ones whose columns are
        // worth comparing. A restore asserts that the rows the ledger recorded
        // are still there, and that claim is no weaker for a table the verifier
        // has no columns for - an agreement or a task missing from the restore
        // is a ledger row this command would then quietly decline to repair.
        foreach ($this->tablesInPlay() as $table => $sourceKey) {
            $idMap = array_map(static fn (array $m): int => $m['id'], $this->idMap($table, $table, $identityHash));
            if ($idMap === []) {
                // The ledger recorded nothing from this table, so there is
                // nothing to compare and no reason to touch it in the source.
                continue;
            }

            if (! isset($comparable[$table])) {
                $absent = $this->ledgerRowsAbsentFromSource($legacy, $table, $sourceKey, array_keys($idMap));
                if ($absent > 0) {
                    $missing[$table] = $absent;
                }

                continue;
            }

            $result = $verifier->verify(
                $legacy,
                $table,
                $sourceKey,
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

    /**
     * Why this table's ledger rows do not resolve, kept apart by reason.
     *
     * idMap() drops all three silently - it can only return mappings it can
     * resolve - so counting them means asking the same questions it asks and
     * taking the difference. They are not the same finding, and collapsing
     * them loses the only thing that decides whether a run may continue:
     *
     * - `unnamed`: the ledger says imported and names nothing. No deletion
     *   path explains that, on any table.
     * - `lost`: it named a row this workspace does not have. On a table SVC
     *   removes rows from in ordinary use this is the system working.
     *
     * Both questions are asked only of this workspace's rows. A target another
     * tenant owns is simply not found, and is not looked for: establishing
     * which of the two it was would mean reading a row this command has no
     * business reading, and a ledger row this workspace cannot follow is
     * unrepairable whichever it turns out to be.
     *
     * @return array{unnamed:int, lost:int, lost_keys:list<string>}
     */
    private function ledgerTargetsBeyondRepair(string $sourceTable, string $destinationTable, string $identityHash, bool $lock): array
    {
        $items = $this->db()->table('external_import_items')
            ->where('source_table', $sourceTable)
            ->where('source_identity_hash', $identityHash)
            ->where('status', 'imported')
            ->whereIn(
                'external_import_run_id',
                $this->db()->table('external_import_runs')->where('workspace_id', $this->workspaceId)->select('id'),
            )
            ->get(['source_key', 'target_public_id']);

        if ($items->isEmpty()) {
            return ['unnamed' => 0, 'lost' => 0, 'lost_keys' => []];
        }

        $named = $items->filter(static fn (object $i): bool => $i->target_public_id !== null && $i->target_public_id !== '');
        $unnamed = $items->count() - $named->count();
        $publicIds = $named->pluck('target_public_id')->unique()->values()->all();

        if ($publicIds === []) {
            return ['unnamed' => $unnamed, 'lost' => 0, 'lost_keys' => []];
        }

        // Locked, not merely read - but only when this run can write. The gate
        // runs in the repair's transaction, and a consistent read only fixes
        // what this sees: a row deleted after it and before the repair reaches
        // it makes the later update a current read that touches nothing, which
        // applyRow() records as deferred and the run then commits over. Holding
        // the rows until the transaction ends is what makes the gate's answer
        // still true when it is used.
        //
        // A report is the default here, and it commits nothing, so it has no
        // business holding every invoice and line in the workspace while it
        // reads five source tables. Nobody should have to stop billing to run
        // one.
        $query = $this->db()->table($destinationTable)
            ->whereIn('public_id', $publicIds)
            ->where('workspace_id', $this->workspaceId);

        $mine = [];
        foreach (($lock ? $query->lockForUpdate() : $query)->pluck('public_id') as $id) {
            $mine[(string) $id] = true;
        }

        // Rows, not distinct ids, so the counts read the way the messages do:
        // how many ledger rows are in each state.
        $lostKeys = [];
        foreach ($named as $item) {
            if (! isset($mine[(string) $item->target_public_id])) {
                $lostKeys[] = (string) $item->source_key;
            }
        }

        return ['unnamed' => $unnamed, 'lost' => count($lostKeys), 'lost_keys' => $lostKeys];
    }

    /** Whether the source records a line type at all. */
    private function sourceHasLineType(ConnectionInterface $legacy): bool
    {
        try {
            $legacy->table('client_invoice_lines')->select('line_type')->limit(1)->get();

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Which of these lost invoice lines an ordinary edit does not account for.
     *
     * Editing a draft invoice deletes every one of its lines and writes fresh
     * ones (InvoiceLifecycleService::update), so an edit takes an invoice's
     * ledgered lines all together. One line gone while its siblings still
     * resolve is therefore not an edit - it is a mapping that has stopped
     * meaning anything, and the waiver this table has must not cover it.
     *
     * Asked entirely of this workspace's rows and of the source, so no other
     * tenant's record is read to answer it.
     *
     * The waiver is for a line a rewrite took, so it is granted only where a
     * rewrite could have happened: on an invoice that arrived as a draft.
     * Nothing rewrites an invoice past draft - InvoiceLifecycleService refuses
     * on every path that touches lines - so a ledgered line missing from one
     * that was already settled when it was imported was not replaced, it was
     * lost, and no reading of its siblings changes that.
     *
     * The state it arrived in, not the state it is in: editing a draft and
     * then issuing it is an ordinary sequence, and asking about the invoice
     * now would call every line that edit legitimately removed unexplained.
     *
     * Two things this still cannot see, and neither is closed here. A ledger
     * row whose target exists under another workspace reads exactly like a
     * deleted one from inside this workspace; telling them apart needs that
     * tenant's row, and reading it is not something a repair run for one
     * tenant may do. And a draft rewritten down to nothing leaves nothing to
     * read either. Both survive only where the invoice is still open, which is
     * where a wrong repair costs least and an operator can still see it.
     *
     * @param  list<string>  $lostKeys
     */
    private function lostLinesAnEditDoesNotExplain(ConnectionInterface $legacy, string $identityHash, array $lostKeys): int
    {
        if ($lostKeys === []) {
            return 0;
        }

        // The type is optional the same way the importer treats it - a source
        // without the column has its lines read as adjustments - but the parent
        // is not: without it there is no evidence to be had, and a waiver
        // granted on no evidence is the thing this exists to stop.
        $hasType = $this->sourceHasLineType($legacy);
        $keyColumn = self::SOURCE_KEYS['client_invoice_lines'];
        $fingerprints = $this->ledgerFingerprints('client_invoice_lines', $identityHash);
        $invoiceOfLine = [];
        $typeOfLine = [];

        try {
            foreach (array_chunk($lostKeys, 500) as $chunk) {
                foreach ($legacy->table('client_invoice_lines')->whereIn($keyColumn, $chunk)->get() as $found) {
                    $row = (array) $found;
                    $key = (string) ($row[$keyColumn] ?? '');
                    $fingerprint = $fingerprints[$key] ?? null;

                    // The whole row, and required whatever a verified restore
                    // said. What is read here is the parent and the type of a
                    // row whose destination is gone - the only two facts the
                    // waiver rests on - and a source row that has moved since
                    // the import describes a line the import never saw. The
                    // drift a restore verification accepts is drift in what it
                    // compares; it does not speak for these, and resolve()
                    // makes the same demand before writing a billing
                    // relationship for the same reason.
                    if ($fingerprint === null || Fingerprint::row($row) !== $fingerprint) {
                        continue;
                    }

                    // A line with no parent has nothing to reason from, and a
                    // cast would turn that null into an invoice named '' that
                    // every other line is absent from - evidence out of
                    // nothing, pointing the wrong way.
                    if (($row['client_invoice_id'] ?? null) === null || (string) $row['client_invoice_id'] === '') {
                        continue;
                    }

                    $invoiceOfLine[$key] = (string) $row['client_invoice_id'];
                    $typeOfLine[$key] = $hasType ? (string) ($row['line_type'] ?? '') : self::UNTYPED_SOURCE_LINE;
                }
            }
        } catch (QueryException) {
            return count($lostKeys);
        }

        $invoices = array_values(array_unique(array_values($invoiceOfLine)));
        if ($invoices === []) {
            // The source does not have these lines either, so there is no
            // parent to reason from. No evidence is not evidence of an edit.
            return count($lostKeys);
        }

        // Every line of those invoices that still resolves, kept by whether the
        // generator wrote it. Regeneration comes in two shapes: an edit through
        // InvoiceLifecycleService::update() deletes every line, and
        // InvoiceLineComposer::resetSystemGeneratedLines() deletes only the
        // generated ones and leaves an operator's adjustments standing. So a
        // surviving adjustment disproves nothing about a generated line that
        // has gone, while a surviving generated line disproves both.
        $ledgered = array_map(
            static fn (array $m): int => $m['id'],
            $this->idMap('client_invoice_lines', 'client_invoice_lines', $identityHash),
        );
        $generated = InvoiceLineType::systemGeneratedValues();

        $survivors = [];
        foreach (array_chunk($invoices, 500) as $chunk) {
            foreach ($legacy->table('client_invoice_lines')
                ->whereIn('client_invoice_id', $chunk)
                ->get(array_filter([self::SOURCE_KEYS['client_invoice_lines'].' as line_key', 'client_invoice_id', $hasType ? 'line_type' : null])) as $row) {
                if (! isset($ledgered[(string) $row->line_key])) {
                    continue;
                }

                $type = $hasType ? (string) $row->line_type : self::UNTYPED_SOURCE_LINE;
                $invoice = (string) $row->client_invoice_id;
                $survivors[$invoice]['any'] = true;
                if ($type === InvoiceLineType::Credit->value) {
                    $survivors[$invoice]['credit'] = true;
                }
                if (in_array($type, $generated, true)) {
                    $survivors[$invoice]['generated'] = true;
                }
            }
        }

        // Which of those invoices could have been rewritten at all. Asked of
        // the source, and of the state it was imported in.
        $rewritable = $this->invoicesImportedAsDrafts($legacy, $invoices, $identityHash);

        $unexplained = 0;
        foreach ($lostKeys as $key) {
            $invoice = $invoiceOfLine[$key] ?? null;
            if ($invoice === null) {
                $unexplained++;

                continue;
            }

            $lostType = $typeOfLine[$key] ?? self::UNTYPED_SOURCE_LINE;

            // Which survivors can disprove the loss depends on what deletes a
            // line of that type. A credit is replaced on its own by
            // OverpaymentCreditService, so only a surviving credit says the
            // credit-only pass did not run; any other generated line is
            // replaced with all of them, so any surviving generated line does.
            // Anything the generator does not write goes only with a full
            // rewrite, and then every survivor counts.
            if ($lostType === InvoiceLineType::Credit->value) {
                $disproved = $survivors[$invoice]['credit'] ?? false;
            } elseif (in_array($lostType, $generated, true)) {
                $disproved = $survivors[$invoice]['generated'] ?? false;
            } else {
                $disproved = $survivors[$invoice]['any'] ?? false;
            }

            // A credit is the exception to the draft requirement, and it has to
            // be. InvoiceLifecycleService::issue() calls
            // capOverpaymentCreditAtIssue(), which deletes the credit line when
            // the pool can no longer cover it, and then sets the status in the
            // same transaction - so the invoice a credit legitimately vanished
            // from is never a draft afterwards, and may be paid by the time
            // anyone runs this. For a credit the surviving-sibling reading is
            // all there is.
            $couldHaveBeenRewritten = $lostType === InvoiceLineType::Credit->value
                || isset($rewritable[$invoice]);

            // Two ways to be unexplained, and they are different questions. A
            // survivor that contradicts the deletion says the rewrite did not
            // happen; an invoice that is no longer a draft says it could not
            // have.
            if ($disproved || ! $couldHaveBeenRewritten) {
                $unexplained++;
            }
        }

        return $unexplained;
    }

    /**
     * The fingerprint the ledger stored for each of this table's imported rows.
     *
     * idMap() carries these too, but only for rows whose destination still
     * resolves - which is exactly what the rows asked about here do not.
     *
     * @return array<string, string>
     */
    private function ledgerFingerprints(string $sourceTable, string $identityHash): array
    {
        $fingerprints = [];

        foreach ($this->db()->table('external_import_items')
            ->where('source_table', $sourceTable)
            ->where('source_identity_hash', $identityHash)
            ->where('status', 'imported')
            ->whereIn(
                'external_import_run_id',
                $this->db()->table('external_import_runs')->where('workspace_id', $this->workspaceId)->select('id'),
            )
            ->get(['source_key', 'source_fingerprint']) as $item) {
            $fingerprints[(string) $item->source_key] = (string) $item->source_fingerprint;
        }

        return $fingerprints;
    }

    /**
     * Which of these source invoices arrived in a state that could still be
     * edited.
     *
     * Asked of the invoice as it was imported, not as it stands now. The
     * status now says nothing about whether a rewrite was possible: editing a
     * draft and then issuing it is one ordinary sequence, and it leaves an
     * issued invoice whose lines were legitimately replaced while it was a
     * draft. What decides it is where the invoice started - an invoice
     * imported already settled was never a draft here, so nothing this system
     * does could have taken a line off it.
     *
     * Read from the source rather than the destination for the same reason,
     * and held to the ledger fingerprint like the lost line itself: an invoice
     * row edited since the import describes a state the import never saw.
     *
     * `status` where the source keeps one, and the settlement date where it
     * does not. A source with neither cannot answer the question, and is
     * treated as answering no.
     *
     * @param  list<string>  $sourceInvoiceKeys
     * @return array<string, true> keyed by source invoice key
     */
    private function invoicesImportedAsDrafts(ConnectionInterface $legacy, array $sourceInvoiceKeys, string $identityHash): array
    {
        if ($sourceInvoiceKeys === []) {
            return [];
        }

        $keyColumn = self::SOURCE_KEYS['client_invoices'];
        $fingerprints = $this->ledgerFingerprints('client_invoices', $identityHash);
        $drafts = [];

        try {
            foreach (array_chunk($sourceInvoiceKeys, 500) as $chunk) {
                foreach ($legacy->table('client_invoices')->whereIn($keyColumn, $chunk)->get() as $found) {
                    $row = (array) $found;
                    $key = (string) ($row[$keyColumn] ?? '');
                    $fingerprint = $fingerprints[$key] ?? null;

                    if ($fingerprint === null || Fingerprint::row($row) !== $fingerprint) {
                        continue;
                    }

                    // Which column answers is read off the row itself. The
                    // whole row is already in hand, so asking the schema
                    // separately would be a second question about something
                    // this can already see.
                    if (array_key_exists('status', $row)) {
                        $editable = (string) ($row['status'] ?? '') === 'draft';
                    } elseif (array_key_exists('paid_date', $row)) {
                        $editable = ($row['paid_date'] ?? null) === null || (string) $row['paid_date'] === '';
                    } else {
                        continue;
                    }

                    if ($editable) {
                        $drafts[$key] = true;
                    }
                }
            }
        } catch (QueryException) {
            return [];
        }

        return $drafts;
    }

    /**
     * How many of this table's ledger rows the source no longer has.
     *
     * The key column alone answers it, so the rows themselves are never read -
     * this runs over tables the verifier compares nothing on, and reading them
     * in full to ask a yes-or-no question would be the expensive way to learn
     * the same thing.
     *
     * @param  list<array-key>  $ledgerKeys
     */
    private function ledgerRowsAbsentFromSource(ConnectionInterface $legacy, string $table, string $sourceKey, array $ledgerKeys): int
    {
        $expected = [];
        foreach ($ledgerKeys as $key) {
            $expected[(string) $key] = true;
        }

        foreach (array_chunk($ledgerKeys, 500) as $chunk) {
            foreach ($legacy->table($table)->whereIn($sourceKey, $chunk)->pluck($sourceKey) as $present) {
                unset($expected[(string) $present]);
            }
        }

        return count($expected);
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
