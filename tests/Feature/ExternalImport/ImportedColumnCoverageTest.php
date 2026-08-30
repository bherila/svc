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
        'client_company_activity' => [
            'subject_public_id' => 'Native events point at SVC public UUIDs. Imported subjects have only the predecessor numeric reference in external_subject_id.',
            'deduplication_key' => 'Native writers hash an operation occurrence to suppress retries. Preserved historical events are already deduplicated by the import ledger.',
        ],
        'client_company_memberships' => [
            'access_scope' => 'Project-level client scoping is an SVC concept the source had no column for, and it is opt-in: inferring a scope on import would narrow every portal at once.',
        ],
        'client_time_entries' => [
            'billing_rate_amount' => 'Written as a literal null on purpose: the source has no rate on a time entry, and svc:billing:derive-time-rates resolves one from the agreement in force with its provenance recorded. Inventing one here would be indistinguishable from a rate somebody was charged.',
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
            'client_project_id' => 'Written as a literal null: the source has no project column on an agreement. Project-scoped agreements are an SVC concept, and guessing a scope would narrow what an agreement covers.',
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
            'provider_event_created_at' => 'Native Stripe delivery watermark. The imported payment is historical and the predecessor event timestamp is not available.',
            'provider_event_id' => 'Native Stripe delivery watermark. Imported provider events cannot be safely associated with one payment after the fact.',
            'external_finance_transaction_uuid' => 'Written as a literal null: the reconciliation link to an external finance system is established here and has no counterpart in the source.',
            'idempotency_key' => 'Guards concurrent payment writes here; a historical payment has already happened.',
        ],
        'client_stripe_customers' => [
            'default_payment_method_event_created_at' => 'Native Stripe delivery watermark. The source customer row has no event-ordering timestamp.',
            'default_payment_method_event_id' => 'Native Stripe delivery watermark. The source customer row has no last-default-update event ID.',
        ],
        'client_stripe_events' => [
            'error_summary' => 'Written when this application fails to process an event. An imported event was processed by the predecessor.',
        ],
    ];

    /**
     * Columns this type's mapping writes as a literal `null`.
     *
     * Read from the source of `attributes()` rather than from its return value,
     * because the two cases are indistinguishable at runtime: a mapping that
     * reads an absent source field and one that hard-codes an absence both
     * produce null for a row with no data.
     *
     * The limit is worth stating: this catches a declared null, not a mistyped
     * source key. Distinguishing that would need the source column names, which
     * this test does not have.
     *
     * @return list<string>
     */
    private function literalNulls(string $type): array
    {
        $method = new ReflectionMethod(ExternalImportService::class, 'attributes');
        $file = (string) $method->getFileName();
        $lines = array_slice(
            file($file) ?: [],
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        );

        foreach ($lines as $line) {
            if (! str_contains($line, "'".$type."' => \$attributes")) {
                continue;
            }

            preg_match_all("/'([a-z_]+)'\s*=>\s*null\b/", $line, $matches);

            return $matches[1];
        }

        return [];
    }

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

            // A key alone is not coverage. `'column' => null` satisfies a name
            // check while every imported row still reads null, which is the
            // exact omission this test exists to catch, so a mapping written as
            // a literal null does not count as mapped.
            $carried = array_diff(array_keys($mapped), $this->literalNulls((string) $spec['target_type']));

            $missing = array_diff(Schema::getColumnListing($table), $carried, array_keys($exempt));

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
