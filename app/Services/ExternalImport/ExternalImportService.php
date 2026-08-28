<?php

namespace App\Services\ExternalImport;

use App\Models\ClientInvoice;
use App\Models\ExternalImportFailure;
use App\Models\ExternalImportItem;
use App\Models\ExternalImportRun;
use App\Models\Workspace;
use Illuminate\Database\ConnectionInterface;
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
 *     sole_superseded_claim: array<array-key, bool>
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
     * Line types that stand for a whole invoice rather than one item on it.
     *
     * InvoiceLineComposer emits each of these once per invoice. The rest it
     * emits from a loop - a milestone per task, a subcontractor charge per
     * (user, project, rate, currency) group, a recurring_item per item - so two
     * lines of one of those types are two different things, and which one a
     * superseded line stood for cannot be read off its type.
     *
     * The distinction only has to be made where the claimant cannot make it. A
     * milestone task's claim is exclusive, so counting claims settles the
     * identity; a time entry's is not, so nothing but the type is left, and the
     * type is not enough.
     *
     * @var list<string>
     */
    private const WHOLE_INVOICE_LINE_TYPES = [
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
        $milestoneCounts = ['source_rows' => 0, 'linked' => 0, 'idempotent' => 0, 'recovered' => 0, 'rejected' => 0, 'failed' => 0];
        $this->linkedSourceKeys = [];
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
                'completed_at' => now(),
            ])->save();
        } catch (Throwable) {
            $run->forceFill(['counts' => $counts, 'status' => 'failed', 'completed_at' => now()])->save();
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
            $attributes['created_at'] ??= now();
        }
        if (in_array('updated_at', $columns, true)) {
            $attributes['updated_at'] ??= now();
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
            'time_entry' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'client_task_id' => $this->internalId($destinationName, 'client_tasks', $task), 'user_id' => $this->internalId($destinationName, 'users', $user), 'worked_on' => self::sourceDate($row['date_worked'] ?? null), 'minutes' => (int) ($row['minutes_worked'] ?? 0), 'description' => $row['name'] ?? '', 'job_type' => $row['job_type'] ?? null, 'is_billable' => (bool) ($row['is_billable'] ?? true), 'is_deferred' => (bool) ($row['is_deferred_billing'] ?? false), 'billing_rate_amount' => null, 'subcontractor_cost_amount' => self::nullableMinorUnits($row['subcontractor_hourly_rate'] ?? null), 'subcontractor_cost_currency' => self::nullableMinorUnits($row['subcontractor_hourly_rate'] ?? null) === null ? null : self::sourceCurrency($row['currency'] ?? null), 'currency' => self::sourceCurrency($row['currency'] ?? null), 'status' => ($row['approval_status'] ?? 'approved') === 'approved' ? 'approved' : 'draft', 'approved_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['approved_by_user_id'] ?? ''), $ledgerItems, $queryCache)), 'approved_at' => self::sourceTimestamp($row['approved_at'] ?? null)],
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
            $keys = array_flip(array_map(strval(...), $superseded));

            // The claims this run observed, not the ones the source still
            // shows. A competitor deleted or cleared between the two reads
            // would otherwise disappear from the count and leave the survivor
            // looking unambiguous - and vanishedLinkCount() reports that
            // afterwards without undoing a link written on the strength of it.
            foreach (['client_time_entries', 'client_tasks'] as $table) {
                foreach ($this->linkedSourceKeys[$table] ?? [] as $lineKey) {
                    if (isset($keys[$lineKey])) {
                        $claimed[$lineKey] = true;
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
     * line per item - a milestone is one line per task, a subcontractor charge
     * one per rate - can have several superseded lines standing for several
     * different things. Collapsing those onto the single line that survived
     * would mark work billed that the regenerated invoice dropped, and nothing
     * would ever charge for it.
     *
     * Where the claim is exclusive there is a third direction to check. A
     * milestone task holds its line in a column because a milestone is one
     * deliverable that cannot be split, so a live line another task already
     * holds is not available to this one - a dropped milestone would otherwise
     * be marked billed by attaching it to the survivor its neighbour owns. A
     * time entry's claim is a pivot row precisely because one line bills many
     * entries, so the same test there would refuse the ordinary case: the
     * replacement in the migrated data is already claimed by nineteen live
     * entries, and the twenty being recovered belong on it beside them.
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
        ?string $exclusiveClaimantTable = null,
        ?int $claimantId = null,
    ): ?int {
        if ($supersededKey === '') {
            return null;
        }

        $columns = $this->sourceColumns($sourceRuntimeName, 'client_invoice_lines', $queryCache);
        foreach (['client_invoice_line_id', 'client_invoice_id', 'line_type', 'deleted_at'] as $required) {
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
        if ($exclusiveClaimantTable === null && ! in_array((string) $lineType, self::WHOLE_INVOICE_LINE_TYPES, true)) {
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

        // Asked of what this run observed, not of the source now. A claim
        // cleared between the two reads would otherwise make the replacement
        // look unheld, and the row being resolved would take a line its
        // neighbour was holding when anybody last looked. The row being
        // resolved named a superseded line, so anything holding the
        // replacement is necessarily a different row.
        if ($exclusiveClaimantTable !== null
            && in_array($replacementKey, $this->linkedSourceKeys[$exclusiveClaimantTable] ?? [], true)) {
            return null;
        }

        // Held to the same fingerprint check as the row carrying the link. A
        // replacement edited since this run observed it describes a snapshot
        // this run never saw, and a billing link must not be written from one.
        if (! $this->observedThisRun('client_invoice_lines', $replacementKey, $replacement, $ledgerItems)) {
            return null;
        }

        $publicId = $this->resolveParentId('client_invoice_lines', $replacementKey, $ledgerItems, $queryCache);
        $lineId = $this->internalId($destinationName, 'client_invoice_lines', $publicId);

        if ($lineId === null || ! $this->ownedByRunWorkspace($destinationName, 'client_invoice_lines', $lineId, $workspaceId)) {
            return null;
        }

        // And here, not only in the source. A row can hold the replacement from
        // an earlier import while the claim that put it there has since been
        // cleared at the source - so it is absent from what this run observed,
        // and the source-side question above cannot see it.
        if ($exclusiveClaimantTable !== null && DB::connection($destinationName)->table($exclusiveClaimantTable)
            ->where('workspace_id', $workspaceId)
            ->where('client_invoice_line_id', $lineId)
            ->when($claimantId !== null, fn ($query) => $query->where('id', '!=', $claimantId))
            ->exists()) {
            return null;
        }

        return $lineId;
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
                'created_at' => now(),
                'updated_at' => now(),
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
                'updated_at' => now(),
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

            // An entry naming a line the source has superseded is billed work,
            // not unbilled work, so follow the claim to the line that replaced
            // it rather than dropping it and letting it be charged again.
            if ($timeId !== null && $lineId === null) {
                $lineId = $this->supersededLineId($source, $sourceRuntimeName, (string) ($row['client_invoice_line_id'] ?? ''), $destinationName, (int) $run->workspace_id, $ledgerItems, $queryCache);

                if ($lineId !== null) {
                    $linkCounts['recovered']++;
                }
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
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
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
     * @param  array{source_rows:int,linked:int,idempotent:int,recovered:int,rejected:int,failed:int}  $milestoneCounts
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

            // Same hazard as a time entry's claim, and the same recovery: a
            // milestone naming a superseded line was billed, and an unlinked
            // milestone is one the next generation run charges for again.
            if ($taskId !== null && $lineId === null) {
                $lineId = $this->supersededLineId($source, $sourceRuntimeName, (string) ($row['client_invoice_line_id'] ?? ''), $destinationName, (int) $run->workspace_id, $ledgerItems, $queryCache, 'client_tasks', $taskId);

                if ($lineId !== null) {
                    $milestoneCounts['recovered']++;
                }
            }

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
            $updated = DB::connection($destinationName)->table('client_tasks')
                ->where('workspace_id', $run->workspace_id)
                ->where('id', $taskId)
                ->whereNull('client_invoice_line_id')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->fromSub(
                    DB::connection($destinationName)->table('client_tasks')
                        ->select('id')
                        ->where('workspace_id', $run->workspace_id)
                        ->where('client_invoice_line_id', $lineId)
                        ->where('id', '!=', $taskId),
                    'holders',
                ))
                ->update(['client_invoice_line_id' => $lineId]);

            if ($updated === 0) {
                // Nothing written, for one of two reasons: this task already
                // had a link, or somebody else took the line. They are not the
                // same outcome and must not report as one.
                $current = DB::connection($destinationName)->table('client_tasks')
                    ->where('workspace_id', $run->workspace_id)
                    ->where('id', $taskId)
                    ->value('client_invoice_line_id');

                if ($current !== null) {
                    $milestoneCounts['idempotent']++;

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
            'started_at' => now(),
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

    private static function minutesFromDecimal(mixed $value): int
    {
        return (int) round(((float) ($value ?: 0)) * 60);
    }
}
