<?php

namespace Tests\Feature\ExternalImport;

use App\Services\ExternalImport\ExternalImportService;
use App\Services\ExternalImport\ImporterRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Every destination column an importer owns must be written by the importer.
 *
 * FillableCoverageTest holds the neighbouring property - that a model will
 * accept a column - and it cannot catch this one. A column can be perfectly
 * fillable while nothing ever passes it a value, which is how the milestone
 * invoice-line link and the invoice opening balances both arrived null on every
 * imported row. The guard said yes and the field was empty anyway.
 *
 * A column the importer deliberately does not carry belongs in the exemptions
 * below with a reason, so a decision stays distinguishable from an oversight.
 */
final class ImportedColumnCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Columns no importer should write, and why.
     *
     * @var array<string, string>
     */
    private const GLOBALLY_EXEMPT = [
        'id' => 'Database-assigned.',
        'created_at' => 'Stamped by the insert path from the source row dates.',
        'updated_at' => 'Stamped by the insert path from the source row dates.',
        'deleted_at' => 'The importer does not carry deletions; a deleted source row is not imported at all.',
        'lock_version' => 'Optimistic locking counter; only ever incremented by the persistence layer.',
    ];

    /**
     * Columns filled by a reconciliation pass after every importer has run,
     * because they point at a row a later spec creates. Named here so the
     * mapping arm reads as complete rather than as an oversight.
     *
     * @var array<string, array<string, string>>
     */
    private const RECONCILED_AFTER_IMPORT = [
        'client_tasks' => [
            'client_invoice_line_id' => 'reconcileMilestoneTaskInvoiceLinks() - invoice lines import after tasks, so the link cannot resolve inline.',
        ],
    ];

    /**
     * Per-table exemptions, and why. A column here is one the source does not
     * carry, so importing it is not possible rather than merely undone.
     *
     * @var array<string, array<string, string>>
     */
    private const TABLE_EXEMPT = [
        'client_company_memberships' => [
            'access_scope' => 'Project-level client scoping is an SVC concept the source had no column for, and it is opt-in: inferring a scope on import would narrow every portal at once.',
        ],
        'client_time_entries' => [
            'subcontractor_cost_currency' => 'Source stores a bare hourly rate with no currency; the agreement currency is authoritative.',
            'subcontractor_cost_metadata' => 'SVC-only; the source has no structured subcontractor cost.',
            'is_visible_to_client' => 'Source has no per-entry client visibility. Defaults closed, which is the safe direction.',
            'client_visible_description' => 'Source has no client-safe description. Left null so the portal shows nothing rather than an internal note.',
            'billing_rate_source' => 'Stamped by svc:billing:derive-time-rates, which records whether a rate was recorded or inferred. An import cannot know.',
            'split_from_time_entry_id' => 'Fragment lineage, created by TimeEntrySplitter. An imported entry is never a fragment.',
        ],
        'client_proposals' => [
            'created_by_user_id' => 'Source records who responded to and accepted a proposal, but never who wrote it.',
            'terms' => 'SVC-only; the source carries one markdown body, which imports as the summary.',
            'declined_at' => 'Source has no decline timestamp - a declined proposal is expressed through status and the response message.',
            'expired_at' => 'Source has expires_at, which is the deadline and imports as valid_until. When it actually expired is not recorded.',
        ],
        'client_agreements' => [
            'rollover_policy' => 'SVC-only. The source expresses rollover through rollover_months and initial_rollover_hours, both of which import.',
        ],
        'client_agreement_recurring_items' => [
            'quantity' => 'Source has no quantity; every recurring item is one unit. The column defaults to 1, so the imported charge is the source amount.',
            'sort_order' => 'Source does not order recurring items. Defaults to 0.',
        ],
        'client_invoices' => [
            'client_billing_schedule_id' => 'SVC-only; schedules are generated here, never imported.',
            'tax_amount' => 'Source folds tax into the invoice total rather than storing it separately, so any value here would be invented.',
            'issued_at' => 'Source records issue_date, which imports. The precise timestamp was never stored.',
            'voided_at' => 'Source expresses a void through status alone.',
            'void_reason' => 'Source expresses a void through status alone.',
        ],
        'client_invoice_lines' => [
            'client_project_id' => 'Source lines belong to an invoice and an agreement, never directly to a project.',
        ],
        'client_invoice_payments' => [
            'idempotency_key' => 'Guards concurrent payment writes here; a historical payment has already happened.',
        ],
        'client_stripe_events' => [
            'error_summary' => 'Written when this application fails to process an event. An imported event was processed by the predecessor.',
        ],
    ];

    public function test_every_imported_table_has_every_column_written(): void
    {
        $service = app(ExternalImportService::class);
        $attributes = new ReflectionMethod($service, 'attributes');
        $queryCache = (new ReflectionMethod($service, 'newQueryCache'))->invoke($service);
        $destination = DB::getDefaultConnection();

        $gaps = [];

        foreach (app(ImporterRegistry::class)->all() as $spec) {
            if (($spec['action'] ?? null) !== 'write') {
                continue;
            }

            $table = (string) $spec['target_table'];
            if (! Schema::hasTable($table)) {
                continue;
            }

            /** @var array<string, mixed> $mapped */
            $mapped = $attributes->invokeArgs($service, [
                [], (string) $spec['target_type'], 1, [], '00000000-0000-4000-8000-000000000000',
                $destination, 'test-identity-hash', &$queryCache, [],
            ]);

            $exempt = array_merge(self::GLOBALLY_EXEMPT, self::TABLE_EXEMPT[$table] ?? [], self::RECONCILED_AFTER_IMPORT[$table] ?? []);
            $missing = array_diff(Schema::getColumnListing($table), array_keys($mapped), array_keys($exempt));

            if ($missing !== []) {
                $gaps[] = sprintf('%s [%s]: %s', $spec['name'], $table, implode(', ', $missing));
            }
        }

        $this->assertSame([], $gaps, sprintf(
            'These columns exist on a table the importer writes, but no import maps them, so every '.
            "imported row reads null forever:\n\n%s\n\nMap each in ExternalImportService::attributes(), ".
            "or add it to this test's exemptions with a reason.",
            implode("\n", $gaps),
        ));
    }
}
