<?php

namespace Tests\Feature\Models;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest;
use Tests\Feature\Billing\CapacityAndScopeGuardsTest;
use Tests\Feature\Engagement\TimeSheetTest;
use Tests\TestCase;

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
 * This is the registry half of #115. Deciding what each PENDING-AUDIT column's
 * null actually means, and any resulting NOT NULL migration, is a separate,
 * later pass - not this test's job. This test only enforces that the decision
 * gets recorded truthfully once it is made.
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
    private const PENDING_AUDIT_COUNT = 66;

    /**
     * One entry per nullable column on the tables above.
     *
     * Each value is either:
     *   - `['covered_by' => SomeTest::class, 'method' => 'test_...']`, a
     *     citation of an existing test that constructs this column's null
     *     case and asserts what happens - verified below by reflection; or
     *   - the string `'PENDING-AUDIT'`, an honest marker that the semantic
     *     audit has not yet reached this column.
     *
     * @var array<string, array<string, 'PENDING-AUDIT'|array{covered_by: class-string, method: string}>>
     */
    private const REGISTRY = [
        'client_invoices' => [
            'client_agreement_id' => 'PENDING-AUDIT',
            'client_billing_schedule_id' => 'PENDING-AUDIT',
            'issue_date' => 'PENDING-AUDIT',
            'due_date' => 'PENDING-AUDIT',
            'service_period_start' => 'PENDING-AUDIT',
            'service_period_end' => 'PENDING-AUDIT',
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
            'cycle_start' => 'PENDING-AUDIT',
            'cycle_end' => 'PENDING-AUDIT',
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
            'client_project_id' => 'PENDING-AUDIT',
            'line_date' => 'PENDING-AUDIT',
            'hours' => 'PENDING-AUDIT',
            'client_agreement_id' => 'PENDING-AUDIT',
            'client_agreement_recurring_item_id' => 'PENDING-AUDIT',
        ],
        'client_agreements' => [
            'client_project_id' => 'PENDING-AUDIT',
            'source_proposal_id' => 'PENDING-AUDIT',
            'starts_on' => 'PENDING-AUDIT',
            'ends_on' => 'PENDING-AUDIT',
            'agreement_text' => 'PENDING-AUDIT',
            'hourly_rate_amount' => 'PENDING-AUDIT',
            'retainer_amount' => 'PENDING-AUDIT',
            'retainer_minutes' => 'PENDING-AUDIT',
            'rollover_policy' => 'PENDING-AUDIT',
            'activated_at' => 'PENDING-AUDIT',
            'signed_at' => 'PENDING-AUDIT',
            'signed_by_user_id' => 'PENDING-AUDIT',
            'signer_name' => 'PENDING-AUDIT',
            'signer_title' => 'PENDING-AUDIT',
            'terminated_at' => 'PENDING-AUDIT',
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            'catch_up_threshold_minutes' => 'PENDING-AUDIT',
            'period_retainer_minutes' => 'PENDING-AUDIT',
            'period_retainer_amount' => 'PENDING-AUDIT',
            'rollover_months' => 'PENDING-AUDIT',
            'initial_rollover_minutes' => 'PENDING-AUDIT',
            'bill_overage_interim' => 'PENDING-AUDIT',
            'first_cycle_proration' => 'PENDING-AUDIT',
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
            'client_visible_description' => 'PENDING-AUDIT',
            'deleted_at' => 'PENDING-AUDIT',
            'billing_rate_source' => [
                'covered_by' => AgentTimeBillingWorkflowTest::class,
                'method' => 'test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
            ],
            'job_type' => 'PENDING-AUDIT',
            'split_from_time_entry_id' => 'PENDING-AUDIT',
            'subcontractor_billing_mode' => 'PENDING-AUDIT',
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

                if (! is_array($entry) || array_keys($entry) !== ['covered_by', 'method']) {
                    $bad[] = sprintf("%s.%s: entry must be 'PENDING-AUDIT' or ['covered_by' => ..., 'method' => ...]", $table, $column);

                    continue;
                }

                $class = $entry['covered_by'];
                $method = $entry['method'];

                if (! class_exists($class)) {
                    $bad[] = sprintf('%s.%s: cited class %s does not exist', $table, $column, $class);

                    continue;
                }

                if (! (new ReflectionClass($class))->hasMethod($method)) {
                    $bad[] = sprintf('%s.%s: cited method %s::%s does not exist', $table, $column, $class, $method);
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
