<?php

namespace App\Services\ExternalImport;

use App\Models\ClientInvoice;
use App\Models\ExternalImportFailure;
use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\Workspace;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\PeriodLabel;
use App\Support\Billing\SubcontractorBillingMode;
use App\Support\WorkspaceClock;
use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type ImportCounts array{
 *     source_rows: int,
 *     planned: int,
 *     imported: int,
 *     skipped: int,
 *     planned_copy: int,
 *     planned_reference: int,
 *     failed: int,
 *     idempotent: int,
 *     failure_reasons: array<string, int>
 * }
 * @phpstan-type QueryCache array{
 *     parent_ids: array<array-key, string|null>,
 *     internal_ids: array<array-key, int|null>,
 *     stripe_customer_ids: array<array-key, int|null>,
 *     related_exists: array<array-key, bool>,
 *     target_exists: array<array-key, bool>,
 *     table_exists: array<array-key, bool>,
 *     table_columns: array<array-key, list<string>>,
 *     source_columns: array<array-key, list<string>>,
 *     sole_superseded_claim: array<array-key, bool>,
 *     agreement_context: array<array-key, array{months: int, terminated: bool}|null>
 * }
 */
final class ExternalImportService
{
    /**
     * Source keys this run observed carrying an invoice-line link, per source
     * table. A reconciliation pass queries the source again and only sees rows
     * that still carry one - so a link cleared, or a row deleted, between the
     * two reads simply vanishes from the second. Without this the pass has
     * nothing to notice its absence against.
     *
     * The value is the line each claim named, not just that it had one, so a
     * later question about which claims competed can be answered from what
     * this run observed rather than from a second read that may have moved.
     *
     * @var array<string, array<string, string>>
     */
    private array $linkedSourceKeys = [];

    /**
     * linkedSourceKeys inverted, built once per run.
     *
     * @var array<string, array<string, int>>|null
     */
    private ?array $observedClaimsByLine = null;

    /**
     * Claims the source holds on each line, per claimant table, built once.
     *
     * @var array<string, array<string, int>>
     */
    private array $sourceClaimsByLine = [];

    /**
     * Line types a description can tell apart, once its numbers are set aside.
     *
     * These are not one-per-invoice - that was the first thing tried here and
     * it is not true. ClientInvoicingService writes a prior_month_retainer line
     * per retainer pool, and addDeferredRetainerLine can add another. What is
     * true is that their descriptions name what they are for in words: which
     * pool a draw came from, what the hours were charged against. Numbers move
     * between generations of the same line - the hours in "applied to retainer
     * (9.9168)" become "(10.0000)" - so they are normalised away, and what is
     * left identifies the line rather than the run that wrote it.
     *
     * The types kept out are the ones where that still would not settle it. A
     * subcontractor charge is one line per (user, project, rate, currency), and
     * the rate is a number, so two groups can normalise to the same words. A
     * milestone, a recurring_item and an adjustment are each one item among
     * several of their kind.
     *
     * None of this is needed where the claim is exclusive: a milestone task
     * holds one line, so counting claims settles the identity by itself.
     *
     * @var list<string>
     */
    /**
     * Descriptions a billing service writes, anchored to their openings.
     *
     * Deliberately literal. A shape like "something in parentheses" would match
     * an operator's text as readily as a generated line, and the whole point of
     * the list is to tell those apart.
     *
     * @var list<string>
     */
    /**
     * Descriptions a billing service writes, and how much of one it fills in.
     *
     * Deliberately literal, down to the cadences BillingCadenceLabel emits: a
     * shape like "something in parentheses" would match an operator's text as
     * readily as a generated line, and telling those apart is the whole point.
     *
     * The value says where the figures are. Most templates put one at the
     * front of a group and words after it - "(N applied to 2026-01 cycle)" -
     * so only the figure goes. The termination line fills the entire group,
     * "(N @ $X/hr)", and both move when the rate changes.
     *
     * @var array<string, bool>
     */
    /**
     * A quantity either system writes into a description.
     *
     * HoursQuantity::format builds H:MM with %d, so there is no leading zero to
     * allow - "09:55" and "010:00" are not two generations of one line. The
     * source writes the same quantity as a decimal on its retainer fee lines,
     * 197 of them against 5 in H:MM, so both forms are quantities here. These
     * descriptions come from the predecessor, and only some of its wording
     * survived into the composer; deriving the figures from the composer alone
     * refused every claim on a fee line.
     *
     * Signed only above zero. HoursQuantity::format takes the sign after
     * rounding - `$totalMinutes < 0` is false for zero - so "-0:00" is a string
     * it cannot produce, and admitting it would let somebody's own "-0:00" wear
     * the same shape as a real "0:00" and take its claim.
     */
    private const HOURS = '(?:-?'.self::POSITIVE_HOURS.'|0(?::00|\.0+))';

    /**
     * Hours above zero, for the one template whose line cannot exist below it.
     *
     * addDeferredTerminationLine() returns without writing anything when the
     * minutes are not positive, so "(0:00 @ ...)" is not a line it produced -
     * and that template replaces its whole group, so a false match there costs
     * the text that identified the charge.
     */
    private const POSITIVE_HOURS = '(?:[1-9]\d*(?::[0-5]\d|\.\d+)|0:(?:0[1-9]|[1-5]\d)|0\.\d*[1-9]\d*)';

    /** A range of months as PeriodLabel writes one. */
    private const PERIOD_RANGE = '\d{4}-(?:0[1-9]|1[0-2])\.\.\d{4}-(?:0[1-9]|1[0-2])';

    /** A month as PeriodLabel writes one. */
    private const PERIOD_MONTH = '\d{4}-(?:0[1-9]|1[0-2])';

    /** What Carbon's "M j, Y" writes. */
    private const SHORT_DATE = '(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{1,2}, \d{4}';

    /** What Carbon's "F Y" writes. */
    private const POOL_MONTH = '(?:January|February|March|April|May|June|July|August|September|October|November|December) \d{4}';

    /**
     * What formatMoney writes: number_format's grouping beside a currency code.
     *
     * number_format does not pad the leading group, so "00.00" and
     * "000,100.00" are not amounts it could have produced.
     */
    private const MONEY = '(?:0|[1-9]\d{0,2}(?:,\d{3})*)\.\d{2} [A-Z]{3}';

    /**
     * The wording each generator writes, and what the words commit it to.
     *
     * `months` is the cadence the wording names. It is checked twice and for
     * two different reasons: against the span the text quotes, because a
     * grammar cannot say how long a quarter is, and against the agreement the
     * line was written under, because the composer takes the cadence word from
     * that agreement and can write no other.
     *
     * The draw templates take positive hours only. Every path that writes one
     * is guarded on there being something to apply - fragments present, or
     * DeferredAllocationResult::hasBilled(), which is hoursBilled > 0 - so a
     * draw of nothing is not a line either generator can produce.
     *
     * @var array<string, array{whole: bool, types: list<string>, months?: int, terminated?: bool}>
     */
    private const GENERATED_DESCRIPTION_TEMPLATES = [
        // Monthly only, though the wording does not say so.
        // generateMonthlyInvoiceForWorkPeriod writes this form; every other
        // cadence goes through generateNonMonthlyInvoiceForPeriod, which names
        // its cadence and says "cycle" rather than "pool".
        '/^Work items applied to retainer \('.self::POSITIVE_HOURS.' applied to '.self::POOL_MONTH.' pool\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'], 'months' => 1,
        ],
        // One entry per cadence, because PeriodLabel writes a different shape
        // for each: a month for monthly, a quarter for quarterly, a year for
        // annual, and a month range for the six-month span. Accepting any
        // One entry per cadence, and each takes either the label PeriodLabel
        // writes for an aligned cycle or a month range for one anchored to an
        // agreement's active date. The range is checked against the cadence
        // afterwards, because a grammar cannot say how long six months is.
        // No range for monthly: the resolver builds those cycles between
        // startOfMonth and endOfMonth, so both ends always share a month and
        // PeriodLabel never reaches its range form.
        '/^Work items applied to monthly retainer \('.self::POSITIVE_HOURS.' applied to '.self::PERIOD_MONTH.' cycle\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'], 'months' => 1,
        ],
        '/^Work items applied to quarterly retainer \('.self::POSITIVE_HOURS.' applied to (?:\d{4}-Q[1-4]|'.self::PERIOD_RANGE.') cycle\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'], 'months' => 3,
        ],
        '/^Work items applied to semiannual retainer \('.self::POSITIVE_HOURS.' applied to '.self::PERIOD_RANGE.' cycle\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'], 'months' => 6,
        ],
        '/^Work items applied to annual retainer \('.self::POSITIVE_HOURS.' applied to (?:\d{4}|'.self::PERIOD_RANGE.') cycle\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'], 'months' => 12,
        ],
        '/^Deferred work items applied to retainer \('.self::POSITIVE_HOURS.'\)$/' => [
            'whole' => false, 'types' => ['prior_month_retainer'],
        ],
        // addDeferredTerminationLine() is reachable only through the
        // post-termination branch, so an agreement that was never terminated
        // has no line of this shape - and this template erases its whole
        // group, which is a costly thing to do to somebody's own charge.
        '/^Deferred work items billed on agreement termination \('.self::POSITIVE_HOURS.' @ '.self::MONEY.'\/hr\)$/' => [
            'whole' => true, 'types' => ['additional_hours'], 'terminated' => true,
        ],
        '/^Interim overage hours for '.self::POOL_MONTH.'$/' => [
            'whole' => false, 'types' => ['additional_hours'],
        ],
        '/^Monthly Retainer \('.self::HOURS.' hours\) - '.self::SHORT_DATE.' through '.self::SHORT_DATE.'$/' => [
            'whole' => false, 'types' => ['retainer'], 'months' => 1,
        ],
        '/^Quarterly Retainer \('.self::HOURS.' hours\) - '.self::SHORT_DATE.' through '.self::SHORT_DATE.'$/' => [
            'whole' => false, 'types' => ['retainer'], 'months' => 3,
        ],
        '/^Semiannual Retainer \('.self::HOURS.' hours\) - '.self::SHORT_DATE.' through '.self::SHORT_DATE.'$/' => [
            'whole' => false, 'types' => ['retainer'], 'months' => 6,
        ],
        '/^Annual Retainer \('.self::HOURS.' hours\) - '.self::SHORT_DATE.' through '.self::SHORT_DATE.'$/' => [
            'whole' => false, 'types' => ['retainer'], 'months' => 12,
        ],
    ];

    private const IDENTIFIABLE_BY_DESCRIPTION = [
        'retainer',
        'prior_month_retainer',
        'prior_month_billable',
        'additional_hours',
    ];

    /** @var QueryCache */
    private array $activeQueryCache;

    public function __construct(
        private readonly SourceGuard $sourceGuard,
        private readonly ImporterRegistry $registry,
        private readonly InventoryService $inventory,
        private readonly WorkspaceClock $clock,
    ) {
        $this->activeQueryCache = $this->newQueryCache();
    }

    /** @return array<string, mixed> */
    public function run(string $sourceName, string $workspaceIdentifier, bool $apply = false): array
    {
        $source = $this->sourceGuard->resolve($sourceName);
        $this->sourceGuard->assertDistinctFromDestination($source);
        $destinationName = $this->destinationName();
        $workspace = $this->workspace($workspaceIdentifier, $destinationName);
        $sourceConnection = $this->sourceGuard->connection($source);
        $specs = $this->registry->all();
        $inventory = $this->inventory->inspect($sourceConnection, $specs, $this->sourceGuard->runtimeName($source));
        $summary = $this->summary($source, $workspace, $inventory, $specs, $apply);

        if (! $apply) {
            return $summary;
        }

        $run = $this->newRun($source, $workspace, $inventory, $destinationName);
        /** @var ImportCounts $counts */
        $counts = $this->emptyCounts($inventory);
        $linkCounts = ['source_rows' => 0, 'inserted' => 0, 'idempotent' => 0, 'recovered' => 0, 'rejected' => 0, 'failed' => 0];
        $milestoneCounts = ['source_rows' => 0, 'linked' => 0, 'idempotent' => 0, 'rejected' => 0, 'failed' => 0];
        $this->linkedSourceKeys = [];
        $this->observedClaimsByLine = null;
        $this->sourceClaimsByLine = [];
        $queryCache = $this->newQueryCache();
        $this->activeQueryCache = &$queryCache;
        /** @var array<string, ExternalImportItem> $ledgerItems */
        $ledgerItems = [];

        try {
            foreach ($specs as $spec) {
                $table = (string) $spec['source_table'];
                if (! isset($inventory[$table])) {
                    continue;
                }

                $this->importSpec($sourceConnection, $this->sourceGuard->runtimeName($source), $spec, $run, $destinationName, $counts, $queryCache, $ledgerItems);
            }

            $this->reconcileImportedInvoices($run, $destinationName);
            $this->reconcileTimeEntryInvoiceLinks($sourceConnection, $this->sourceGuard->runtimeName($source), $run, $destinationName, $ledgerItems, $queryCache, $linkCounts, $counts);
            $this->reconcileMilestoneTaskInvoiceLinks($sourceConnection, $this->sourceGuard->runtimeName($source), $run, $destinationName, $ledgerItems, $queryCache, $milestoneCounts, $counts);

            $run->forceFill([
                'counts' => $counts + ['link_counts' => $linkCounts, 'milestone_link_counts' => $milestoneCounts],
                'status' => $counts['failed'] > 0
                    ? 'completed_with_failures'
                    // A row this ledger says it imported, which the source has
                    // since deleted, leaves a live copy here that nothing points
                    // at any more. This run has not reconciled it and must not
                    // report as though it had.
                    : (($counts['skipped'] > 0 || $counts['deleted_at_source'] > 0)
                        ? 'completed_with_skips'
                        : 'completed'),
                'completed_at' => $this->clock->now($workspace),
            ])->save();
        } catch (Throwable) {
            $run->forceFill(['counts' => $counts, 'status' => 'failed', 'completed_at' => $this->clock->now($workspace)])->save();
            throw new SourceConfigurationException('import_failed');
        }

        $summary['run_public_id'] = $run->public_id;
        $summary['counts'] = $counts;
        $summary['link_counts'] = $linkCounts;
        $summary['milestone_link_counts'] = $milestoneCounts;
        $summary['status'] = $run->status;

        return $summary;
    }

    /** @return array<string, mixed> */
    public function verify(?string $runPublicId = null, ?string $workspaceIdentifier = null): array
    {
        $destinationName = $this->destinationName();
        $query = (new ExternalImportRun)->setConnection($destinationName)->newQuery();
        if ($runPublicId !== null && $runPublicId !== '') {
            $query->where('public_id', $runPublicId);
        }
        if ($workspaceIdentifier !== null && $workspaceIdentifier !== '') {
            $workspace = $this->workspace($workspaceIdentifier, $destinationName);
            $query->where('workspace_id', $workspace->getKey());
        }

        $run = $query->latest('id')->first();
        if (! $run) {
            throw new SourceConfigurationException('import_run_not_found');
        }

        $observations = DB::connection($destinationName)->table('external_import_run_items')
            ->where('external_import_run_id', $run->getKey());
        $counts = (clone $observations)->selectRaw('observed_status, COUNT(*) as aggregate')
            ->groupBy('observed_status')->pluck('aggregate', 'observed_status')
            ->map(fn ($value): int => (int) $value)->all();
        $itemIds = (clone $observations)->pluck('external_import_item_id');
        $items = (new ExternalImportItem)->setConnection($destinationName)->newQuery()->whereIn('id', $itemIds)
            ->where('status', 'imported')
            ->get(['source_table', 'target_type', 'target_public_id']);
        $targetIdsByTable = [];
        $tableExists = [];
        $missingTargets = 0;
        foreach ($items as $item) {
            $table = $this->targetTableForType((string) $item->target_type, (string) $item->source_table);
            if ($table === null || ! ($tableExists[$table] ??= Schema::connection($destinationName)->hasTable($table)) || ! is_string($item->target_public_id) || $item->target_public_id === '') {
                $missingTargets++;

                continue;
            }
            $targetIdsByTable[$table][] = $item->target_public_id;
        }
        foreach ($targetIdsByTable as $table => $targetIds) {
            $existingIds = DB::connection($destinationName)->table($table)->whereIn('public_id', array_values(array_unique($targetIds)))->pluck('public_id')->map(fn ($id): string => (string) $id)->all();
            $existingIds = array_fill_keys($existingIds, true);
            foreach ($targetIds as $targetId) {
                if (! isset($existingIds[$targetId])) {
                    $missingTargets++;
                }
            }
        }

        $failureCount = (new ExternalImportFailure)->setConnection($destinationName)->newQuery()->where('external_import_run_id', $run->getKey())->count();

        return [
            'run_public_id' => $run->public_id,
            'workspace_public_id' => $run->workspace->public_id,
            'mode' => $run->mode,
            'status' => $run->status,
            'counts' => $counts,
            'failure_count' => $failureCount,
            'missing_target_count' => $missingTargets,
            'source_high_water_marks' => $run->source_high_water_marks ?? [],
            'fingerprints' => $run->fingerprints ?? [],
            'redacted' => true,
            'ok' => $run->status === 'completed' && $failureCount === 0 && $missingTargets === 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $counts
     * @param  QueryCache  $queryCache
     * @param  array<string, ExternalImportItem>  $ledgerItems
     */
    private function importSpec(ConnectionInterface $source, string $sourceRuntimeName, array $spec, ExternalImportRun $run, string $destinationName, array &$counts, array &$queryCache, array &$ledgerItems): void
    {
        $table = (string) $spec['source_table'];
        $keyColumn = (string) $spec['source_key'];
        // Deleted rows are not imported. See SourceRows.
        $cursor = SourceRows::for($source, $sourceRuntimeName, $table)->orderBy($keyColumn)->cursor();
        $itemsForTable = $this->loadLedgerItems($run, $table, $destinationName);
        foreach ($itemsForTable as $sourceKey => $item) {
            $ledgerItems[$this->ledgerItemKey($table, (string) $sourceKey)] = $item;
        }

        // Every key this pass actually saw, so a row the source has deleted
        // since an earlier pass can be told apart from one that was never
        // there. Skipping it silently would leave a live copy here that the
        // source no longer has, while the run reported clean.
        $seenKeys = [];

        foreach ($cursor as $rawRow) {
            $row = (array) $rawRow;
            $sourceKey = (string) ($row[$keyColumn] ?? '');
            $seenKeys[$sourceKey] = true;
            if (($row['client_invoice_line_id'] ?? null) !== null && $sourceKey !== '') {
                $this->linkedSourceKeys[$table][$sourceKey] = (string) $row['client_invoice_line_id'];
            }
            if ($sourceKey === '') {
                $counts['failed']++;
                $counts['failure_reasons']['missing_source_key'] = ($counts['failure_reasons']['missing_source_key'] ?? 0) + 1;

                continue;
            }
            $fingerprint = Fingerprint::row($row);

            try {
                DB::connection($destinationName)->transaction(function () use ($row, $sourceKey, $fingerprint, $spec, $run, $destinationName, &$counts, &$queryCache, &$ledgerItems, &$itemsForTable, $table): void {
                    $item = $itemsForTable[$sourceKey] ?? null;

                    if ($item && $item->source_fingerprint !== $fingerprint) {
                        $this->recordRunItem($run, $item, 'failed', $fingerprint, $destinationName);
                        $this->recordFailure($run, $item, $spec, $sourceKey, $fingerprint, 'source_changed', $row, $destinationName);
                        $counts['failed']++;
                        $counts['failure_reasons']['source_changed'] = ($counts['failure_reasons']['source_changed'] ?? 0) + 1;

                        return;
                    }
                    if ($item && in_array($item->status, ['imported', 'planned_copy', 'planned_reference'], true)) {
                        $this->recordRunItem($run, $item, 'idempotent', $fingerprint, $destinationName);
                        $counts['idempotent']++;

                        return;
                    }

                    $result = $this->importRow($row, $spec, $run, $item, $destinationName, $queryCache, $ledgerItems);
                    $item = $item ?: new ExternalImportItem;
                    $item->setConnection($destinationName);
                    $item->forceFill([
                        'external_import_run_id' => $run->getKey(),
                        'source_connection' => $run->source_connection,
                        'source_identity_hash' => $run->source_identity_hash,
                        'source_table' => $spec['source_table'],
                        'source_key' => $sourceKey,
                        'target_type' => $spec['target_type'],
                        'target_public_id' => $result['target_public_id'] ?? null,
                        'source_fingerprint' => $fingerprint,
                        'status' => $result['status'],
                        'reason_code' => $result['reason_code'] ?? null,
                    ])->save();
                    $itemsForTable[$sourceKey] = $item;
                    $ledgerItems[$this->ledgerItemKey($table, $sourceKey)] = $item;
                    $this->recordRunItem($run, $item, $result['status'], $fingerprint, $destinationName);

                    $counts[$result['status']]++;
                    if (isset($result['reason_code'])) {
                        $reason = $result['reason_code'];
                        $counts['failure_reasons'][$reason] = ($counts['failure_reasons'][$reason] ?? 0) + 1;
                    }
                    if ($result['status'] === 'failed') {
                        $this->recordFailure($run, $item, $spec, $sourceKey, $fingerprint, $result['reason_code'] ?? 'import_failed', $row, $destinationName);
                    }
                });
            } catch (Throwable) {
                DB::connection($destinationName)->transaction(function () use ($row, $sourceKey, $fingerprint, $spec, $run, $destinationName, &$ledgerItems, &$itemsForTable, $table): void {
                    $item = (new ExternalImportItem)->setConnection($destinationName)->newQuery()->firstOrCreate(
                        [
                            'source_identity_hash' => $run->source_identity_hash,
                            'source_table' => $spec['source_table'],
                            'source_key' => $sourceKey,
                        ],
                        [
                            'external_import_run_id' => $run->getKey(),
                            'source_connection' => $run->source_connection,
                            'target_type' => $spec['target_type'],
                            'source_fingerprint' => $fingerprint,
                            'status' => 'failed',
                            'reason_code' => 'row_transaction_failed',
                        ],
                    );
                    if (! in_array($item->status, ['imported', 'planned_copy', 'planned_reference'], true)) {
                        $item->forceFill([
                            'external_import_run_id' => $run->getKey(),
                            'source_fingerprint' => $fingerprint,
                            'status' => 'failed',
                            'reason_code' => 'row_transaction_failed',
                        ])->save();
                    }
                    $itemsForTable[$sourceKey] = $item;
                    $ledgerItems[$this->ledgerItemKey($table, $sourceKey)] = $item;
                    $this->recordRunItem($run, $item, 'failed', $fingerprint, $destinationName);
                    $this->recordFailure($run, $item, $spec, $sourceKey, $fingerprint, 'row_transaction_failed', $row, $destinationName);
                });
                $counts['failed']++;
                $counts['failure_reasons']['row_transaction_failed'] = ($counts['failure_reasons']['row_transaction_failed'] ?? 0) + 1;
            }
        }

        // Rows this ledger imported that the source has since deleted. They are
        // reported rather than removed: propagating a delete would destroy a
        // destination row that may since have been issued or paid against, and
        // that decision is not one an import pass should make on its own. What
        // it must not do is stay quiet - the run's status carries this.
        foreach ($itemsForTable as $sourceKey => $item) {
            if (isset($seenKeys[(string) $sourceKey]) || $item->status !== 'imported') {
                continue;
            }

            $counts['deleted_at_source']++;
            $counts['failure_reasons']['deleted_at_source'] = ($counts['failure_reasons']['deleted_at_source'] ?? 0) + 1;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $spec
     * @param  QueryCache  $queryCache
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @return array{status: string, target_public_id?: string, reason_code?: string}
     */
    private function importRow(array $row, array $spec, ExternalImportRun $run, ?ExternalImportItem $item, string $destinationName, array &$queryCache, array $ledgerItems): array
    {
        $action = $spec['action'];
        if ($action === 'planned_copy') {
            return ['status' => 'planned_copy', 'reason_code' => 'attachment_copy_deferred'];
        }
        if ($action === 'planned_reference') {
            return ['status' => 'planned_reference', 'reason_code' => 'stripe_reference_deferred'];
        }
        if ($action === 'bind_user') {
            return $this->bindUser($row, $destinationName, $queryCache);
        }

        if (! $this->destinationTableExists($destinationName, (string) $spec['target_table'], $queryCache)) {
            return ['status' => 'skipped', 'reason_code' => 'destination_table_missing'];
        }

        $parentIds = [];
        foreach ($spec['parents'] as $parent) {
            $value = $row[$parent['source_column']] ?? null;
            if ($value === null || $value === '') {
                if ($parent['required']) {
                    return ['status' => 'skipped', 'reason_code' => 'missing_parent'];
                }
                $parentIds[$parent['source_table']] = null;

                continue;
            }
            $parentIds[$parent['source_table']] = $this->resolveParentId($parent['source_table'], (string) $value, $ledgerItems, $queryCache);
            if ($parentIds[$parent['source_table']] === null && $parent['required']) {
                return ['status' => 'skipped', 'reason_code' => 'missing_parent'];
            }
        }

        $publicId = $item?->target_public_id ?: (string) Str::uuid();
        $attributes = $this->attributes($row, $spec['target_type'], $run->workspace_id, $parentIds, $publicId, $destinationName, $run->source_identity_hash, $queryCache, $ledgerItems);
        if ($spec['target_type'] === 'stripe_payment_method') {
            $companyId = $this->internalId($destinationName, 'client_companies', $parentIds['client_companies'] ?? null);
            $stripeCustomerKey = (string) ($companyId ?? 'none');
            $hasStripeCustomer = $queryCache['related_exists'][$stripeCustomerKey] ?? null;
            if ($hasStripeCustomer === null && $companyId !== null) {
                $hasStripeCustomer = DB::connection($destinationName)->table('client_stripe_customers')->where('client_company_id', $companyId)->exists();
                $queryCache['related_exists'][$stripeCustomerKey] = $hasStripeCustomer;
            }
            if ($companyId === null || $hasStripeCustomer !== true) {
                return ['status' => 'skipped', 'reason_code' => 'missing_parent'];
            }
        }
        $columns = $this->destinationColumns($destinationName, (string) $spec['target_table'], $queryCache);
        if (! in_array('public_id', $columns, true)) {
            return ['status' => 'skipped', 'reason_code' => 'destination_public_id_missing'];
        }
        $attributes = array_intersect_key($attributes, array_flip($columns));
        if (! isset($attributes['public_id']) && in_array('public_id', $columns, true)) {
            $attributes['public_id'] = $publicId;
        }
        if (in_array('created_at', $columns, true)) {
            $attributes['created_at'] ??= $this->clock->now();
        }
        if (in_array('updated_at', $columns, true)) {
            $attributes['updated_at'] ??= $this->clock->now();
        }

        $existing = in_array('public_id', $columns, true) ? DB::connection($destinationName)->table($spec['target_table'])->where('public_id', $publicId)->first() : null;
        if (! $existing && $attributes !== []) {
            DB::connection($destinationName)->table($spec['target_table'])->insert($attributes);
        }

        return ['status' => 'imported', 'target_public_id' => $publicId];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  QueryCache  $queryCache
     * @return array{status: string, target_public_id?: string, reason_code?: string}
     */
    private function bindUser(array $row, string $destinationName, array &$queryCache): array
    {
        $externalId = (string) ($row['id'] ?? '');
        $bindings = Config::get('external-import.user_bindings', []);
        $publicId = is_array($bindings) ? ($bindings[$externalId] ?? null) : null;

        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            $provider = $row['oauth_provider'] ?? $row['provider'] ?? null;
            $subject = $row['oauth_subject'] ?? $row['subject'] ?? null;
            $trusted = Config::get('external-import.trusted_identity_bindings', []);
            if (is_string($provider) && is_string($subject) && is_array($trusted)) {
                $publicId = $trusted[$provider.':'.$subject] ?? null;
            }
        }

        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            return ['status' => 'skipped', 'reason_code' => 'user_binding_required'];
        }

        $columns = $this->destinationColumns($destinationName, 'users', $queryCache);
        if (! in_array('public_id', $columns, true)) {
            return ['status' => 'skipped', 'reason_code' => 'user_public_id_column_missing'];
        }
        $userKey = 'users'."\0".$publicId;
        $userExists = $queryCache['target_exists'][$userKey] ?? null;
        if ($userExists === null) {
            $userExists = DB::connection($destinationName)->table('users')->where('public_id', $publicId)->exists();
            $queryCache['target_exists'][$userKey] = $userExists;
        }
        if ($userExists !== true) {
            return ['status' => 'skipped', 'reason_code' => 'user_binding_not_found'];
        }

        return ['status' => 'imported', 'target_public_id' => $publicId];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string|null>  $parents
     * @param  QueryCache  $queryCache
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @return array<string, mixed>
     */
    private function attributes(array $row, string $type, int $workspaceId, array $parents, string $publicId, string $destinationName, string $sourceIdentityHash, array &$queryCache, array $ledgerItems): array
    {
        $company = $parents['client_companies'] ?? null;
        $project = $parents['client_projects'] ?? null;
        $task = $parents['client_tasks'] ?? null;
        $user = $parents['users'] ?? null;
        $proposal = $parents['client_proposals'] ?? null;
        $agreement = $parents['client_agreements'] ?? null;
        $invoice = $parents['client_invoices'] ?? null;
        $recurring = $parents['client_agreement_recurring_items'] ?? null;
        // Source timestamps, carried for every type. importRow() falls back to
        // now() where the source table has no such column, so an imported row
        // dates from when it happened rather than from when it was imported.
        $attributes = ['public_id' => $publicId, 'workspace_id' => $workspaceId, 'created_at' => self::sourceTimestamp($row['created_at'] ?? null), 'updated_at' => self::sourceTimestamp($row['updated_at'] ?? null)];

        return match ($type) {
            'company' => $attributes + [
                'name' => $row['company_name'] ?? $row['name'] ?? 'External company',
                'slug' => $this->safeSlug((string) ($row['slug'] ?? $row['company_name'] ?? $row['name'] ?? 'external-company'), (string) ($row['id'] ?? '')),
                'billing_email' => $row['billing_email'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            'company_membership' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'user_id' => $this->internalId($destinationName, 'users', $user), 'role' => $row['role'] ?? 'client'],
            'company_activity' => $attributes + [
                'client_company_id' => $this->internalId($destinationName, 'client_companies', $company),
                'actor_user_id' => $this->internalId($destinationName, 'users', $user),
                'action' => (string) ($row['action'] ?? 'external.activity'),
                'subject_type' => $row['subject_type'] ?? null,
                'external_subject_id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
                'payload' => $this->jsonOrNull([
                    'external_subject_id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
                    'external_payload' => $this->decodeJson($row['payload'] ?? null),
                ]),
            ],
            'project' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'name' => $row['name'] ?? 'External project', 'description' => $row['description'] ?? null, 'status' => 'active', 'is_visible_to_client' => ! (bool) ($row['is_hidden_from_clients'] ?? false)],
            'task' => $attributes + ['client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'title' => $row['name'] ?? $row['title'] ?? 'External task', 'description' => $row['description'] ?? null, 'status' => self::sourceTimestamp($row['completed_at'] ?? null) !== null ? 'completed' : 'open', 'is_visible_to_client' => ! (bool) ($row['is_hidden_from_clients'] ?? false), 'completed_at' => self::sourceTimestamp($row['completed_at'] ?? null), 'milestone_price_amount' => ((float) ($row['milestone_price'] ?? 0)) > 0.0 ? self::minorUnits($row['milestone_price']) : null],
            'time_entry' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'client_task_id' => $this->internalId($destinationName, 'client_tasks', $task), 'user_id' => $this->internalId($destinationName, 'users', $user), 'worked_on' => self::sourceDate($row['date_worked'] ?? null), 'minutes' => (int) ($row['minutes_worked'] ?? 0), 'description' => $row['name'] ?? '', 'job_type' => $row['job_type'] ?? null, 'is_billable' => (bool) ($row['is_billable'] ?? true), 'is_deferred' => (bool) ($row['is_deferred_billing'] ?? false), 'billing_rate_amount' => null, 'currency' => self::sourceCurrency($row['currency'] ?? null), 'status' => ($row['approval_status'] ?? 'approved') === 'approved' ? 'approved' : 'draft', 'approved_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['approved_by_user_id'] ?? ''), $ledgerItems, $queryCache)), 'approved_at' => self::sourceTimestamp($row['approved_at'] ?? null)] + self::sourceSubcontractorAttributes($row),
            'proposal' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'title' => $row['title'] ?? 'External proposal', 'summary' => $row['body_markdown'] ?? null, 'currency' => self::sourceCurrency($row['currency'] ?? null), 'valid_until' => $row['expires_at'] ?? null, 'status' => $this->proposalStatus($row['status'] ?? 'draft'), 'accepted_at' => self::sourceTimestamp($row['accepted_at'] ?? null), 'accepted_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['accepted_by_user_id'] ?? ''), $ledgerItems, $queryCache)), 'acceptance_signer_name' => $row['accept_signature_name'] ?? null, 'acceptance_signer_title' => $row['accept_signature_title'] ?? null, 'is_visible_to_client' => (bool) ($row['is_visible_to_client'] ?? false), 'sent_at' => self::sourceTimestamp($row['sent_at'] ?? null)],
            'proposal_item' => $attributes + ['client_proposal_id' => $this->internalId($destinationName, 'client_proposals', $proposal), 'description' => $row['description'] ?? 'External proposal item', 'quantity' => $row['quantity'] ?? '1', 'unit_amount' => self::minorUnits($row['amount'] ?? null), 'cadence' => $row['charge_cadence'] ?? 'one_time', 'sort_order' => (int) ($row['sort_order'] ?? 0)],
            'agreement' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => null, 'source_proposal_id' => $this->internalId($destinationName, 'client_proposals', $proposal), 'title' => $row['title'] ?? 'External agreement', 'status' => self::sourceDate($row['termination_date'] ?? null) !== null ? 'terminated' : (self::sourceDate($row['active_date'] ?? null) !== null ? 'active' : 'draft'), 'starts_on' => self::sourceDate($row['active_date'] ?? null), 'ends_on' => self::sourceDate($row['termination_date'] ?? null), 'agreement_text' => $row['agreement_text'] ?? null, 'is_visible_to_client' => (bool) ($row['is_visible_to_client'] ?? false), 'currency' => self::sourceCurrency($row['currency'] ?? null), 'hourly_rate_amount' => self::nullableMinorUnits($row['hourly_rate'] ?? null), 'retainer_amount' => self::nullableMinorUnits($row['monthly_retainer_fee'] ?? $row['retainer_fee'] ?? null), 'retainer_minutes' => self::minutesFromDecimal($row['monthly_retainer_hours'] ?? $row['retainer_hours'] ?? null), 'billing_cadence' => $row['billing_cadence'] ?? 'monthly', 'activated_at' => self::sourceTimestamp($row['active_date'] ?? null), 'signed_at' => self::sourceTimestamp($row['client_company_signed_date'] ?? null), 'signed_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['client_company_signed_user_id'] ?? ''), $ledgerItems, $queryCache)), 'signer_name' => $row['client_company_signed_name'] ?? null, 'signer_title' => $row['client_company_signed_title'] ?? null, 'terminated_at' => self::sourceTimestamp($row['termination_date'] ?? null), 'catch_up_threshold_minutes' => self::minutesFromDecimal($row['catch_up_threshold_hours'] ?? null), 'period_retainer_minutes' => self::minutesFromDecimal($row['retainer_hours'] ?? null), 'period_retainer_amount' => self::nullableMinorUnits($row['retainer_fee'] ?? null), 'rollover_months' => isset($row['rollover_months']) ? (int) $row['rollover_months'] : null, 'initial_rollover_minutes' => self::minutesFromDecimal($row['initial_rollover_hours'] ?? null), 'bill_overage_interim' => isset($row['bill_overage_interim']) ? (bool) $row['bill_overage_interim'] : null, 'first_cycle_proration' => $row['first_cycle_proration'] ?? null, 'agreement_link' => $row['agreement_link'] ?? null],
            'agreement_recurring_item' => $attributes + ['client_agreement_id' => $this->internalId($destinationName, 'client_agreements', $agreement), 'description' => $row['description'] ?? 'External recurring item', 'amount' => self::minorUnits($row['amount'] ?? null), 'currency' => self::sourceCurrency($row['currency'] ?? null), 'cadence' => $row['charge_cadence'] ?? 'monthly', 'anchor_month' => $row['anchor_month'] ?? null, 'anchor_day' => $row['anchor_day'] ?? 1, 'effective_on' => self::sourceDate($row['start_date'] ?? null), 'expires_on' => self::sourceDate($row['end_date'] ?? null), 'is_taxable' => (bool) ($row['is_taxable'] ?? false), 'is_active' => ! isset($row['deleted_at'])],
            'invoice' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_agreement_id' => $this->internalId($destinationName, 'client_agreements', $agreement), 'invoice_number' => $this->invoiceNumber($row, $workspaceId, $destinationName), 'status' => in_array($row['status'] ?? 'draft', ['draft', 'issued', 'partially_paid', 'paid', 'void'], true) ? ($row['status'] ?? 'draft') : 'draft', 'issue_date' => self::sourceDate($row['issue_date'] ?? null), 'due_date' => self::sourceDate($row['due_date'] ?? null), 'service_period_start' => $row['period_start'] ?? null, 'service_period_end' => $row['period_end'] ?? null, 'currency' => self::sourceCurrency($row['currency'] ?? null), 'subtotal_amount' => self::minorUnits($row['invoice_total'] ?? null), 'total_amount' => self::minorUnits($row['invoice_total'] ?? null), 'paid_amount' => ($row['status'] ?? '') === 'paid' ? self::minorUnits($row['invoice_total'] ?? null) : 0, 'balance_amount' => ($row['status'] ?? '') === 'paid' ? 0 : self::minorUnits($row['invoice_total'] ?? null), 'notes' => $row['notes'] ?? null, 'is_visible_to_client' => ($row['status'] ?? 'draft') !== 'draft', 'invoice_kind' => $row['invoice_kind'] ?? null, 'cycle_start' => self::sourceDate($row['cycle_start'] ?? null), 'cycle_end' => self::sourceDate($row['cycle_end'] ?? null), 'paid_on' => self::sourceDate($row['paid_date'] ?? null), 'retainer_hours_included' => $row['retainer_hours_included'] ?? null, 'hours_worked' => $row['hours_worked'] ?? null, 'rollover_hours_used' => $row['rollover_hours_used'] ?? null, 'unused_hours_balance' => $row['unused_hours_balance'] ?? null, 'negative_hours_balance' => $row['negative_hours_balance'] ?? null, 'hours_billed_at_rate' => $row['hours_billed_at_rate'] ?? null, 'starting_unused_hours' => $row['starting_unused_hours'] ?? null, 'starting_negative_hours' => $row['starting_negative_hours'] ?? null],
            'invoice_line' => $attributes + ['client_invoice_id' => $this->internalId($destinationName, 'client_invoices', $invoice), 'description' => $row['description'] ?? 'External invoice line', 'type' => $row['line_type'] ?? 'adjustment', 'quantity' => self::invoiceLineQuantity($row['quantity'] ?? null), 'unit_amount' => self::minorUnits($row['unit_price'] ?? null), 'tax_amount' => 0, 'total_amount' => self::minorUnits($row['line_total'] ?? null), 'sort_order' => (int) ($row['sort_order'] ?? 0), 'line_date' => self::sourceDate($row['line_date'] ?? null), 'hours' => $row['hours'] ?? null, 'client_agreement_id' => $this->internalId($destinationName, 'client_agreements', $agreement), 'client_agreement_recurring_item_id' => $this->internalId($destinationName, 'client_agreement_recurring_items', $recurring)],
            'invoice_payment' => $attributes + ['client_invoice_id' => $this->internalId($destinationName, 'client_invoices', $invoice), 'status' => 'succeeded', 'amount' => self::minorUnits($row['amount'] ?? null), 'refunded_amount' => 0, 'currency' => self::sourceCurrency($row['currency'] ?? null), 'received_on' => self::sourceDate($row['payment_date'] ?? null), 'method' => $row['payment_method'] ?? 'external', 'reference' => $row['stripe_payment_intent_id'] ?? null, 'notes' => $row['notes'] ?? null, 'provider' => ($row['stripe_payment_intent_id'] ?? null) ? 'stripe' : null, 'provider_payment_identifier' => $row['stripe_payment_intent_id'] ?? null, 'external_finance_transaction_uuid' => null],
            'invoice_email_delivery' => $attributes + [
                'client_invoice_id' => $this->internalId($destinationName, 'client_invoices', $invoice),
                'recipients' => $this->jsonOrNull([
                    'to' => $this->decodeJson($row['to_recipients'] ?? null),
                    'cc' => $this->decodeJson($row['cc_recipients'] ?? null),
                    'bcc' => $this->decodeJson($row['bcc_recipients'] ?? null),
                ]),
                'subject' => (string) ($row['subject'] ?? ''),
                'status' => (string) ($row['status'] ?? 'queued'),
                'provider_message_reference' => $row['provider_message_id'] ?? $row['transport_message_id'] ?? null,
                'error_summary' => $row['last_event_reason'] ?? $row['note'] ?? null,
                'external_metadata' => $this->jsonOrNull([
                    'external_id' => isset($row['id']) ? (int) $row['id'] : null,
                    'queued_by_user_id' => isset($row['queued_by_user_id']) ? (int) $row['queued_by_user_id'] : null,
                    'mailer' => $row['mailer'] ?? null,
                    'provider' => $row['provider'] ?? null,
                    'transport_message_id' => $row['transport_message_id'] ?? null,
                    'note' => $row['note'] ?? null,
                    'last_event' => $row['last_event'] ?? null,
                    'last_event_at' => self::sourceTimestamp($row['last_event_at'] ?? null),
                    'last_status_checked_at' => self::sourceTimestamp($row['last_status_checked_at'] ?? null),
                    'delivery_events' => $this->decodeJson($row['delivery_events'] ?? null),
                    'provider_response' => $this->decodeJson($row['provider_response'] ?? null),
                ]),
                'queued_at' => self::sourceTimestamp($row['queued_at'] ?? null),
                'sent_at' => self::sourceTimestamp($row['sent_at'] ?? null),
                'failed_at' => self::sourceTimestamp($row['failed_at'] ?? null),
            ],
            'stripe_customer' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'stripe_customer_id' => $row['stripe_customer_id'] ?? null, 'metadata' => json_encode(['imported' => true], JSON_THROW_ON_ERROR)],
            'stripe_payment_method' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_stripe_customer_id' => $this->stripeCustomerInternalId($destinationName, $company), 'stripe_payment_method_id' => $row['stripe_payment_method_id'] ?? null, 'type' => $row['type'] ?? 'unknown', 'brand' => $row['brand'] ?? null, 'last4' => $row['last4'] ?? null, 'exp_month' => $row['exp_month'] ?? null, 'exp_year' => $row['exp_year'] ?? null, 'is_default' => (bool) ($row['is_default'] ?? false), 'metadata' => json_encode(['imported' => true], JSON_THROW_ON_ERROR)],
            'stripe_event' => $attributes + ['stripe_event_id' => $row['stripe_event_id'] ?? null, 'event_type' => $row['type'] ?? 'external.event', 'object_id' => $row['object_id'] ?? null, 'payload_hash' => Fingerprint::row($row), 'status' => 'received', 'processed_at' => self::sourceTimestamp($row['processed_at'] ?? null)],
            default => $attributes,
        };
    }

    /**
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     */
    private function resolveParentId(string $sourceTable, string $sourceKey, array $ledgerItems, array &$queryCache): ?string
    {
        if ($sourceKey === '') {
            return null;
        }

        $cacheKey = $this->ledgerItemKey($sourceTable, $sourceKey);
        if (array_key_exists($cacheKey, $queryCache['parent_ids'])) {
            return $queryCache['parent_ids'][$cacheKey];
        }
        $item = $ledgerItems[$cacheKey] ?? null;
        $targetPublicId = $item?->status === 'imported' ? $item->target_public_id : null;
        $queryCache['parent_ids'][$cacheKey] = $targetPublicId;

        return $targetPublicId;
    }

    private function internalId(string $destinationName, string $table, ?string $publicId): ?int
    {
        $cacheKey = $table."\0".($publicId ?? '');
        if (array_key_exists($cacheKey, $this->activeQueryCache['internal_ids'])) {
            return $this->activeQueryCache['internal_ids'][$cacheKey];
        }
        if ($publicId === null || $publicId === '' || ! $this->destinationTableExists($destinationName, $table, $this->activeQueryCache)) {
            $this->activeQueryCache['internal_ids'][$cacheKey] = null;

            return null;
        }

        $value = DB::connection($destinationName)->table($table)->where('public_id', $publicId)->value('id');
        $this->activeQueryCache['internal_ids'][$cacheKey] = $value === null ? null : (int) $value;

        return $this->activeQueryCache['internal_ids'][$cacheKey];
    }

    private function stripeCustomerInternalId(string $destinationName, ?string $companyPublicId): ?int
    {
        $companyId = $this->internalId($destinationName, 'client_companies', $companyPublicId);
        if ($companyId === null || ! $this->destinationTableExists($destinationName, 'client_stripe_customers', $this->activeQueryCache)) {
            return null;
        }

        $cacheKey = (string) $companyId;
        if (array_key_exists($cacheKey, $this->activeQueryCache['stripe_customer_ids'])) {
            return $this->activeQueryCache['stripe_customer_ids'][$cacheKey];
        }
        $value = DB::connection($destinationName)->table('client_stripe_customers')->where('client_company_id', $companyId)->value('id');
        $this->activeQueryCache['stripe_customer_ids'][$cacheKey] = $value === null ? null : (int) $value;

        return $this->activeQueryCache['stripe_customer_ids'][$cacheKey];
    }

    /**
     * @return QueryCache
     */
    private function newQueryCache(): array
    {
        return [
            'parent_ids' => [],
            'internal_ids' => [],
            'stripe_customer_ids' => [],
            'related_exists' => [],
            'target_exists' => [],
            'table_exists' => [],
            'table_columns' => [],
            'source_columns' => [],
            'sole_superseded_claim' => [],
            'agreement_context' => [],
        ];
    }

    /**
     * @return array<string, ExternalImportItem>
     */
    private function loadLedgerItems(ExternalImportRun $run, string $sourceTable, string $destinationName): array
    {
        $items = [];
        $query = (new ExternalImportItem)->setConnection($destinationName)->newQuery()
            ->where('source_identity_hash', $run->source_identity_hash)
            ->where('source_table', $sourceTable);

        foreach ($query->cursor() as $item) {
            $items[(string) $item->source_key] = $item;
        }

        return $items;
    }

    /** @param  QueryCache  $queryCache */
    private function destinationTableExists(string $destinationName, string $table, array &$queryCache): bool
    {
        if (! array_key_exists($table, $queryCache['table_exists'])) {
            $queryCache['table_exists'][$table] = Schema::connection($destinationName)->hasTable($table);
        }

        return $queryCache['table_exists'][$table] === true;
    }

    /**
     * @param  QueryCache  $queryCache
     * @return list<string>
     */
    private function destinationColumns(string $destinationName, string $table, array &$queryCache): array
    {
        if (! array_key_exists($table, $queryCache['table_columns'])) {
            $queryCache['table_columns'][$table] = $this->destinationTableExists($destinationName, $table, $queryCache)
                ? Schema::connection($destinationName)->getColumnListing($table)
                : [];
        }

        /** @var list<string> $columns */
        $columns = $queryCache['table_columns'][$table];

        return $columns;
    }

    /**
     * @param  QueryCache  $queryCache
     * @return list<string>
     */
    private function sourceColumns(string $sourceRuntimeName, string $table, array &$queryCache): array
    {
        if (! array_key_exists($table, $queryCache['source_columns'])) {
            $queryCache['source_columns'][$table] = Schema::connection($sourceRuntimeName)->hasTable($table)
                ? Schema::connection($sourceRuntimeName)->getColumnListing($table)
                : [];
        }

        /** @var list<string> $columns */
        $columns = $queryCache['source_columns'][$table];

        return $columns;
    }

    /**
     * Whether the spans in this text are the ones its cadence produces.
     *
     * A grammar can say two dates and a range; it cannot say how long a
     * quarter is. A cycle runs from its start to the day before the same day
     * $months later, and PeriodLabel writes a range only when that span
     * straddles months - so an aligned six-month cycle labels five months of
     * difference and an unaligned one labels six. Both are real; a year is
     * neither.
     *
     * Called for every template. The ones without a cadence still get their
     * dates checked for order, which is all that can be said about them.
     */
    private static function spansItsCadence(string $text, ?int $months): bool
    {
        preg_match_all('/('.self::SHORT_DATE.') through ('.self::SHORT_DATE.')/', $text, $spans, PREG_SET_ORDER);

        foreach ($spans as $span) {
            $from = Carbon::createFromFormat('M j, Y', $span[1]);
            $to = Carbon::createFromFormat('M j, Y', $span[2]);

            if ($from === null || $to === null || $from->greaterThan($to)) {
                return false;
            }

            // addMonths, not the no-overflow form: BillingCycleResolver uses
            // addMonths()->subDay(), so a cycle starting on January 31 ends on
            // April 30 rather than April 29, and a validator that disagreed
            // would refuse the very lines it was written to recognise.
            if ($months !== null && ! $to->isSameDay($from->copy()->addMonths($months)->subDay())) {
                return false;
            }
        }

        if ($months === null) {
            return true;
        }

        preg_match_all('/(\d{4})-(\d{2})\.\.(\d{4})-(\d{2})/', $text, $ranges, PREG_SET_ORDER);

        foreach ($ranges as $range) {
            $difference = ((int) $range[3] - (int) $range[1]) * 12 + ((int) $range[4] - (int) $range[2]);

            if ($difference !== $months && $difference !== $months - 1) {
                return false;
            }

            if ($difference !== $months - 1) {
                continue;
            }

            // One short of the cadence means the cycle runs whole calendar
            // months, which fixes both its ends: it starts on the first and
            // ends on the last of the month it names. PeriodLabel does not
            // range every such span - a calendar quarter is "2026-Q1" and a
            // calendar year is "2026" - so ask it, and refuse a range it would
            // have written another way. "2026-01..2026-12" is the one that
            // matters: the only annual cycle with those ends is the calendar
            // year, and the year form is what it is called.
            $start = Carbon::createFromFormat('Y-m-d', $range[1].'-'.$range[2].'-01');

            if ($start === null) {
                return false;
            }

            $start = $start->startOfDay();

            if (PeriodLabel::for($start, $start->copy()->addMonths($months)->subDay()) !== $range[0]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every period range here is one PeriodLabel would write.
     *
     * It writes a range only when the two ends are not the same month - a span
     * inside one month collapses to that month, and a backwards span is not a
     * span - so "2026-01..2026-01" is somebody's own text however well it
     * matches the grammar.
     */
    private static function periodRangesAreReal(string $text): bool
    {
        preg_match_all('/(\d{4}-\d{2})\.\.(\d{4}-\d{2})/', $text, $matches, PREG_SET_ORDER);

        foreach ($matches as $range) {
            if ($range[1] >= $range[2]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every date-shaped run in this text is a date Carbon could write.
     *
     * A pattern can say "Jan" and two digits; it cannot say that January has no
     * ninety-ninth. Only the formatter settles that, so the candidates are
     * handed back to it and required to come out unchanged. A description with
     * an impossible day is somebody's own text, and its figures stay put.
     */
    private static function datesAreReal(string $text): bool
    {
        preg_match_all('/'.self::SHORT_DATE.'/', $text, $matches);

        foreach ($matches[0] as $candidate) {
            $parsed = Carbon::createFromFormat('M j, Y', $candidate);

            if ($parsed === null || $parsed->format('M j, Y') !== $candidate) {
                return false;
            }
        }

        return true;
    }

    /**
     * A line description with the composer's own figures set aside.
     *
     * Regenerating a line rewrites the hours and money it quotes while the
     * words stay put, so two generations of one line differ only there. What
     * this must not do is decide which numbers are the mutable ones, and four
     * attempts to went wrong in four different ways: deleting every number
     * merged February 2024 with February 2025; keeping years merged 2026-01
     * with 2026-02; keeping the cycle labels PeriodLabel emits still merged
     * "Aug 1, 2026" with "Aug 15, 2026"; and keeping four-digit tokens read the
     * front of a 2000.00 rate as a year. Every round the source found another
     * way to write a date.
     *
     * So it does not classify numbers at all. The composer puts its figure at
     * the front of a parenthetical - "(9.9168)" becomes "(10.0000)", and
     * "(10.0000 applied to August 2026 pool)" names its pool after it - so only
     * that leading figure is set aside. Everything else, inside the group or
     * out, names the charge and has to match exactly.
     *
     * And only where a billing service wrote the description. Anchoring to its
     * templates is what stops "Support package (2025 tier)" being read as a
     * generated line with a figure in front: an operator's parenthetical can
     * start with a number that names the thing rather than prices it, and two
     * of those are two allocations. Anything that does not match a template is
     * compared exactly.
     *
     * A figure written anywhere else is therefore treated as identifying, and a
     * line that moves one is refused rather than recovered. That is the safe
     * direction: a refusal leaves work looking unbilled and reported, where a
     * wrong match marks it billed silently.
     */
    /**
     * What the source agreement a line falls under says about its own billing.
     *
     * The generators take the cadence word and the termination wording from
     * the agreement, so this is what says whether they could have written a
     * given description. Read from the source rather than from what was
     * imported: the recovery is reasoning about two source rows, and the
     * destination agreement may not exist yet when the line carrying the claim
     * is read.
     *
     * Held to the same fingerprint as any other row used as evidence. An
     * agreement edited since this run observed it - its cadence changed, say -
     * describes a contract the run never saw, and shaping a description
     * against it would recognise wording the imported snapshot could not have
     * produced.
     *
     * An absent cadence reads as monthly, which is what the importer and
     * ClientAgreement::effectiveBillingCadence() both do with one, and a good
     * deal of data relies on it. A cadence that was chosen and is not a
     * recurring one - `one_time` above all - is not monthly and is not
     * anything else either: billsOnARecurringCadence() stops the generator
     * before it writes a cycle line at all, so no cadence wording under such
     * an agreement is generated wording.
     *
     * Not filtered by deleted_at: a terminated agreement still says what its
     * lines were written under, and that is the whole point of asking.
     *
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     * @return array{months: int, terminated: bool}|null
     */
    private function sourceAgreementContext(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        string $agreementKey,
        array $ledgerItems,
        array &$queryCache,
    ): ?array {
        if ($agreementKey === '') {
            return null;
        }

        if (array_key_exists($agreementKey, $queryCache['agreement_context'])) {
            return $queryCache['agreement_context'][$agreementKey];
        }

        $columns = $this->sourceColumns($sourceRuntimeName, 'client_agreements', $queryCache);
        $context = null;

        if (in_array('id', $columns, true)) {
            $found = $source->table('client_agreements')->where('id', $agreementKey)->first();
            $row = $found === null ? null : (array) $found;

            if ($row !== null && $this->observedThisRun('client_agreements', $agreementKey, $row, $ledgerItems)) {
                $cadence = in_array('billing_cadence', $columns, true)
                    ? (string) ($row['billing_cadence'] ?? '')
                    : '';
                $months = $cadence === ''
                    ? BillingCadence::Monthly->monthsInCycle()
                    : BillingCadence::tryFrom($cadence)?->monthsInCycle();

                if ($months !== null) {
                    $context = [
                        'months' => $months,
                        'terminated' => in_array('termination_date', $columns, true)
                            && self::sourceDate($row['termination_date'] ?? null) !== null,
                    ];
                }
            }
        }

        $queryCache['agreement_context'][$agreementKey] = $context;

        return $context;
    }

    /** @param  array{months: int, terminated: bool}|null  $agreement */
    private static function descriptionShape(mixed $description, string $lineType, ?array $agreement): string
    {
        // Not trimmed at all. The composer writes no padding, so a description
        // carrying some is not one of its - and trimming to recognise it made
        // two custom lines differing only in whitespace into one.
        $text = (string) ($description ?? '');

        foreach (self::GENERATED_DESCRIPTION_TEMPLATES as $template => $rule) {
            // Bound to the type it belongs to. A service writes each of these
            // for one kind of line, so the same words on another kind are
            // somebody quoting them - and the termination template erases its
            // whole group, which is a costly thing to do to a line it does not
            // describe.
            if (! in_array($lineType, $rule['types'], true)) {
                continue;
            }

            // And bound to the cadence it names. createRetainerFeeLine and the
            // cycle draw both take that word from the agreement's own cadence,
            // so an annual wording under a monthly agreement is not something
            // either could have written - two such lines are somebody's prose
            // that happens to share a shape. An unknown cadence refuses the
            // same way: a billing claim is not worth guessing at.
            if (($rule['months'] ?? null) !== null && $rule['months'] !== ($agreement['months'] ?? null)) {
                continue;
            }

            // And bound to the circumstance it describes. Wording that says a
            // line was billed on termination is written on one path only, and
            // that path needs an agreement that ended.
            if (($rule['terminated'] ?? false) && ($agreement['terminated'] ?? false) !== true) {
                continue;
            }

            $wholeGroup = $rule['whole'];
            $months = $rule['months'] ?? null;

            if (preg_match($template, $text) !== 1
                || ! self::datesAreReal($text)
                || ! self::spansItsCadence($text, $months)
                || ! self::periodRangesAreReal($text)) {
                continue;
            }

            // The first group only. A later one is prose the service did not
            // write and has no claim to being an amount.
            return (string) preg_replace(
                $wholeGroup ? '/\([^()]*\)/' : '/\((\s*)-?\d[\d.,:]*/',
                $wholeGroup ? '(#)' : '($1#',
                $text,
                1,
            );
        }

        return $text;
    }

    /**
     * Take a milestone line for one task, if it is still there to take.
     *
     * Two guards saying the same thing, because either can be the one that
     * catches it. The predicate settles it inside a single statement where a
     * reader a statement earlier could be overtaken; the unique index settles
     * it when two statements are in flight at once. The caller reads a refusal
     * from either the same way.
     */
    private function reserveMilestoneLine(string $destinationName, int $workspaceId, int $taskId, int $lineId): int
    {
        return DB::connection($destinationName)->table('client_tasks')
            ->where('workspace_id', $workspaceId)
            ->where('id', $taskId)
            ->whereNull('client_invoice_line_id')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->fromSub(
                DB::connection($destinationName)->table('client_tasks')
                    ->select('id')
                    ->where('workspace_id', $workspaceId)
                    ->where('client_invoice_line_id', $lineId)
                    ->where('id', '!=', $taskId),
                'holders',
            ))
            ->update(['client_invoice_line_id' => $lineId]);
    }

    /**
     * How many claims the source holds on each line, deleted rows included.
     *
     * Read once per table rather than per row. Asking it per row meant a
     * workspace with a thousand billed milestones making a thousand round
     * trips to the source, each one scanning the same claims again, in the
     * middle of a pass that is otherwise streamed.
     *
     * @return array<string, int>
     */
    private function sourceClaimsByLine(ConnectionInterface $source, string $table): array
    {
        if (isset($this->sourceClaimsByLine[$table])) {
            return $this->sourceClaimsByLine[$table];
        }

        // Grouped in the source rather than counted here. Plucking every claim
        // materialised a whole billed-time history to answer a question about
        // a handful of lines.
        $counts = [];
        foreach ($source->table($table)
            ->selectRaw('client_invoice_line_id as line_key, count(*) as claims')
            ->whereNotNull('client_invoice_line_id')
            ->groupBy('client_invoice_line_id')
            ->get() as $row) {
            $counts[(string) $row->line_key] = (int) $row->claims;
        }

        return $this->sourceClaimsByLine[$table] = $counts;
    }

    /**
     * How many claims this run observed on each line, by the line's source key.
     *
     * Counted rather than flagged. A milestone task's claim is exclusive, so
     * two tasks naming one superseded line is not one claim seen twice - it is
     * two deliverables and no way to tell which the line billed. Collapsing
     * them to a single entry made that look unambiguous, and reconciliation
     * would then hand the survivor to whichever task came first by id.
     *
     * Kept per claimant table as well as in total, because the two questions
     * asked of it are different: whether any claim competes for a line, and
     * whether more than one exclusive claimant named it.
     *
     * @return array<string, array<string, int>>
     */
    private function observedClaimsByLine(): array
    {
        if ($this->observedClaimsByLine !== null) {
            return $this->observedClaimsByLine;
        }

        $byLine = ['*' => []];
        foreach (['client_time_entries', 'client_tasks'] as $table) {
            $byLine[$table] = [];
            foreach ($this->linkedSourceKeys[$table] ?? [] as $lineKey) {
                $byLine['*'][$lineKey] = ($byLine['*'][$lineKey] ?? 0) + 1;
                $byLine[$table][$lineKey] = ($byLine[$table][$lineKey] ?? 0) + 1;
            }
        }

        return $this->observedClaimsByLine = $byLine;
    }

    /**
     * Whether exactly one superseded line of this type is still claimed here.
     *
     * A line type is not always one line per invoice. A milestone is one line
     * per task and a subcontractor charge one per rate, so an invoice can carry
     * several superseded lines of a type that each stood for a different thing.
     * If two of them are still claimed and one live line survived, the
     * regenerated invoice dropped something - and resolving both claims to the
     * survivor would mark the dropped work billed, so nothing would ever charge
     * for it.
     *
     * Counting the claims rather than the lines is what keeps the ordinary case
     * working. An invoice regenerated twenty-one times carries twenty-one
     * superseded copies of the same aggregate line, but only the last of them
     * is named by anything, so the mapping is still one to one.
     *
     * @param  QueryCache  $queryCache
     */
    private function soleSupersededClaim(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        mixed $invoiceKey,
        string $lineType,
        array &$queryCache,
    ): bool {
        $memo = 'client_invoice_lines'."\0".((string) $invoiceKey)."\0".$lineType;
        if (array_key_exists($memo, $queryCache['sole_superseded_claim'])) {
            return $queryCache['sole_superseded_claim'][$memo];
        }

        $superseded = $source->table('client_invoice_lines')
            ->where('client_invoice_id', $invoiceKey)
            ->where('line_type', $lineType)
            ->whereNotNull('deleted_at')
            ->pluck('client_invoice_line_id')
            ->all();

        $claimed = [];

        if (count($superseded) > 1) {
            // Both what this run observed and what the source holds now, for
            // the reason the exclusive check needs both: a claimant deleted
            // between the two reads is gone from the source, and one deleted
            // before the run began was never observed. Either way its line had
            // a claim on it, and a survivor beside it is not the unambiguous
            // replacement it looks like.
            // The observed side is indexed once per run rather than walked per
            // question. Walking it per (invoice, type) meant every regenerated
            // invoice scanning every billed row in the source, which is
            // quadratic in exactly the history this exists to repair.
            $claimants = $this->observedClaimsByLine()['*'];

            foreach ($superseded as $key) {
                if (isset($claimants[(string) $key])) {
                    $claimed[(string) $key] = true;
                }
            }

            foreach (['client_time_entries', 'client_tasks'] as $table) {
                if (! in_array('client_invoice_line_id', $this->sourceColumns($sourceRuntimeName, $table, $queryCache), true)) {
                    continue;
                }

                $counts = $this->sourceClaimsByLine($source, $table);
                foreach ($superseded as $key) {
                    if (($counts[(string) $key] ?? 0) > 0) {
                        $claimed[(string) $key] = true;
                    }
                }
            }
        }

        return $queryCache['sole_superseded_claim'][$memo] = count($claimed) <= 1;
    }

    /**
     * The live invoice line that replaced a superseded one, when exactly one did.
     *
     * The source regenerates an invoice by soft-deleting its lines and inserting
     * fresh ones, and it does not repoint the rows that named the old line. So a
     * time entry or a milestone can name a line that no longer exists while the
     * work it records was billed - on that same invoice, by the line that
     * replaced it. One issued invoice in the migrated data carries two live
     * lines and forty-two superseded ones, and twenty live time entries still
     * name copies from earlier generations. Left unresolved they arrive here
     * unbilled, which is indistinguishable from work nobody has charged for, and
     * the next generation run charges for it again.
     *
     * The superseded line is read unfiltered, deliberately and as the only such
     * read: it is deleted at the source, it will never be imported, and the one
     * thing asked of it is which invoice it belonged to.
     *
     * The replacement has to be unambiguous in both directions, because
     * attaching work to a line that did not bill it suppresses a charge that is
     * owed - the same size of mistake as billing it twice. One direction is
     * that exactly one live line on that invoice shares the superseded line's
     * type. The other is that exactly one superseded line of that type is still
     * claimed: not every type is one line per invoice, and a type that is one
     * line per item - a subcontractor charge is one per rate - can have several
     * superseded lines standing for several different things. Collapsing those
     * onto the single line that survived would mark work billed that the
     * regenerated invoice dropped, and nothing would ever charge for it.
     *
     * This is for aggregate claims only. A milestone's claim was recovered here
     * too until it became clear that nothing available establishes which task
     * an unheld line belongs to: an invoice line carries no task reference, and
     * a description is the task's title, which nothing makes unique. Six rounds
     * of narrowing - the claimant, the holder at the source, the holder here,
     * the rival count, the title - each closed a case and left another, and the
     * last one has no evidence left to appeal to. A milestone whose line was
     * superseded is now reported unlinked rather than guessed at. It has never
     * arisen in the migrated data; the two milestones there link from live
     * lines in the ordinary way.
     *
     * Anything less than certain is refused and reported rather than guessed.
     *
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     */
    private function supersededLineId(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        string $supersededKey,
        string $destinationName,
        int $workspaceId,
        array $ledgerItems,
        array &$queryCache,
    ): ?int {
        if ($supersededKey === '') {
            return null;
        }

        $columns = $this->sourceColumns($sourceRuntimeName, 'client_invoice_lines', $queryCache);
        // The description is required where it is the evidence - an aggregate
        // claim has nothing but the type without it, and the type was never
        // enough. A milestone establishes identity another way, through an
        // exclusive claimant and an unheld line, so demanding a column it never
        // reads would only disable a recovery that is sound without it.
        foreach (['client_invoice_line_id', 'client_invoice_id', 'line_type', 'deleted_at', 'description', 'client_agreement_id'] as $required) {
            if (! in_array($required, $columns, true)) {
                return null;
            }
        }

        $superseded = $source->table('client_invoice_lines')
            ->where('client_invoice_line_id', $supersededKey)
            ->whereNotNull('deleted_at')
            ->first();

        if ($superseded === null) {
            return null;
        }

        $supersededRow = (array) $superseded;
        $invoiceKey = $supersededRow['client_invoice_id'] ?? null;
        $lineType = $supersededRow['line_type'] ?? null;

        if ($invoiceKey === null || $lineType === null) {
            return null;
        }

        // Where the claimant cannot establish identity, the type has to. One
        // superseded subcontractor line and one live one are two different
        // groups of work as often as they are the same line twice.
        if (! in_array((string) $lineType, self::IDENTIFIABLE_BY_DESCRIPTION, true)) {
            return null;
        }

        // Two is enough to know it is ambiguous, and stops an invoice with a
        // long line history being read in full to answer a yes-or-no question.
        $replacements = SourceRows::for($source, $sourceRuntimeName, 'client_invoice_lines', $columns)
            ->where('client_invoice_id', $invoiceKey)
            ->where('line_type', $lineType)
            ->limit(2)
            ->get();

        if ($replacements->count() !== 1) {
            return null;
        }

        if (! $this->soleSupersededClaim($source, $sourceRuntimeName, $invoiceKey, (string) $lineType, $queryCache)) {
            return null;
        }

        $replacement = (array) $replacements->first();
        $replacementKey = (string) ($replacement['client_invoice_line_id'] ?? '');

        // Same type is not the same line. Two retainer draws on one invoice are
        // two pools, and only the words say which - so the words have to agree,
        // with the figures the composer fills in set aside because those move
        // between generations of one line. Not every number, though: a retainer
        // description carries the cycle it is for, and February 2024 is not
        // February 2025.
        $agreement = $this->sourceAgreementContext(
            $source,
            $sourceRuntimeName,
            (string) ($supersededRow['client_agreement_id'] ?? ''),
            $ledgerItems,
            $queryCache,
        );
        $supersededShape = self::descriptionShape($supersededRow['description'] ?? null, (string) $lineType, $agreement);

        if ($supersededShape === ''
            || $supersededShape !== self::descriptionShape($replacement['description'] ?? null, (string) $lineType, $agreement)) {
            return null;
        }

        // And the same agreement. One invoice can carry lines from more than
        // one, and two agreements can have a line of the same type reading the
        // same way - the words describe the work, not which contract it fell
        // under. This is the one piece of identity the source records as a
        // reference rather than as prose, so it is used where it exists.
        // Both must name one, and the same one. Two nulls cast to '' compare
        // equal, which is not two lines agreeing about their agreement - it is
        // two lines saying nothing, and the templates without a cadence would
        // have taken that as identity established.
        $supersededAgreement = (string) ($supersededRow['client_agreement_id'] ?? '');

        if ($supersededAgreement === ''
            || $supersededAgreement !== (string) ($replacement['client_agreement_id'] ?? '')) {
            return null;
        }

        // Held to the same fingerprint check as the row carrying the link. A
        // replacement edited since this run observed it describes a snapshot
        // this run never saw, and a billing link must not be written from one.
        if (! $this->observedThisRun('client_invoice_lines', $replacementKey, $replacement, $ledgerItems)) {
            return null;
        }

        $publicId = $this->resolveParentId('client_invoice_lines', $replacementKey, $ledgerItems, $queryCache);

        if ($publicId === null) {
            return null;
        }

        // Resolved inside the workspace rather than resolved and then checked:
        // a ledger mapping that names another tenant's line has no answer here,
        // and asking for one and rejecting it afterwards leaves a row this run
        // had no business reading in a variable.
        $lineId = DB::connection($destinationName)->table('client_invoice_lines')
            ->where('workspace_id', $workspaceId)
            ->where('public_id', $publicId)
            ->value('id');

        return $lineId === null ? null : (int) $lineId;
    }

    /**
     * A reconciliation pass reads the source a second time, later than the read
     * importSpec() observed. Anything that changed in between - a row this run
     * refused as source_changed, or one edited in the gap between the two reads
     * - describes a snapshot this run never observed, and a billing link must
     * not be written from it.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, ExternalImportItem>  $ledgerItems
     */
    private function observedThisRun(string $sourceTable, string $sourceKey, array $row, array $ledgerItems): bool
    {
        $item = $ledgerItems[$this->ledgerItemKey($sourceTable, $sourceKey)] ?? null;

        return $item !== null && $item->source_fingerprint === Fingerprint::row($row);
    }

    /**
     * How many rows carried a link when this run observed them and do not carry
     * one now.
     *
     * Such a row never reaches the reconciliation loop at all - the second read
     * filters it out, whether the link was cleared or the row was deleted - so
     * there is nothing there to notice its absence. Silence would leave a
     * milestone unlinked while the run reported clean, and an unlinked
     * milestone is billed again.
     *
     * @param  array<string, true>  $seen
     * @param  array<string, mixed>  $counts
     */
    private function vanishedLinkCount(string $sourceTable, array $seen, array &$counts, string $reason): int
    {
        $vanished = 0;

        foreach (array_keys($this->linkedSourceKeys[$sourceTable] ?? []) as $sourceKey) {
            if (isset($seen[$sourceKey])) {
                continue;
            }

            $vanished++;
            $counts['skipped']++;
            $counts['failure_reasons'][$reason] = ($counts['failure_reasons'][$reason] ?? 0) + 1;
        }

        return $vanished;
    }

    /**
     * The ledger is keyed on the source identity rather than on a workspace, so
     * a public id can resolve to a row owned by a tenant this run is not
     * importing into. A foreign key here is not workspace-composite, so nothing
     * below the application stops one tenant's row pointing at another's.
     */
    private function ownedByRunWorkspace(string $destinationName, string $table, int $id, int $workspaceId): bool
    {
        return DB::connection($destinationName)->table($table)
            ->where('id', $id)
            ->where('workspace_id', $workspaceId)
            ->exists();
    }

    private function ledgerItemKey(string $sourceTable, string $sourceKey): string
    {
        return $sourceTable."\0".$sourceKey;
    }

    private function recordRunItem(ExternalImportRun $run, ExternalImportItem $item, string $status, string $fingerprint, string $destinationName): void
    {
        DB::connection($destinationName)->table('external_import_run_items')->updateOrInsert(
            [
                'external_import_run_id' => $run->getKey(),
                'external_import_item_id' => $item->getKey(),
            ],
            [
                'observed_status' => $status,
                'source_fingerprint' => $fingerprint,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ],
        );
    }

    private function reconcileImportedInvoices(ExternalImportRun $run, string $destinationName): void
    {
        $invoicePublicIds = (new ExternalImportItem)->setConnection($destinationName)->newQuery()
            ->where('source_identity_hash', $run->source_identity_hash)
            ->where('target_type', 'invoice')
            ->where('status', 'imported')
            ->whereNotNull('target_public_id')
            ->pluck('target_public_id');

        foreach ($invoicePublicIds as $publicId) {
            $invoice = DB::connection($destinationName)->table('client_invoices')
                ->where('workspace_id', $run->workspace_id)
                ->where('public_id', $publicId)
                ->first();
            if ($invoice === null || $invoice->status === 'void') {
                continue;
            }

            $paidFromPayments = (int) DB::connection($destinationName)->table('client_invoice_payments')
                ->where('workspace_id', $run->workspace_id)
                ->where('client_invoice_id', $invoice->id)
                ->where('status', 'succeeded')
                ->selectRaw('COALESCE(SUM(amount - refunded_amount), 0) as total')
                ->value('total');
            $total = max(0, (int) $invoice->total_amount);
            // Monotonic: the imported paid_amount (derived from the source status)
            // is a floor. Zero imported payment rows means the payments did not
            // import or the invoice was settled offline — never evidence that a
            // paid invoice is collectible again.
            $paid = min($total, max((int) $invoice->paid_amount, max(0, $paidFromPayments)));
            $status = $paid >= $total && $total > 0
                ? 'paid'
                : ($paid > 0 ? 'partially_paid' : $invoice->status);

            if ($paid === (int) $invoice->paid_amount && $status === $invoice->status) {
                continue;
            }

            DB::connection($destinationName)->table('client_invoices')->where('id', $invoice->id)->update([
                'status' => $status,
                'paid_amount' => $paid,
                'balance_amount' => ClientInvoice::balanceOwed($total, $paid),
                'is_visible_to_client' => $status !== 'draft' ? true : (bool) $invoice->is_visible_to_client,
                'updated_at' => $this->clock->now(),
            ]);
        }
    }

    /**
     * Externally imported invoices stored their billed-time relationship on the
     * time-entry row. SVC intentionally models that as a many-to-many link, so
     * reconcile it after all parent importers have established their public-id
     * mappings.
     *
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     * @param  array{source_rows:int,inserted:int,idempotent:int,recovered:int,rejected:int,failed:int}  $linkCounts
     * @param  array<string, mixed>  $counts
     */
    private function reconcileTimeEntryInvoiceLinks(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        ExternalImportRun $run,
        string $destinationName,
        array $ledgerItems,
        array &$queryCache,
        array &$linkCounts,
        array &$counts,
    ): void {
        if (! Schema::connection($sourceRuntimeName)->hasColumn('client_time_entries', 'client_invoice_line_id')) {
            return;
        }

        // Streamed, not materialised. Fingerprinting needs every column, and a
        // long billed-time history would otherwise be held in memory in full
        // after the imports have already succeeded.
        $rows = SourceRows::for($source, $sourceRuntimeName, 'client_time_entries')
            ->whereNotNull('client_invoice_line_id')
            ->orderBy('id')
            ->cursor();

        $seen = [];

        foreach ($rows as $rawRow) {
            $linkCounts['source_rows']++;
            $row = (array) $rawRow;
            $sourceKey = (string) ($row['id'] ?? '');
            $seen[$sourceKey] = true;

            if (! $this->observedThisRun('client_time_entries', $sourceKey, $row, $ledgerItems)) {
                $linkCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['time_link_source_changed'] = ($counts['failure_reasons']['time_link_source_changed'] ?? 0) + 1;

                continue;
            }

            $timePublicId = $this->resolveParentId('client_time_entries', $sourceKey, $ledgerItems, $queryCache);
            $linePublicId = $this->resolveParentId('client_invoice_lines', (string) ($row['client_invoice_line_id'] ?? ''), $ledgerItems, $queryCache);
            $timeId = $this->internalId($destinationName, 'client_time_entries', $timePublicId);
            $lineId = $this->internalId($destinationName, 'client_invoice_lines', $linePublicId);
            $recovering = false;

            // An entry naming a line the source has superseded is billed work,
            // not unbilled work, so follow the claim to the line that replaced
            // it rather than dropping it and letting it be charged again.
            if ($timeId !== null && $lineId === null) {
                $lineId = $this->supersededLineId($source, $sourceRuntimeName, (string) ($row['client_invoice_line_id'] ?? ''), $destinationName, (int) $run->workspace_id, $ledgerItems, $queryCache);
                $recovering = $lineId !== null;
            }

            if ($timeId === null || $lineId === null) {
                $linkCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['missing_invoice_time_link_parent'] = ($counts['failure_reasons']['missing_invoice_time_link_parent'] ?? 0) + 1;

                continue;
            }

            if (! $this->ownedByRunWorkspace($destinationName, 'client_time_entries', $timeId, (int) $run->workspace_id)
                || ! $this->ownedByRunWorkspace($destinationName, 'client_invoice_lines', $lineId, (int) $run->workspace_id)) {
                $linkCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['time_link_outside_workspace'] = ($counts['failure_reasons']['time_link_outside_workspace'] ?? 0) + 1;

                continue;
            }

            // Fill a hole only, the same rule the milestone pass follows, and
            // for the same reason: repointing a billed row is not a decision an
            // import pass gets to make. Asking only whether this exact pair
            // exists is not enough - the pivot is unique on the entry, so an
            // entry an operator billed onto some other line in the gap between
            // the two reads would take the insert into a constraint violation
            // and throw the whole run after earlier tables had committed.
            $held = DB::connection($destinationName)->table('client_invoice_line_time_entries')
                ->where('workspace_id', $run->workspace_id)
                ->where('client_time_entry_id', $timeId)
                ->value('client_invoice_line_id');

            if ($held !== null) {
                if ((int) $held === $lineId) {
                    $linkCounts['idempotent']++;

                    continue;
                }

                // The destination says this work was billed somewhere else.
                // Leaving it alone is right, but the run has not reconciled the
                // link and must not report as though it had.
                $linkCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['time_link_destination_claims_another_line'] = ($counts['failure_reasons']['time_link_destination_claims_another_line'] ?? 0) + 1;

                continue;
            }

            $query = DB::connection($destinationName)->table('client_invoice_line_time_entries');

            // The read above narrows the window; it cannot close it. The pivot
            // is unique on the entry, so an operator billing it in the gap
            // still collides - and an uncaught collision throws the whole run
            // after earlier tables have committed. The constraint is the
            // arbiter, and losing to it means the same thing the read meant.
            try {
                $query->insert([
                    'workspace_id' => $run->workspace_id,
                    'client_invoice_line_id' => $lineId,
                    'client_time_entry_id' => $timeId,
                    'created_at' => $this->clock->now(),
                    'updated_at' => $this->clock->now(),
                ]);
            } catch (QueryException $exception) {
                // The line can also go between the checks above and this
                // insert - a draft regenerated in the gap takes its lines with
                // it - and that arrives as a foreign key failure rather than a
                // duplicate. It means the parent is missing, which is what this
                // run would have reported had it looked a moment later.
                if (! $exception instanceof UniqueConstraintViolationException) {
                    // Only the line is asked about, because only the line is
                    // constrained: the pivot deliberately carries no foreign
                    // key to client_time_entries, which the engagement slice
                    // migrates independently. An entry deleted in the gap does
                    // not raise this.
                    //
                    // Scoped, like every other tenant-owned read here: a row
                    // this workspace does not own is not this run's parent,
                    // whatever the ownership check a moment earlier concluded.
                    $lineStillThere = DB::connection($destinationName)->table('client_invoice_lines')
                        ->where('workspace_id', $run->workspace_id)
                        ->where('id', $lineId)
                        ->exists();

                    if ($lineStillThere) {
                        throw $exception;
                    }

                    $linkCounts['failed']++;
                    $counts['failed']++;
                    $counts['failure_reasons']['missing_invoice_time_link_parent'] = ($counts['failure_reasons']['missing_invoice_time_link_parent'] ?? 0) + 1;

                    continue;
                }

                // Losing the race does not say what won it. The other writer
                // may have written the very row this was about to, and
                // reporting a skip then would end an otherwise reconciled run
                // with skips over work that was in fact done.
                $winner = DB::connection($destinationName)->table('client_invoice_line_time_entries')
                    ->where('workspace_id', $run->workspace_id)
                    ->where('client_time_entry_id', $timeId)
                    ->value('client_invoice_line_id');

                if ($winner !== null && (int) $winner === $lineId) {
                    $linkCounts['idempotent']++;

                    continue;
                }

                $linkCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['time_link_destination_claims_another_line'] = ($counts['failure_reasons']['time_link_destination_claims_another_line'] ?? 0) + 1;

                continue;
            }

            $linkCounts['inserted']++;

            // Counted here rather than where the candidate was found: a claim
            // that was identified and then lost the pivot, or whose line went
            // before the insert, was not recovered.
            if ($recovering) {
                $linkCounts['recovered']++;
            }
        }

        $linkCounts['rejected'] += $this->vanishedLinkCount('client_time_entries', $seen, $counts, 'time_link_vanished');
    }

    /**
     * A milestone task records which invoice line billed it. Without that link,
     * the next generation run finds every completed priced task unbilled and
     * charges for it a second time - so this is reconstructed from the source
     * rather than left null, and it runs after the invoice lines have their
     * public-id mappings.
     *
     * Unlike time entries, a milestone is one deliverable at one price and
     * cannot be split, so the link is a column rather than a pivot row.
     *
     * @param  array<string, ExternalImportItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     * @param  array{source_rows:int,linked:int,idempotent:int,rejected:int,failed:int}  $milestoneCounts
     * @param  array<string, mixed>  $counts
     */
    private function reconcileMilestoneTaskInvoiceLinks(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        ExternalImportRun $run,
        string $destinationName,
        array $ledgerItems,
        array &$queryCache,
        array &$milestoneCounts,
        array &$counts,
    ): void {
        if (! Schema::connection($sourceRuntimeName)->hasColumn('client_tasks', 'client_invoice_line_id')) {
            return;
        }

        $rows = SourceRows::for($source, $sourceRuntimeName, 'client_tasks')
            ->whereNotNull('client_invoice_line_id')
            ->orderBy('id')
            ->cursor();

        $seen = [];

        foreach ($rows as $rawRow) {
            $milestoneCounts['source_rows']++;
            $row = (array) $rawRow;
            $sourceKey = (string) ($row['id'] ?? '');
            $seen[$sourceKey] = true;

            // A rejected link is not a cosmetic skip: the milestone stays
            // unlinked, and an unlinked completed milestone is one the next
            // generation run charges for again. The run must not report clean.
            if (! $this->observedThisRun('client_tasks', $sourceKey, $row, $ledgerItems)) {
                $milestoneCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['milestone_link_source_changed'] = ($counts['failure_reasons']['milestone_link_source_changed'] ?? 0) + 1;

                continue;
            }

            $taskPublicId = $this->resolveParentId('client_tasks', $sourceKey, $ledgerItems, $queryCache);
            $linePublicId = $this->resolveParentId('client_invoice_lines', (string) ($row['client_invoice_line_id'] ?? ''), $ledgerItems, $queryCache);
            $taskId = $this->internalId($destinationName, 'client_tasks', $taskPublicId);
            $lineId = $this->internalId($destinationName, 'client_invoice_lines', $linePublicId);

            if ($taskId === null || $lineId === null) {
                $milestoneCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['missing_milestone_invoice_link_parent'] = ($counts['failure_reasons']['missing_milestone_invoice_link_parent'] ?? 0) + 1;

                continue;
            }

            // Both sides, not just the task. Scoping only the row being written
            // still lets a task in this workspace point at another tenant's
            // invoice line, and the foreign key is not workspace-composite.
            if (! $this->ownedByRunWorkspace($destinationName, 'client_tasks', $taskId, (int) $run->workspace_id)
                || ! $this->ownedByRunWorkspace($destinationName, 'client_invoice_lines', $lineId, (int) $run->workspace_id)) {
                $milestoneCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['milestone_link_outside_workspace'] = ($counts['failure_reasons']['milestone_link_outside_workspace'] ?? 0) + 1;

                continue;
            }

            // Only ever fill a hole, and decide that in the write rather than
            // in a read before it: an operator issuing an invoice between the
            // two would otherwise have their link replaced by this one. Leaving
            // updated_at alone keeps the source date attributes() imported -
            // reconstructing a link is not the source editing the row.
            // A milestone line stands for one deliverable, and the schema
            // indexes the column without constraining it, so nothing below the
            // application stops two tasks holding one line. The availability
            // question is therefore asked by the write rather than before it:
            // a reader that answered it a statement earlier can be overtaken.
            // The predicate and the constraint answer the same question, and
            // either can be the one that answers it: losing to the index throws
            // where losing to the predicate returns zero. Both mean the line
            // was taken, so both are read the same way rather than one of them
            // failing the whole run after earlier tables have committed.
            // Two tasks naming one line is two deliverables with one line
            // between them. Left to the write, the lower id would take it and
            // the constraint would reject the other - and nothing here says the
            // lower id is the one the line billed.
            //
            // Counted in the source unfiltered as well as among what this run
            // observed. A task deleted before the run is not a rival for being
            // billed again, but it may be the deliverable the line paid for,
            // and handing that line to the survivor would mark the survivor
            // billed for work of its own that nothing has charged.
            $claimedLine = (string) ($row['client_invoice_line_id'] ?? '');
            if (max(
                $this->observedClaimsByLine()['client_tasks'][$claimedLine] ?? 0,
                $this->sourceClaimsByLine($source, 'client_tasks')[$claimedLine] ?? 0,
            ) > 1) {
                $milestoneCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['milestone_link_claimed_by_two_tasks'] = ($counts['failure_reasons']['milestone_link_claimed_by_two_tasks'] ?? 0) + 1;

                continue;
            }

            try {
                $updated = $this->reserveMilestoneLine($destinationName, (int) $run->workspace_id, $taskId, $lineId);
            } catch (UniqueConstraintViolationException) {
                $updated = 0;
            } catch (QueryException $exception) {
                // The line can go between the checks above and this write, the
                // way it can on the time-entry side, and that arrives as a
                // foreign key failure. It means the parent is missing.
                if (DB::connection($destinationName)->table('client_invoice_lines')
                    ->where('workspace_id', $run->workspace_id)
                    ->where('id', $lineId)
                    ->exists()) {
                    throw $exception;
                }

                $milestoneCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['missing_milestone_invoice_link_parent'] = ($counts['failure_reasons']['missing_milestone_invoice_link_parent'] ?? 0) + 1;

                continue;
            }

            if ($updated === 0) {
                // Nothing written, for one of two reasons: this task already
                // had a link, or somebody else took the line. They are not the
                // same outcome and must not report as one.
                $current = DB::connection($destinationName)->table('client_tasks')
                    ->where('workspace_id', $run->workspace_id)
                    ->where('id', $taskId)
                    ->value('client_invoice_line_id');

                if ($current !== null) {
                    if ((int) $current === $lineId) {
                        $milestoneCounts['idempotent']++;

                        continue;
                    }

                    // The destination says this deliverable was billed on some
                    // other line. Leaving it alone is right - repointing a
                    // billed row is not an import's decision - but the run has
                    // not reconciled the claim and must not report as if it
                    // had, which is how the time-entry pass reads the same
                    // disagreement.
                    $milestoneCounts['rejected']++;
                    $counts['skipped']++;
                    $counts['failure_reasons']['milestone_link_destination_claims_another_line'] = ($counts['failure_reasons']['milestone_link_destination_claims_another_line'] ?? 0) + 1;

                    continue;
                }

                $milestoneCounts['rejected']++;
                $counts['skipped']++;
                $counts['failure_reasons']['milestone_link_taken_by_another_task'] = ($counts['failure_reasons']['milestone_link_taken_by_another_task'] ?? 0) + 1;

                continue;
            }

            $milestoneCounts['linked']++;
        }

        $milestoneCounts['rejected'] += $this->vanishedLinkCount('client_tasks', $seen, $counts, 'milestone_link_vanished');
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $row
     */
    private function recordFailure(ExternalImportRun $run, ExternalImportItem $item, array $spec, string $sourceKey, string $fingerprint, string $reason, array $row, string $destinationName): void
    {
        $keyHash = hash('sha256', $sourceKey);
        $context = ['source_key_hash' => $keyHash, 'source_row_fingerprint' => $fingerprint, 'source_field_count' => count($row)];
        $failureFingerprint = Fingerprint::row(['table' => $spec['source_table'], 'key_hash' => $keyHash, 'reason' => $reason, 'row' => $fingerprint]);
        (new ExternalImportFailure)->setConnection($destinationName)->newQuery()->updateOrCreate(
            ['external_import_run_id' => $run->getKey(), 'source_table' => $spec['source_table'], 'source_key_hash' => $keyHash, 'reason_code' => $reason],
            ['external_import_item_id' => $item->getKey(), 'source_connection' => $run->source_connection, 'redacted_context' => $context, 'failure_fingerprint' => $failureFingerprint],
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, array<string, mixed>>  $inventory
     */
    private function newRun(array $source, Workspace $workspace, array $inventory, string $destinationName): ExternalImportRun
    {
        $marks = [];
        $fingerprints = [];
        foreach ($inventory as $table => $details) {
            $marks[$table] = $details['high_water_mark'];
            $fingerprints[$table] = $details['fingerprint'];
        }

        return (new ExternalImportRun)->setConnection($destinationName)->newQuery()->create([
            'workspace_id' => $workspace->getKey(),
            'source_connection' => $source['connection'],
            'source_identity_hash' => $source['identity_hash'],
            'mode' => 'apply',
            'status' => 'running',
            'source_high_water_marks' => $marks,
            'counts' => $this->emptyCounts($inventory),
            'fingerprints' => $fingerprints,
            'started_at' => $this->clock->now($workspace),
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, array<string, mixed>>  $inventory
     * @param  list<array<string, mixed>>  $specs
     * @return array<string, mixed>
     */
    private function summary(array $source, Workspace $workspace, array $inventory, array $specs, bool $apply): array
    {
        $counts = ['source_rows' => array_sum(array_column($inventory, 'row_count')), 'planned' => 0, 'imported' => 0, 'skipped' => 0, 'planned_copy' => 0, 'planned_reference' => 0, 'failed' => 0, 'idempotent' => 0, 'failure_reasons' => []];
        foreach ($specs as $spec) {
            $details = $inventory[$spec['source_table']] ?? null;
            if (! $details) {
                continue;
            }
            if ($spec['action'] === 'planned_copy') {
                $counts['planned_copy'] += $details['row_count'];
            } elseif ($spec['action'] === 'planned_reference') {
                $counts['planned_reference'] += $details['row_count'];
            } else {
                $counts['planned'] += $details['row_count'];
            }
        }

        return [
            'source' => $source['key'],
            'workspace_public_id' => $workspace->public_id,
            'mode' => $apply ? 'apply' : 'dry-run',
            'redacted' => true,
            'inventory' => $inventory,
            'counts' => $counts,
            'fingerprints' => array_map(fn (array $details): string => $details['fingerprint'], $inventory),
            'high_water_marks' => array_map(fn (array $details): array => $details['high_water_mark'], $inventory),
        ];
    }

    private function workspace(string $identifier, string $connection): Workspace
    {
        $workspace = Workspace::on($connection)->where(function ($query) use ($identifier): void {
            $query->where('public_id', $identifier)->orWhere('slug', $identifier);
        })->first();
        if (! $workspace) {
            throw new SourceConfigurationException('workspace_not_found');
        }

        return $workspace;
    }

    private function destinationName(): string
    {
        return (string) (Config::get('external-import.destination_connection') ?: Config::get('database.default'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @return ImportCounts
     */
    private function emptyCounts(array $inventory): array
    {
        return [
            'source_rows' => array_sum(array_column($inventory, 'row_count')),
            'planned' => 0,
            'imported' => 0,
            'skipped' => 0,
            'planned_copy' => 0,
            'planned_reference' => 0,
            'failed' => 0,
            'idempotent' => 0,
            'deleted_at_source' => 0,
            'failure_reasons' => [],
        ];
    }

    private function targetTableForType(string $type, ?string $sourceTable = null): ?string
    {
        $matches = [];
        foreach ($this->registry->all() as $spec) {
            if ($spec['target_type'] !== $type) {
                continue;
            }
            if ($sourceTable !== null && $spec['source_table'] !== $sourceTable) {
                continue;
            }
            $matches[] = $spec['target_table'];
        }

        $matches = array_values(array_unique($matches));

        return count($matches) === 1 ? (string) $matches[0] : null;
    }

    private function safeSlug(string $value, string $sourceKey): string
    {
        $slug = Str::slug($value) ?: 'external-company';

        return $slug.'-external-'.substr(hash('sha256', $sourceKey), 0, 10);
    }

    /** @param array<string, mixed> $row */
    private function invoiceNumber(array $row, int $workspaceId, string $destinationName): string
    {
        $sourceKey = (string) ($row['client_invoice_id'] ?? $row['id'] ?? 'unknown');
        $base = trim((string) ($row['invoice_number'] ?? ''));
        $base = $base !== '' ? $base : 'EXTERNAL-'.$sourceKey;
        $base = Str::substr($base, 0, 80);

        $exists = DB::connection($destinationName)->table('client_invoices')
            ->where('workspace_id', $workspaceId)
            ->where('invoice_number', $base)
            ->exists();
        if (! $exists) {
            return $base;
        }

        $suffix = '-external-'.substr(hash('sha256', $sourceKey), 0, 16);

        return Str::substr($base, 0, 80 - Str::length($suffix)).$suffix;
    }

    private function proposalStatus(string $status): string
    {
        return match ($status) {
            'accepted' => 'accepted', 'sent' => 'sent', 'expired' => 'expired', 'declined', 'rejected' => 'declined', default => 'draft',
        };
    }

    private function decodeJson(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /** @param array<string, mixed> $value */
    private function jsonOrNull(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * NULL/'' means "not configured" for nullable money columns; mapping it to 0
     * would turn an unset rate into a legitimate-looking $0.00.
     */
    private static function nullableMinorUnits(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return self::minorUnits($value);
    }

    /**
     * Carry the predecessor's per-entry billing snapshot without inferring a
     * billable shape from incomplete terms. Older rows used the rate itself as
     * the flat-hourly signal, which is the compatibility case retained here.
     *
     * @param  array<string, mixed>  $row
     * @return array{subcontractor_billing_mode:string|null, subcontractor_cost_amount:int|null, subcontractor_cost_currency:string|null}
     */
    private static function sourceSubcontractorAttributes(array $row): array
    {
        $rawMode = strtolower(trim((string) ($row['subcontractor_billing_mode'] ?? '')));
        $cost = self::nullableMinorUnits($row['subcontractor_hourly_rate'] ?? null);

        if ($rawMode === '') {
            $mode = $cost === null ? null : SubcontractorBillingMode::FlatHourly;
        } else {
            $mode = SubcontractorBillingMode::tryFrom($rawMode);
            if (! $mode instanceof SubcontractorBillingMode) {
                throw new \UnexpectedValueException('The source time entry has an unsupported subcontractor billing mode.');
            }
        }

        if ($mode === SubcontractorBillingMode::FlatHourly && $cost === null) {
            throw new \UnexpectedValueException('Flat-hourly source time requires a snapshotted rate.');
        }

        if ($mode !== null && $mode !== SubcontractorBillingMode::FlatHourly && $cost !== null) {
            throw new \UnexpectedValueException('Only flat-hourly source time may carry a subcontractor rate.');
        }

        return [
            'subcontractor_billing_mode' => $mode?->value,
            'subcontractor_cost_amount' => $cost,
            'subcontractor_cost_currency' => $cost === null ? null : self::sourceCurrency($row['currency'] ?? null),
        ];
    }

    /**
     * A source timestamp, or null where the source is not really carrying one.
     *
     * MySQL's zero date is a legal value there and rejected outright by a
     * strict destination, and importRow()'s null-coalescing fallback does not
     * see it - so copying it verbatim fails the insert, fails the row, and
     * cascades into every child that needed it as a parent.
     */
    private static function sourceTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' || str_starts_with($text, '0000-00-00') ? null : $text;
    }

    /**
     * A source date, normalised the same way, truncated to the day.
     */
    /**
     * A source currency, never blank.
     *
     * Null-coalescing catches a missing column and not an empty one, and a
     * blank currency is worse than a missing one: InvoiceLineComposer skips its
     * cross-currency refusal when the cost currency is empty, so a rate of
     * unknown denomination would be billed as though it were the invoice's.
     */
    private static function sourceCurrency(mixed $value): string
    {
        $text = strtoupper(trim((string) ($value ?? '')));

        return $text === '' ? 'USD' : $text;
    }

    private static function sourceDate(mixed $value): ?string
    {
        $text = self::sourceTimestamp($value);

        return $text === null ? null : substr($text, 0, 10);
    }

    private static function minorUnits(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        $text = str_replace([',', '$', ' '], '', trim((string) $value));
        $negative = str_starts_with($text, '-');
        $text = ltrim($text, '+-');
        if (preg_match('/^\d+(\.\d+)?$/', $text) !== 1) {
            // Fail the row loudly: silently mis-parsing a money string (e.g. a
            // stray currency code) would corrupt receivables while verify() —
            // which only checks row existence — still reports ok.
            throw new \InvalidArgumentException('Unparseable external money value.');
        }
        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $cents = ((int) $whole * 100) + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $negative ? -$cents : $cents;
    }

    private static function invoiceLineQuantity(mixed $value): string
    {
        $text = strtolower(trim((string) ($value ?? '')));
        if ($text === '') {
            return '1';
        }
        if (preg_match('/^[+-]?\d+(?:\.\d{1,4})?$/', $text) === 1) {
            return $text;
        }
        if (preg_match('/^(\d+):(\d{1,2})$/', $text, $matches) === 1) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];

            return number_format((($hours * 60) + $minutes) / 60, 4, '.', '');
        }
        if (preg_match('/^(\d+(?:\.\d{1,4})?)h$/', $text, $matches) === 1) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('Unparseable external invoice-line quantity.');
    }

    /** NULL/'' means the optional hours term was not configured. */
    private static function minutesFromDecimal(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new \InvalidArgumentException('External hours must be numeric.');
        }

        $text = trim(is_string($value) ? $value : (string) $value);
        if ($text === '') {
            return null;
        }
        if (! is_numeric($text)) {
            throw new \InvalidArgumentException('External hours must be numeric.');
        }

        return (int) round(((float) $text) * 60);
    }
}
