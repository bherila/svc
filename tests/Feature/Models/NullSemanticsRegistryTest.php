<?php

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Feature\AgentApi\AgentReadApiTest;
use Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest;
use Tests\Feature\Billing\AllocationServiceTest;
use Tests\Feature\Billing\BillingWorkflowTest;
use Tests\Feature\Billing\CapacityAndScopeGuardsTest;
use Tests\Feature\Billing\DeriveTimeEntryRatesTest;
use Tests\Feature\Billing\DraftInvoiceTimeRegenerationTest;
use Tests\Feature\Billing\InvoiceFromTimeServiceTest;
use Tests\Feature\Billing\InvoicingExamplesTest;
use Tests\Feature\Billing\RetainerDrawConsistencyTest;
use Tests\Feature\Engagement\TimeSheetTest;
use Tests\Feature\EngagementWorkflowTest;
use Tests\TestCase;
use Tests\Unit\Billing\InvoiceLedgerBuilderTest;
use Tests\Unit\Billing\RetainerCalculatorTest;

/**
 * Every nullable column on the billing tables must be registered with either
 * a citation of the test that covers its null branch, or an honest
 * PENDING-AUDIT marker.
 *
 * This exists because a null on these tables is not always "no value" - it is
 * sometimes a silently-selected branch. A null `invoice_kind` read as cadence
 * everywhere, so a guard written for migrated invoices excluded exactly the
 * rows it existed to protect. A null subcontractor cost currency bypassed the
 * cross-currency refusal in the invoice composer. A preserved rate with a
 * null currency made a time entry silently unbillable forever, because the
 * invoice path bills only entries whose currency matches the invoice's. Each
 * shipped because nothing forced anyone to say out loud what the null meant.
 *
 * An earlier attempt at this used a prose exemption list - reasons without
 * proof - and it certified two of the bugs above as intentional. That is
 * worse than no list at all, so this test accepts only two answers: a
 * citation this test can verify actually exists (`covered_by` + `method`,
 * checked by reflection), or `PENDING-AUDIT`, which admits the semantic audit
 * has not reached the column yet. Neither can be faked past this guard.
 *
 * This is the registry for #115, and the semantic audit has now been through
 * it once: every column whose null selects a behaviour carries a citation, and
 * the note above each says what the null means in one line - the citation, not
 * the note, is what this test checks. What is left PENDING-AUDIT is the set
 * nothing branches on: columns written and never read (`paid_on`,
 * `void_reason`, `agreement_link`, the invoice hour-balance columns), Eloquent
 * timestamps, and optional text. They cannot be cited because there is no null
 * branch to cover, and inventing one would be the prose exemption list again in
 * another costume. Retiring them is #73's `NOT NULL` question, not this one's.
 *
 * `client_agreements.initial_rollover_minutes` was pending for a different
 * reason - it had no reachable reader at all, because InvoiceLedgerBuilder
 * asked for `initial_rollover_hours`, which was neither a column nor an
 * accessor, so the seed month it guards was never built and the column's null
 * could not mean anything. #134 fixed the read and the column is registered
 * like any other. Left here rather than deleted because it is the clearest
 * example this registry has produced of what it is for: the column sat in a
 * schema, a model and a service for the whole port, and nothing forced anyone
 * to notice that no code path read it.
 */
final class NullSemanticsRegistryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The billing tables audited by issue #115.
     *
     * @var list<string>
     */
    private const TABLES = [
        'client_invoices',
        'client_invoice_lines',
        'client_agreements',
        'client_time_entries',
    ];

    /**
     * The number of PENDING-AUDIT entries in the registry below. This may
     * only decrease as the semantic audit resolves columns to either a
     * citation or a NOT NULL migration - it must never change silently in
     * either direction, so both an increase (a new nullable column landing
     * unregistered) and a decrease (an audited column whose pin was not
     * updated) fail this test.
     */
    private const PENDING_AUDIT_COUNT = 37;

    /**
     * One entry per nullable column on the tables above.
     *
     * Each value is either:
     *   - `['covered_by' => SomeTest::class, 'method' => 'test_...']`, a
     *     citation of an existing test that constructs this column's null
     *     case and asserts what happens - verified below by reflection;
     *   - a list of such citations, where the null is branched on in more than
     *     one place and the branches mean different things; or
     *   - the string `'PENDING-AUDIT'`, an honest marker that the semantic
     *     audit has not yet reached this column.
     *
     * A list rather than a second registry: one citation on a column with two
     * null branches is worse than none, because it reads as settled. That is
     * how `service_period_end` came to look audited while the branch that
     * decides whether overage is charged twice went uncovered until #135.
     *
     * @var array<string, array<string, 'PENDING-AUDIT'|array{covered_by: class-string, method: string}|list<array{covered_by: class-string, method: string}>>>
     */
    private const REGISTRY = [
        'client_invoices' => [
            // No agreement means no terms to reprice against, so regeneration
            // refuses rather than rebuilding the invoice unscoped.
            'client_agreement_id' => [
                'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                'method' => 'test_a_generated_draft_without_an_agreement_fails_closed',
            ],
            // No schedule means an operator typed this invoice, which is what
            // makes it ad hoc and exempt from the cadence overlap guard.
            'client_billing_schedule_id' => [
                'covered_by' => BillingWorkflowTest::class,
                'method' => 'test_a_draft_without_a_billing_schedule_is_classified_ad_hoc',
            ],
            // Not issued yet: issuing stamps the workspace's calendar date.
            'issue_date' => [
                'covered_by' => BillingWorkflowTest::class,
                'method' => 'test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
            ],
            // No stated term: issuing makes it due on the issue date. (A null
            // also drops the invoice from the overdue query - see the report on
            // AgentReadController, which nothing covers yet.)
            'due_date' => [
                'covered_by' => BillingWorkflowTest::class,
                'method' => 'test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
            ],
            // No recorded work period. With the cycle columns also null there is
            // nothing left to say which period a cadence draft covers, so
            // regeneration fails closed rather than guessing a range.
            'service_period_start' => [
                'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                'method' => 'test_a_cadence_draft_without_a_service_period_fails_closed',
            ],
            // Two branches, and the second is the consequential one. A null also
            // means "inside the billed-overage window": the sum of what an
            // agreement has already been charged reads `<=` against this
            // column, which is false for a null, so an unplaceable invoice
            // dropped out of it and its overage was charged again (#135).
            'service_period_end' => [
                [
                    'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                    'method' => 'test_a_cadence_draft_without_a_service_period_fails_closed',
                ],
                [
                    'covered_by' => CapacityAndScopeGuardsTest::class,
                    'method' => 'test_a_charged_invoice_with_no_service_period_is_still_counted_as_billed',
                ],
            ],
            'notes' => 'PENDING-AUDIT',
            'issued_at' => 'PENDING-AUDIT',
            'voided_at' => 'PENDING-AUDIT',
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            'void_reason' => 'PENDING-AUDIT',
            'invoice_kind' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_a_migrated_invoice_with_no_kind_still_counts_as_having_sold_the_cycle',
            ],
            // Predates the restored cycle columns: an interim draft with no
            // cycle cannot be regenerated at all, and a cadence draft falls back
            // to its service period.
            'cycle_start' => [
                'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                'method' => 'test_an_interim_draft_without_cycle_dates_fails_closed',
            ],
            'cycle_end' => [
                'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                'method' => 'test_an_interim_draft_without_cycle_dates_fails_closed',
            ],
            'paid_on' => 'PENDING-AUDIT',
            'retainer_hours_included' => 'PENDING-AUDIT',
            'hours_worked' => 'PENDING-AUDIT',
            'rollover_hours_used' => 'PENDING-AUDIT',
            'unused_hours_balance' => 'PENDING-AUDIT',
            'negative_hours_balance' => 'PENDING-AUDIT',
            'hours_billed_at_rate' => 'PENDING-AUDIT',
            'starting_unused_hours' => 'PENDING-AUDIT',
            'starting_negative_hours' => 'PENDING-AUDIT',
        ],
        'client_invoice_lines' => [
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            // Unattributed, not unresolvable: a flat fee belongs to the client
            // rather than to one of their projects. A project that *was* named
            // and cannot be found is still refused.
            'client_project_id' => [
                'covered_by' => InvoiceFromTimeServiceTest::class,
                'method' => 'test_a_manual_line_without_a_project_is_accepted_unattributed',
            ],
            // No date recorded, which is not a date: an undated line does not
            // widen the invoice's service period, and that period is what the
            // overlap guard reads to decide whether the next cycle may bill.
            'line_date' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_an_undated_line_does_not_widen_the_service_period',
            ],
            'hours' => 'PENDING-AUDIT',
            'client_agreement_id' => 'PENDING-AUDIT',
            'client_agreement_recurring_item_id' => 'PENDING-AUDIT',
        ],
        'client_agreements' => [
            // Company-wide: the agreement covers every project's work rather
            // than one project's, and loses to a project-specific agreement.
            'client_project_id' => [
                'covered_by' => DeriveTimeEntryRatesTest::class,
                'method' => 'test_it_resolves_the_agreement_rate_and_stamps_the_source',
            ],
            'source_proposal_id' => 'PENDING-AUDIT',
            // Not ready to be billed: an agreement with no start date anchors no
            // cycle and reports no capacity.
            'starts_on' => [
                'covered_by' => TimeSheetTest::class,
                'method' => 'test_an_agreement_with_no_start_date_reports_no_capacity',
            ],
            // Open-ended: nothing clips the retainer entitlement, and the
            // agreement is still in force on any later work date.
            'ends_on' => [
                'covered_by' => DeriveTimeEntryRatesTest::class,
                'method' => 'test_it_resolves_the_agreement_rate_and_stamps_the_source',
            ],
            'agreement_text' => 'PENDING-AUDIT',
            // Unpriced, which is not free: the rate lookup refuses rather than
            // stamping a rate. (Every other reader coerces the null to 0 - see
            // the report on InvoiceLineComposer, which nothing covers yet.)
            'hourly_rate_amount' => [
                'covered_by' => DeriveTimeEntryRatesTest::class,
                'method' => 'test_an_agreement_with_no_rate_prices_nothing',
            ],
            // No retainer price recorded, so no retainer fee is billed.
            'retainer_amount' => [
                'covered_by' => RetainerCalculatorTest::class,
                'method' => 'test_an_agreement_with_no_retainer_price_bills_no_retainer_fee',
            ],
            // No recurring capacity: an hourly-only agreement gets no strip and
            // grants no monthly pool.
            'retainer_minutes' => [
                'covered_by' => TimeSheetTest::class,
                'method' => 'test_an_agreement_with_no_retainer_reports_no_capacity',
            ],
            'rollover_policy' => 'PENDING-AUDIT',
            // Never activated: activation stamps the date, and a later
            // reactivation preserves the one already recorded.
            'activated_at' => [
                'covered_by' => EngagementWorkflowTest::class,
                'method' => 'test_only_an_unstamped_agreement_takes_an_activation_date',
            ],
            // Unsigned: only a null admits a signature, so a replayed signing
            // cannot rewrite the signatory or the date.
            'signed_at' => [
                'covered_by' => EngagementWorkflowTest::class,
                'method' => 'test_only_an_unsigned_agreement_can_be_signed',
            ],
            'signed_by_user_id' => 'PENDING-AUDIT',
            'signer_name' => 'PENDING-AUDIT',
            'signer_title' => 'PENDING-AUDIT',
            'terminated_at' => 'PENDING-AUDIT',
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            // Unstated: one hour, capped at the retainer the agreement has.
            'catch_up_threshold_minutes' => [
                'covered_by' => InvoicingExamplesTest::class,
                'method' => 'test_an_unset_threshold_defaults_to_one_hour',
            ],
            // No period-level override, so the monthly terms are scaled to the
            // cycle instead - a different ledger algorithm, not a default value.
            'period_retainer_minutes' => [
                'covered_by' => RetainerCalculatorTest::class,
                'method' => 'test_cycle_retainer_falls_back_to_monthly_ledger_terms',
            ],
            'period_retainer_amount' => [
                'covered_by' => RetainerCalculatorTest::class,
                'method' => 'test_cycle_retainer_falls_back_to_monthly_ledger_terms',
            ],
            // No rollover term, so nothing carries forward: an unstated policy
            // must not silently grant last month's leftover hours.
            'rollover_months' => [
                'covered_by' => InvoiceLedgerBuilderTest::class,
                'method' => 'test_an_agreement_with_no_rollover_term_carries_nothing_forward',
            ],
            // No opening balance was recorded, which is not an unknown one: the
            // ledger builds no carrier month and the agreement opens on its
            // retainer alone. Pending until #134, when the column acquired a
            // reachable reader and its null could mean something.
            'initial_rollover_minutes' => [
                'covered_by' => InvoiceLedgerBuilderTest::class,
                'method' => 'test_an_agreement_with_no_recorded_opening_rollover_grants_none',
            ],
            // Unset reads as off. The column stays nullable so the backfill can
            // tell an unset flag from a deliberate false, but for billing there
            // is one safe reading and it is "do not charge mid-cycle".
            'bill_overage_interim' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_an_agreement_with_no_interim_policy_bills_no_interim_overage',
            ],
            // No stated first-cycle policy, so the opening month prorates rather
            // than granting a full month nobody agreed to.
            'first_cycle_proration' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_an_agreement_with_no_stated_first_cycle_policy_prorates_its_opening_month',
            ],
            'agreement_link' => 'PENDING-AUDIT',
        ],
        'client_time_entries' => [
            'client_task_id' => 'PENDING-AUDIT',
            'billing_rate_amount' => [
                'covered_by' => AgentTimeBillingWorkflowTest::class,
                'method' => 'test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
            ],
            'currency' => [
                'covered_by' => TimeSheetTest::class,
                'method' => 'test_approval_supplies_a_currency_an_older_entry_lacks',
            ],
            'approved_by_user_id' => 'PENDING-AUDIT',
            'approved_at' => 'PENDING-AUDIT',
            'subcontractor_cost_amount' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_flat_hourly_time_without_a_complete_snapshot_is_refused',
            ],
            'subcontractor_cost_currency' => [
                'covered_by' => CapacityAndScopeGuardsTest::class,
                'method' => 'test_flat_hourly_time_without_a_complete_snapshot_is_refused',
            ],
            'subcontractor_cost_metadata' => 'PENDING-AUDIT',
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            // No client-safe text was written, and the internal description is
            // never substituted for it: the client-facing surfaces show nothing
            // rather than the operator's private wording.
            'client_visible_description' => [
                'covered_by' => AgentReadApiTest::class,
                'method' => 'test_legacy_client_visible_time_never_falls_back_to_internal_description',
            ],
            // Not deleted. The soft-delete scope is what admits the entry to
            // every ledger, allocator and invoice query, so the null is the
            // difference between billed and not billed.
            'deleted_at' => [
                'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                'method' => 'test_deleting_approved_time_rebuilds_the_cadence_draft_without_it',
            ],
            'billing_rate_source' => [
                'covered_by' => AgentTimeBillingWorkflowTest::class,
                'method' => 'test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
            ],
            'job_type' => 'PENDING-AUDIT',
            // Not a fragment of anything. Lineage is the only thing that makes
            // two rows one entry, so entries that merely look alike - same day,
            // person, project and description - are never merged.
            'split_from_time_entry_id' => [
                'covered_by' => AllocationServiceTest::class,
                'method' => 'test_entries_that_merely_look_alike_are_never_merged',
            ],
            // Consultant time, not "unknown": null draws on the retainer pool
            // and is invoiceable at the client rate. A cost with no mode is
            // excluded fail-closed instead.
            'subcontractor_billing_mode' => [
                'covered_by' => RetainerDrawConsistencyTest::class,
                'method' => 'test_each_subcontractor_mode_has_one_consistent_billing_path',
            ],
        ],
    ];

    /**
     * The ratchet: a nullable column with no registry entry at all cannot
     * ship. This is what stops a new nullable-and-branched-on column from
     * landing without anyone having to decide what its null means.
     */
    public function test_every_nullable_column_is_registered(): void
    {
        $missing = [];

        foreach (self::TABLES as $table) {
            $registered = array_keys(self::REGISTRY[$table] ?? []);
            $gap = array_diff($this->nullableColumns($table), $registered);

            if ($gap !== []) {
                $missing[] = sprintf('%s: %s', $table, implode(', ', $gap));
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These nullable columns have no entry in NullSemanticsRegistryTest::REGISTRY:\n\n%s\n\n".
            "Add an entry citing the test that covers the null branch, or 'PENDING-AUDIT' if the ".
            'semantic audit has not reached it yet.',
            implode("\n", $missing),
        ));
    }

    /**
     * The other direction: a registry entry naming a column that no longer
     * exists, or that was migrated to NOT NULL, is stale and must be removed
     * rather than left to look like an open question.
     */
    public function test_no_registry_entry_names_a_stale_column(): void
    {
        $stale = [];

        foreach (self::TABLES as $table) {
            $nullable = array_flip($this->nullableColumns($table));

            foreach (array_keys(self::REGISTRY[$table] ?? []) as $column) {
                if (! isset($nullable[$column])) {
                    $stale[] = sprintf('%s.%s', $table, $column);
                }
            }
        }

        $this->assertSame([], $stale, sprintf(
            "These registry entries name a column that is no longer nullable (or no longer exists):\n\n%s\n\n".
            'Remove the stale entry.',
            implode("\n", $stale),
        ));
    }

    /**
     * A citation is only as good as the test it points at. Verify the class
     * exists and the method exists on it, so a bogus citation - a renamed
     * method, a typo, a test that was deleted - fails loudly instead of
     * standing in for coverage that no longer exists.
     */
    public function test_every_citation_names_a_real_test_method(): void
    {
        $bad = [];

        foreach (self::REGISTRY as $table => $columns) {
            foreach ($columns as $column => $entry) {
                if ($entry === 'PENDING-AUDIT') {
                    continue;
                }

                // One citation or several: a column whose null is branched on
                // in more than one place carries one entry per branch.
                $citations = isset($entry['covered_by']) ? [$entry] : $entry;

                if (! is_array($entry) || $citations === []) {
                    $bad[] = sprintf("%s.%s: entry must be 'PENDING-AUDIT', a citation, or a non-empty list of citations", $table, $column);

                    continue;
                }

                foreach ($citations as $citation) {
                    if (! is_array($citation) || array_keys($citation) !== ['covered_by', 'method']) {
                        $bad[] = sprintf("%s.%s: each citation must be ['covered_by' => ..., 'method' => ...]", $table, $column);

                        continue;
                    }

                    $class = $citation['covered_by'];
                    $method = $citation['method'];

                    if (! class_exists($class)) {
                        $bad[] = sprintf('%s.%s: cited class %s does not exist', $table, $column, $class);

                        continue;
                    }

                    if (! (new ReflectionClass($class))->hasMethod($method)) {
                        $bad[] = sprintf('%s.%s: cited method %s::%s does not exist', $table, $column, $class, $method);
                    }
                }
            }
        }

        $this->assertSame([], $bad, "These registry citations do not resolve:\n\n".implode("\n", $bad));
    }

    /**
     * Pin the PENDING-AUDIT count so it cannot drift silently. A registry
     * entry can move from PENDING-AUDIT to a citation (or to a NOT NULL
     * migration that removes it) only by also updating this constant, which
     * keeps the count an honest measure of how much audit work remains.
     */
    public function test_pending_audit_count_is_pinned(): void
    {
        $count = 0;

        foreach (self::REGISTRY as $columns) {
            foreach ($columns as $entry) {
                if ($entry === 'PENDING-AUDIT') {
                    $count++;
                }
            }
        }

        $this->assertSame(
            self::PENDING_AUDIT_COUNT,
            $count,
            'The PENDING-AUDIT count changed without updating NullSemanticsRegistryTest::PENDING_AUDIT_COUNT. '.
            'Update the constant to match (it may only decrease).',
        );
    }

    /**
     * @return list<string>
     */
    private function nullableColumns(string $table): array
    {
        $nullable = [];

        foreach (Schema::getColumns($table) as $column) {
            if ($column['nullable']) {
                $nullable[] = $column['name'];
            }
        }

        return $nullable;
    }
}
