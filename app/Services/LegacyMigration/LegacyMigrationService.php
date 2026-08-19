<?php

namespace App\Services\LegacyMigration;

use App\Models\LegacyMigrationFailure;
use App\Models\LegacyMigrationItem;
use App\Models\LegacyMigrationRun;
use App\Models\Workspace;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * @phpstan-type MigrationCounts array{
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
 *     table_columns: array<array-key, list<string>>
 * }
 */
final class LegacyMigrationService
{
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
        /** @var MigrationCounts $counts */
        $counts = $this->emptyCounts($inventory);
        $linkCounts = ['source_rows' => 0, 'inserted' => 0, 'idempotent' => 0, 'failed' => 0];
        $queryCache = $this->newQueryCache();
        $this->activeQueryCache = &$queryCache;
        /** @var array<string, LegacyMigrationItem> $ledgerItems */
        $ledgerItems = [];

        try {
            foreach ($specs as $spec) {
                $table = (string) $spec['source_table'];
                if (! isset($inventory[$table])) {
                    continue;
                }

                $this->importSpec($sourceConnection, $spec, $run, $destinationName, $counts, $queryCache, $ledgerItems);
            }

            $this->reconcileImportedInvoices($run, $destinationName);
            $this->reconcileTimeEntryInvoiceLinks($sourceConnection, $this->sourceGuard->runtimeName($source), $run, $destinationName, $ledgerItems, $queryCache, $linkCounts, $counts);

            $run->forceFill([
                'counts' => $counts + ['link_counts' => $linkCounts],
                'status' => $counts['failed'] > 0
                    ? 'completed_with_failures'
                    : ($counts['skipped'] > 0 ? 'completed_with_skips' : 'completed'),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable) {
            $run->forceFill(['counts' => $counts, 'status' => 'failed', 'completed_at' => now()])->save();
            throw new SourceConfigurationException('migration_failed');
        }

        $summary['run_public_id'] = $run->public_id;
        $summary['counts'] = $counts;
        $summary['link_counts'] = $linkCounts;
        $summary['status'] = $run->status;

        return $summary;
    }

    /** @return array<string, mixed> */
    public function verify(?string $runPublicId = null, ?string $workspaceIdentifier = null): array
    {
        $destinationName = $this->destinationName();
        $query = (new LegacyMigrationRun)->setConnection($destinationName)->newQuery();
        if ($runPublicId !== null && $runPublicId !== '') {
            $query->where('public_id', $runPublicId);
        }
        if ($workspaceIdentifier !== null && $workspaceIdentifier !== '') {
            $workspace = $this->workspace($workspaceIdentifier, $destinationName);
            $query->where('workspace_id', $workspace->getKey());
        }

        $run = $query->latest('id')->first();
        if (! $run) {
            throw new SourceConfigurationException('migration_run_not_found');
        }

        $observations = DB::connection($destinationName)->table('legacy_migration_run_items')
            ->where('legacy_migration_run_id', $run->getKey());
        $counts = (clone $observations)->selectRaw('observed_status, COUNT(*) as aggregate')
            ->groupBy('observed_status')->pluck('aggregate', 'observed_status')
            ->map(fn ($value): int => (int) $value)->all();
        $itemIds = (clone $observations)->pluck('legacy_migration_item_id');
        $items = (new LegacyMigrationItem)->setConnection($destinationName)->newQuery()->whereIn('id', $itemIds)
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

        $failureCount = (new LegacyMigrationFailure)->setConnection($destinationName)->newQuery()->where('legacy_migration_run_id', $run->getKey())->count();

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
     * @param  array<string, LegacyMigrationItem>  $ledgerItems
     */
    private function importSpec(ConnectionInterface $source, array $spec, LegacyMigrationRun $run, string $destinationName, array &$counts, array &$queryCache, array &$ledgerItems): void
    {
        $table = (string) $spec['source_table'];
        $keyColumn = (string) $spec['source_key'];
        $cursor = $source->table($table)->orderBy($keyColumn)->cursor();
        $itemsForTable = $this->loadLedgerItems($run, $table, $destinationName);
        foreach ($itemsForTable as $sourceKey => $item) {
            $ledgerItems[$this->ledgerItemKey($table, (string) $sourceKey)] = $item;
        }

        foreach ($cursor as $rawRow) {
            $row = (array) $rawRow;
            $sourceKey = (string) ($row[$keyColumn] ?? '');
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
                    $item = $item ?: new LegacyMigrationItem;
                    $item->setConnection($destinationName);
                    $item->forceFill([
                        'legacy_migration_run_id' => $run->getKey(),
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
                    $item = (new LegacyMigrationItem)->setConnection($destinationName)->newQuery()->firstOrCreate(
                        [
                            'source_identity_hash' => $run->source_identity_hash,
                            'source_table' => $spec['source_table'],
                            'source_key' => $sourceKey,
                        ],
                        [
                            'legacy_migration_run_id' => $run->getKey(),
                            'source_connection' => $run->source_connection,
                            'target_type' => $spec['target_type'],
                            'source_fingerprint' => $fingerprint,
                            'status' => 'failed',
                            'reason_code' => 'row_transaction_failed',
                        ],
                    );
                    if (! in_array($item->status, ['imported', 'planned_copy', 'planned_reference'], true)) {
                        $item->forceFill([
                            'legacy_migration_run_id' => $run->getKey(),
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
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $spec
     * @param  QueryCache  $queryCache
     * @param  array<string, LegacyMigrationItem>  $ledgerItems
     * @return array{status: string, target_public_id?: string, reason_code?: string}
     */
    private function importRow(array $row, array $spec, LegacyMigrationRun $run, ?LegacyMigrationItem $item, string $destinationName, array &$queryCache, array $ledgerItems): array
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
        $legacyId = (string) ($row['id'] ?? '');
        $bindings = Config::get('legacy-migration.user_bindings', []);
        $publicId = is_array($bindings) ? ($bindings[$legacyId] ?? null) : null;

        if (! is_string($publicId) || ! Str::isUuid($publicId)) {
            $provider = $row['oauth_provider'] ?? $row['provider'] ?? null;
            $subject = $row['oauth_subject'] ?? $row['subject'] ?? null;
            $trusted = Config::get('legacy-migration.trusted_identity_bindings', []);
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
     * @param  array<string, LegacyMigrationItem>  $ledgerItems
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
        $attributes = ['public_id' => $publicId, 'workspace_id' => $workspaceId];

        return match ($type) {
            'company' => $attributes + [
                'name' => $row['company_name'] ?? $row['name'] ?? 'Legacy company',
                'slug' => $this->safeSlug((string) ($row['slug'] ?? $row['company_name'] ?? $row['name'] ?? 'legacy-company'), (string) ($row['id'] ?? '')),
                'billing_email' => $row['billing_email'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            'company_membership' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'user_id' => $this->internalId($destinationName, 'users', $user), 'role' => $row['role'] ?? 'client'],
            'company_activity' => $attributes + [
                'client_company_id' => $this->internalId($destinationName, 'client_companies', $company),
                'actor_user_id' => $this->internalId($destinationName, 'users', $user),
                'action' => (string) ($row['action'] ?? 'legacy.activity'),
                'subject_type' => $row['subject_type'] ?? null,
                'legacy_subject_id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
                'payload' => $this->jsonOrNull([
                    'legacy_subject_id' => isset($row['subject_id']) ? (int) $row['subject_id'] : null,
                    'legacy_payload' => $this->decodeJson($row['payload'] ?? null),
                ]),
            ],
            'project' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'name' => $row['name'] ?? 'Legacy project', 'description' => $row['description'] ?? null, 'status' => 'active', 'is_visible_to_client' => ! (bool) ($row['is_hidden_from_clients'] ?? false)],
            'task' => $attributes + ['client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'title' => $row['name'] ?? $row['title'] ?? 'Legacy task', 'description' => $row['description'] ?? null, 'status' => ($row['completed_at'] ?? null) ? 'completed' : 'open', 'is_visible_to_client' => ! (bool) ($row['is_hidden_from_clients'] ?? false), 'completed_at' => $row['completed_at'] ?? null],
            'time_entry' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'client_task_id' => $this->internalId($destinationName, 'client_tasks', $task), 'user_id' => $this->internalId($destinationName, 'users', $user), 'worked_on' => $row['date_worked'] ?? null, 'minutes' => (int) ($row['minutes_worked'] ?? 0), 'description' => $row['name'] ?? '', 'is_billable' => (bool) ($row['is_billable'] ?? true), 'is_deferred' => (bool) ($row['is_deferred_billing'] ?? false), 'billing_rate_amount' => null, 'subcontractor_cost_amount' => self::nullableMinorUnits($row['subcontractor_hourly_rate'] ?? null), 'currency' => $row['currency'] ?? 'USD', 'status' => ($row['approval_status'] ?? 'approved') === 'approved' ? 'approved' : 'draft'],
            'proposal' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => $this->internalId($destinationName, 'client_projects', $project), 'title' => $row['title'] ?? 'Legacy proposal', 'summary' => $row['body_markdown'] ?? null, 'currency' => $row['currency'] ?? 'USD', 'valid_until' => $row['expires_at'] ?? null, 'status' => $this->proposalStatus($row['status'] ?? 'draft'), 'accepted_at' => $row['accepted_at'] ?? null, 'accepted_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['accepted_by_user_id'] ?? ''), $ledgerItems, $queryCache)), 'acceptance_signer_name' => $row['accept_signature_name'] ?? null, 'acceptance_signer_title' => $row['accept_signature_title'] ?? null],
            'proposal_item' => $attributes + ['client_proposal_id' => $this->internalId($destinationName, 'client_proposals', $proposal), 'description' => $row['description'] ?? 'Legacy proposal item', 'quantity' => $row['quantity'] ?? '1', 'unit_amount' => self::minorUnits($row['amount'] ?? null), 'cadence' => $row['charge_cadence'] ?? 'one_time', 'sort_order' => (int) ($row['sort_order'] ?? 0)],
            'agreement' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_project_id' => null, 'source_proposal_id' => $this->internalId($destinationName, 'client_proposals', $proposal), 'title' => $row['title'] ?? 'Legacy agreement', 'status' => ($row['termination_date'] ?? null) ? 'terminated' : (($row['active_date'] ?? null) ? 'active' : 'draft'), 'starts_on' => $row['active_date'] ?? null, 'ends_on' => $row['termination_date'] ?? null, 'agreement_text' => $row['agreement_text'] ?? null, 'is_visible_to_client' => (bool) ($row['is_visible_to_client'] ?? false), 'currency' => $row['currency'] ?? 'USD', 'hourly_rate_amount' => self::nullableMinorUnits($row['hourly_rate'] ?? null), 'retainer_amount' => self::nullableMinorUnits($row['monthly_retainer_fee'] ?? $row['retainer_fee'] ?? null), 'retainer_minutes' => self::minutesFromDecimal($row['monthly_retainer_hours'] ?? $row['retainer_hours'] ?? null), 'billing_cadence' => $row['billing_cadence'] ?? 'monthly', 'activated_at' => $row['active_date'] ?? null, 'signed_at' => $row['client_company_signed_date'] ?? null, 'signed_by_user_id' => $this->internalId($destinationName, 'users', $this->resolveParentId('users', (string) ($row['client_company_signed_user_id'] ?? ''), $ledgerItems, $queryCache)), 'signer_name' => $row['client_company_signed_name'] ?? null, 'signer_title' => $row['client_company_signed_title'] ?? null, 'terminated_at' => $row['termination_date'] ?? null],
            'agreement_recurring_item' => $attributes + ['client_agreement_id' => $this->internalId($destinationName, 'client_agreements', $agreement), 'description' => $row['description'] ?? 'Legacy recurring item', 'amount' => self::minorUnits($row['amount'] ?? null), 'currency' => $row['currency'] ?? 'USD', 'cadence' => $row['charge_cadence'] ?? 'monthly', 'anchor_month' => $row['anchor_month'] ?? null, 'anchor_day' => $row['anchor_day'] ?? 1, 'effective_on' => $row['start_date'] ?? null, 'expires_on' => $row['end_date'] ?? null, 'is_taxable' => (bool) ($row['is_taxable'] ?? false), 'is_active' => ! isset($row['deleted_at'])],
            'invoice' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_agreement_id' => $this->internalId($destinationName, 'client_agreements', $agreement), 'invoice_number' => $this->invoiceNumber($row, $workspaceId, $destinationName), 'status' => in_array($row['status'] ?? 'draft', ['draft', 'issued', 'partially_paid', 'paid', 'void'], true) ? ($row['status'] ?? 'draft') : 'draft', 'issue_date' => isset($row['issue_date']) ? substr((string) $row['issue_date'], 0, 10) : null, 'due_date' => isset($row['due_date']) ? substr((string) $row['due_date'], 0, 10) : null, 'service_period_start' => $row['period_start'] ?? null, 'service_period_end' => $row['period_end'] ?? null, 'currency' => $row['currency'] ?? 'USD', 'subtotal_amount' => self::minorUnits($row['invoice_total'] ?? null), 'total_amount' => self::minorUnits($row['invoice_total'] ?? null), 'paid_amount' => ($row['status'] ?? '') === 'paid' ? self::minorUnits($row['invoice_total'] ?? null) : 0, 'balance_amount' => ($row['status'] ?? '') === 'paid' ? 0 : self::minorUnits($row['invoice_total'] ?? null), 'notes' => $row['notes'] ?? null, 'is_visible_to_client' => ($row['status'] ?? 'draft') !== 'draft'],
            'invoice_line' => $attributes + ['client_invoice_id' => $this->internalId($destinationName, 'client_invoices', $invoice), 'description' => $row['description'] ?? 'Legacy invoice line', 'type' => $row['line_type'] ?? 'adjustment', 'quantity' => self::invoiceLineQuantity($row['quantity'] ?? null), 'unit_amount' => self::minorUnits($row['unit_price'] ?? null), 'tax_amount' => 0, 'total_amount' => self::minorUnits($row['line_total'] ?? null), 'sort_order' => (int) ($row['sort_order'] ?? 0)],
            'invoice_payment' => $attributes + ['client_invoice_id' => $this->internalId($destinationName, 'client_invoices', $invoice), 'status' => 'succeeded', 'amount' => self::minorUnits($row['amount'] ?? null), 'refunded_amount' => 0, 'currency' => $row['currency'] ?? 'USD', 'received_on' => $row['payment_date'] ?? null, 'method' => $row['payment_method'] ?? 'legacy', 'reference' => $row['stripe_payment_intent_id'] ?? null, 'notes' => $row['notes'] ?? null, 'provider' => ($row['stripe_payment_intent_id'] ?? null) ? 'stripe' : null, 'provider_payment_identifier' => $row['stripe_payment_intent_id'] ?? null, 'external_finance_transaction_uuid' => null],
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
                'legacy_metadata' => $this->jsonOrNull([
                    'legacy_id' => isset($row['id']) ? (int) $row['id'] : null,
                    'queued_by_user_id' => isset($row['queued_by_user_id']) ? (int) $row['queued_by_user_id'] : null,
                    'mailer' => $row['mailer'] ?? null,
                    'provider' => $row['provider'] ?? null,
                    'transport_message_id' => $row['transport_message_id'] ?? null,
                    'note' => $row['note'] ?? null,
                    'last_event' => $row['last_event'] ?? null,
                    'last_event_at' => $row['last_event_at'] ?? null,
                    'last_status_checked_at' => $row['last_status_checked_at'] ?? null,
                    'delivery_events' => $this->decodeJson($row['delivery_events'] ?? null),
                    'provider_response' => $this->decodeJson($row['provider_response'] ?? null),
                ]),
                'queued_at' => $row['queued_at'] ?? null,
                'sent_at' => $row['sent_at'] ?? null,
                'failed_at' => $row['failed_at'] ?? null,
            ],
            'stripe_customer' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'stripe_customer_id' => $row['stripe_customer_id'] ?? null, 'metadata' => json_encode(['migrated' => true], JSON_THROW_ON_ERROR)],
            'stripe_payment_method' => $attributes + ['client_company_id' => $this->internalId($destinationName, 'client_companies', $company), 'client_stripe_customer_id' => $this->stripeCustomerInternalId($destinationName, $company), 'stripe_payment_method_id' => $row['stripe_payment_method_id'] ?? null, 'type' => $row['type'] ?? 'unknown', 'brand' => $row['brand'] ?? null, 'last4' => $row['last4'] ?? null, 'exp_month' => $row['exp_month'] ?? null, 'exp_year' => $row['exp_year'] ?? null, 'is_default' => (bool) ($row['is_default'] ?? false), 'metadata' => json_encode(['migrated' => true], JSON_THROW_ON_ERROR)],
            'stripe_event' => $attributes + ['stripe_event_id' => $row['stripe_event_id'] ?? null, 'event_type' => $row['type'] ?? 'legacy.event', 'object_id' => $row['object_id'] ?? null, 'payload_hash' => Fingerprint::row($row), 'status' => 'received', 'processed_at' => $row['processed_at'] ?? null],
            default => $attributes,
        };
    }

    /**
     * @param  array<string, LegacyMigrationItem>  $ledgerItems
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
        ];
    }

    /**
     * @return array<string, LegacyMigrationItem>
     */
    private function loadLedgerItems(LegacyMigrationRun $run, string $sourceTable, string $destinationName): array
    {
        $items = [];
        $query = (new LegacyMigrationItem)->setConnection($destinationName)->newQuery()
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

    private function ledgerItemKey(string $sourceTable, string $sourceKey): string
    {
        return $sourceTable."\0".$sourceKey;
    }

    private function recordRunItem(LegacyMigrationRun $run, LegacyMigrationItem $item, string $status, string $fingerprint, string $destinationName): void
    {
        DB::connection($destinationName)->table('legacy_migration_run_items')->updateOrInsert(
            [
                'legacy_migration_run_id' => $run->getKey(),
                'legacy_migration_item_id' => $item->getKey(),
            ],
            [
                'observed_status' => $status,
                'source_fingerprint' => $fingerprint,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function reconcileImportedInvoices(LegacyMigrationRun $run, string $destinationName): void
    {
        $invoicePublicIds = (new LegacyMigrationItem)->setConnection($destinationName)->newQuery()
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
            // Monotonic: the imported paid_amount (derived from the legacy status)
            // is a floor. Zero migrated payment rows means the payments did not
            // migrate or the invoice was settled offline — never evidence that a
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
                'balance_amount' => $total - $paid,
                'is_visible_to_client' => $status !== 'draft' ? true : (bool) $invoice->is_visible_to_client,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Legacy invoices stored their billed-time relationship on the time-entry row. SVC
     * intentionally models that as a many-to-many link, so reconcile it after all parent
     * importers have established their public-id mappings.
     *
     * @param  array<string, LegacyMigrationItem>  $ledgerItems
     * @param  QueryCache  $queryCache
     * @param  array{source_rows:int,inserted:int,idempotent:int,failed:int}  $linkCounts
     * @param  array<string, mixed>  $counts
     */
    private function reconcileTimeEntryInvoiceLinks(
        ConnectionInterface $source,
        string $sourceRuntimeName,
        LegacyMigrationRun $run,
        string $destinationName,
        array $ledgerItems,
        array &$queryCache,
        array &$linkCounts,
        array &$counts,
    ): void {
        if (! Schema::connection($sourceRuntimeName)->hasColumn('client_time_entries', 'client_invoice_line_id')) {
            return;
        }

        $rows = $source->table('client_time_entries')
            ->whereNotNull('client_invoice_line_id')
            ->orderBy('id')
            ->get(['id', 'client_invoice_line_id']);
        $linkCounts['source_rows'] = $rows->count();

        foreach ($rows as $row) {
            $timePublicId = $this->resolveParentId('client_time_entries', (string) $row->id, $ledgerItems, $queryCache);
            $linePublicId = $this->resolveParentId('client_invoice_lines', (string) $row->client_invoice_line_id, $ledgerItems, $queryCache);
            $timeId = $this->internalId($destinationName, 'client_time_entries', $timePublicId);
            $lineId = $this->internalId($destinationName, 'client_invoice_lines', $linePublicId);

            if ($timeId === null || $lineId === null) {
                $linkCounts['failed']++;
                $counts['failed']++;
                $counts['failure_reasons']['missing_invoice_time_link_parent'] = ($counts['failure_reasons']['missing_invoice_time_link_parent'] ?? 0) + 1;

                continue;
            }

            $query = DB::connection($destinationName)->table('client_invoice_line_time_entries');
            $exists = $query->where('workspace_id', $run->workspace_id)
                ->where('client_invoice_line_id', $lineId)
                ->where('client_time_entry_id', $timeId)
                ->exists();
            if ($exists) {
                $linkCounts['idempotent']++;

                continue;
            }

            $query->insert([
                'workspace_id' => $run->workspace_id,
                'client_invoice_line_id' => $lineId,
                'client_time_entry_id' => $timeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $linkCounts['inserted']++;
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $row
     */
    private function recordFailure(LegacyMigrationRun $run, LegacyMigrationItem $item, array $spec, string $sourceKey, string $fingerprint, string $reason, array $row, string $destinationName): void
    {
        $keyHash = hash('sha256', $sourceKey);
        $context = ['source_key_hash' => $keyHash, 'source_row_fingerprint' => $fingerprint, 'source_field_count' => count($row)];
        $failureFingerprint = Fingerprint::row(['table' => $spec['source_table'], 'key_hash' => $keyHash, 'reason' => $reason, 'row' => $fingerprint]);
        (new LegacyMigrationFailure)->setConnection($destinationName)->newQuery()->updateOrCreate(
            ['legacy_migration_run_id' => $run->getKey(), 'source_table' => $spec['source_table'], 'source_key_hash' => $keyHash, 'reason_code' => $reason],
            ['legacy_migration_item_id' => $item->getKey(), 'source_connection' => $run->source_connection, 'redacted_context' => $context, 'failure_fingerprint' => $failureFingerprint],
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, array<string, mixed>>  $inventory
     */
    private function newRun(array $source, Workspace $workspace, array $inventory, string $destinationName): LegacyMigrationRun
    {
        $marks = [];
        $fingerprints = [];
        foreach ($inventory as $table => $details) {
            $marks[$table] = $details['high_water_mark'];
            $fingerprints[$table] = $details['fingerprint'];
        }

        return (new LegacyMigrationRun)->setConnection($destinationName)->newQuery()->create([
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
        return (string) (Config::get('legacy-migration.destination_connection') ?: Config::get('database.default'));
    }

    /**
     * @param  array<string, array<string, mixed>>  $inventory
     * @return MigrationCounts
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
        $slug = Str::slug($value) ?: 'legacy-company';

        return $slug.'-legacy-'.substr(hash('sha256', $sourceKey), 0, 10);
    }

    /** @param array<string, mixed> $row */
    private function invoiceNumber(array $row, int $workspaceId, string $destinationName): string
    {
        $sourceKey = (string) ($row['client_invoice_id'] ?? $row['id'] ?? 'unknown');
        $base = trim((string) ($row['invoice_number'] ?? ''));
        $base = $base !== '' ? $base : 'LEGACY-'.$sourceKey;
        $base = Str::substr($base, 0, 80);

        $exists = DB::connection($destinationName)->table('client_invoices')
            ->where('workspace_id', $workspaceId)
            ->where('invoice_number', $base)
            ->exists();
        if (! $exists) {
            return $base;
        }

        $suffix = '-legacy-'.substr(hash('sha256', $sourceKey), 0, 16);

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
            throw new \InvalidArgumentException('Unparseable legacy money value.');
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

        throw new \InvalidArgumentException('Unparseable legacy invoice-line quantity.');
    }

    private static function minutesFromDecimal(mixed $value): int
    {
        return (int) round(((float) ($value ?: 0)) * 60);
    }
}
