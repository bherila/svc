<?php

namespace Tests\Feature\Models;

use App\Console\Commands\Billing\BackfillBillingLedgerCommand;
use App\Console\Commands\Billing\ReplayInvoicesCommand;
use App\Http\Controllers\Api\V1\AgentReadController;
use App\Http\Controllers\Engagement\TimeSheetController;
use App\Services\Billing\AgreementBillingRateResolver;
use App\Services\Billing\AllocationService;
use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DraftInvoiceTimeRegenerator;
use App\Services\Billing\InterimOverageGenerator;
use App\Services\Billing\InvoiceFromTimeService;
use App\Services\Billing\InvoiceLineComposer;
use App\Services\Engagement\ProposalWorkflow;
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
 * Every nullable column on the billing tables must be registered with either a
 * citation of the test that covers its null branch, a named reader that
 * branches on the null but is not yet pinned, or an honest PENDING-AUDIT
 * marker.
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
 * proof - and it certified two of the bugs above as intentional. So every
 * entry here has to name something this test can resolve by reflection, and
 * nothing is accepted on the strength of its comment alone.
 *
 * ## Why there are three states and not two
 *
 * The first version of this registry had two: a citation, or PENDING-AUDIT. An
 * external review of #120 showed both were being read as stronger claims than
 * they are, in opposite directions, and that the difference cost real coverage.
 *
 * A citation is checked by reflection, which can prove a test *exists* but not
 * that it *isolates* the column. Several citations here pointed at tests whose
 * fixture nulled two columns at once, or set the column incidentally: deleting
 * the production guard for one of them left the cited test green, because a
 * sibling null still failed it. `subcontractor_cost_amount` and
 * `subcontractor_cost_currency` both cited a fixture that nulled the pair, and
 * production refuses on `amount === null || currency === ''` - an OR, so
 * neither half was ever isolated. Those citations are gone rather than
 * annotated: a citation that proves nothing is worse than none, because it
 * reads as settled.
 *
 * PENDING-AUDIT was the more dangerous of the two. It was documented as the set
 * "nothing branches on", and it was read that way - but 17 of its 37 columns
 * had live null-sensitive readers. `hours_billed_at_rate` sat in it while being
 * the column whose null-drop from a `SUM` consumed #135, #139 and #142. A
 * registry that under-claims exposure is worse than no registry, because it
 * launders "we have not looked" into "we looked and it is fine".
 *
 * So PENDING-AUDIT now means only what it says: no reader is known. A column
 * with a known reader and no test carries `reader_in` naming that reader, which
 * is checked by reflection exactly as a citation is. It is a weaker claim than a
 * citation and deliberately so - it asserts exposure, not coverage - and it
 * turns 17 invisible holes into a worklist. Pinning them with isolating tests
 * is tracked separately; this file's job is to stop the holes being invisible.
 *
 * ## What is still PENDING-AUDIT
 *
 * Eloquent timestamps, optional text, and lifecycle stamps with no branch on
 * them. They cannot be cited because there is no null branch to cover, and
 * inventing one would be the prose exemption list again in another costume.
 * Retiring them is #73's `NOT NULL` question, not this one's.
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
     * Every registered branch, by identity, that the registry may not lose.
     *
     * An exact set, and the fourth attempt at this ratchet. The first pinned
     * the PENDING-AUDIT count to an equality; the second put a floor under the
     * covered count. Both bound totals, and a total survives a swap. The third
     * named the covered columns - better, but still too coarse, because "this
     * column carries a citation" is satisfied by *any one* of its entries, so a
     * column registering four branches could be cut back to one and pass.
     *
     * So the unit here is the branch, not the column and not a number: one line
     * per `table.column => kind:Fully\Qualified\Class::method` that must still
     * be present. Adding a branch is free; removing one fails by name.
     *
     * Both halves of that identity are load-bearing, and an earlier version of
     * this constant carried neither.
     *
     * The **kind** distinguishes `covered_by` from `reader_in`. Without it the
     * two collapse to the same string, so any citation could be rewritten as a
     * named reader against the same test class and method - which
     * `problemsWithReader()` accepts, since a test class is a class and a test
     * method is a method - and coverage would silently decay into mere exposure
     * with every guard green.
     *
     * The **fully-qualified name** is required because short names collide: this
     * repository already contains `Tests\Feature\ExampleTest` and
     * `Tests\Unit\ExampleTest`. On basenames alone, swapping one class for
     * another in a different namespace with a same-named method preserves the
     * identity and passes. An earlier revision of this file used basenames and
     * claimed the qualified form "makes the pinned list unreadable without
     * making it stricter". That was wrong on the second half.
     *
     * Yes, this duplicates the registry. That is what a ratchet is: the
     * duplication is the part that cannot be edited away by accident, and a
     * deletion that would otherwise read as tidying has to be made twice and
     * defended once.
     *
     * @var list<string>
     */
    private const REGISTERED_BRANCHES = [
        'client_agreements.activated_at => covered_by:Tests\Feature\EngagementWorkflowTest::test_only_an_unstamped_agreement_takes_an_activation_date',
        'client_agreements.agreement_link => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_agreements.bill_overage_interim => covered_by:Tests\Feature\Billing\CapacityAndScopeGuardsTest::test_an_agreement_with_no_interim_policy_bills_no_interim_overage',
        'client_agreements.catch_up_threshold_minutes => covered_by:Tests\Feature\Billing\InvoicingExamplesTest::test_an_unset_threshold_defaults_to_one_hour',
        'client_agreements.catch_up_threshold_minutes => covered_by:Tests\Feature\Billing\InvoicingExamplesTest::test_an_unset_threshold_is_capped_by_a_small_period_retainer_override',
        'client_agreements.client_project_id => covered_by:Tests\Feature\Billing\DeriveTimeEntryRatesTest::test_an_agreement_with_no_project_covers_work_on_any_project',
        'client_agreements.ends_on => covered_by:Tests\Feature\Billing\DeriveTimeEntryRatesTest::test_an_agreement_with_no_end_date_is_still_in_force',
        'client_agreements.first_cycle_proration => covered_by:Tests\Feature\Billing\CapacityAndScopeGuardsTest::test_an_agreement_with_no_stated_first_cycle_policy_prorates_its_opening_month',
        'client_agreements.hourly_rate_amount => covered_by:Tests\Feature\Billing\DeriveTimeEntryRatesTest::test_an_agreement_with_no_rate_prices_nothing',
        'client_agreements.hourly_rate_amount => reader_in:App\Services\Billing\InvoiceLineComposer::addDeferredTerminationLine',
        'client_agreements.initial_rollover_minutes => covered_by:Tests\Unit\Billing\InvoiceLedgerBuilderTest::test_an_agreement_with_no_recorded_opening_rollover_grants_none',
        'client_agreements.period_retainer_amount => covered_by:Tests\Unit\Billing\RetainerCalculatorTest::test_cycle_retainer_falls_back_to_monthly_ledger_terms',
        'client_agreements.period_retainer_minutes => covered_by:Tests\Unit\Billing\RetainerCalculatorTest::test_cycle_retainer_falls_back_to_monthly_ledger_terms',
        'client_agreements.retainer_amount => covered_by:Tests\Unit\Billing\RetainerCalculatorTest::test_an_agreement_with_no_retainer_price_bills_no_retainer_fee',
        'client_agreements.retainer_minutes => covered_by:Tests\Feature\Engagement\TimeSheetTest::test_an_agreement_with_no_retainer_reports_no_capacity',
        'client_agreements.rollover_months => covered_by:Tests\Unit\Billing\InvoiceLedgerBuilderTest::test_an_agreement_with_no_rollover_term_carries_nothing_forward',
        'client_agreements.signed_at => covered_by:Tests\Feature\EngagementWorkflowTest::test_only_an_unsigned_agreement_can_be_signed',
        'client_agreements.source_proposal_id => covered_by:Tests\Feature\EngagementWorkflowTest::test_an_agreement_whose_proposal_link_is_missing_does_not_stop_a_second_being_created',
        'client_agreements.source_proposal_id => reader_in:App\Services\Engagement\ProposalWorkflow::accept',
        'client_agreements.starts_on => covered_by:Tests\Feature\Engagement\TimeSheetTest::test_an_agreement_with_no_start_date_reports_no_capacity',
        'client_agreements.starts_on => reader_in:App\Http\Controllers\Engagement\TimeSheetController::capacityByMonth',
        'client_agreements.starts_on => reader_in:App\Services\Billing\AgreementBillingRateResolver::resolve',
        'client_invoice_lines.client_agreement_id => reader_in:App\Console\Commands\Billing\ReplayInvoicesCommand::snapshot',
        'client_invoice_lines.client_agreement_recurring_item_id => reader_in:App\Console\Commands\Billing\ReplayInvoicesCommand::snapshot',
        'client_invoice_lines.client_project_id => covered_by:Tests\Feature\Billing\InvoiceFromTimeServiceTest::test_a_manual_line_without_a_project_is_accepted_unattributed',
        'client_invoice_lines.hours => reader_in:App\Console\Commands\Billing\ReplayInvoicesCommand::snapshot',
        'client_invoice_lines.line_date => covered_by:Tests\Feature\Billing\CapacityAndScopeGuardsTest::test_an_undated_line_does_not_widen_the_service_period',
        'client_invoices.client_agreement_id => covered_by:Tests\Feature\Billing\DraftInvoiceTimeRegenerationTest::test_a_generated_draft_without_an_agreement_fails_closed',
        'client_invoices.client_agreement_id => reader_in:App\Services\Billing\DraftInvoiceTimeRegenerator::regenerate',
        'client_invoices.client_billing_schedule_id => covered_by:Tests\Feature\Billing\BillingWorkflowTest::test_a_draft_without_a_billing_schedule_is_classified_ad_hoc',
        'client_invoices.client_billing_schedule_id => reader_in:App\Services\Billing\BillingScheduleService::generateDue',
        'client_invoices.cycle_end => reader_in:App\Services\Billing\InterimOverageGenerator::interimOverageHoursForCycle',
        'client_invoices.cycle_start => reader_in:App\Services\Billing\InterimOverageGenerator::interimOverageHoursForCycle',
        'client_invoices.due_date => covered_by:Tests\Feature\Billing\BillingWorkflowTest::test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
        'client_invoices.due_date => reader_in:App\Http\Controllers\Api\V1\AgentReadController::summary',
        'client_invoices.hours_billed_at_rate => reader_in:App\Services\Billing\ClientInvoicingService::totalBilledOveragesThrough',
        'client_invoices.hours_billed_at_rate => reader_in:App\Services\Billing\InterimOverageGenerator::interimOverageHoursForCycle',
        'client_invoices.hours_worked => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.invoice_kind => covered_by:Tests\Feature\Billing\CapacityAndScopeGuardsTest::test_a_migrated_invoice_with_no_kind_still_counts_as_having_sold_the_cycle',
        'client_invoices.invoice_kind => reader_in:App\Services\Billing\DraftInvoiceTimeRegenerator::regenerate',
        'client_invoices.issue_date => covered_by:Tests\Feature\Billing\BillingWorkflowTest::test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
        'client_invoices.negative_hours_balance => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.paid_on => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.retainer_hours_included => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.rollover_hours_used => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.service_period_end => covered_by:Tests\Feature\Billing\CapacityAndScopeGuardsTest::test_a_charged_invoice_with_no_service_period_is_still_counted_as_billed',
        'client_invoices.service_period_end => covered_by:Tests\Feature\Billing\DraftInvoiceTimeRegenerationTest::test_a_cadence_draft_with_no_period_end_fails_closed',
        'client_invoices.service_period_end => reader_in:App\Console\Commands\Billing\ReplayInvoicesCommand::sourceScopeForInvoice',
        'client_invoices.service_period_end => reader_in:App\Services\Billing\InterimOverageGenerator::generateInterimOverageInvoice',
        'client_invoices.service_period_end => reader_in:App\Services\Billing\InvoiceLineComposer::addDeferredTerminationLine',
        'client_invoices.service_period_start => reader_in:App\Console\Commands\Billing\ReplayInvoicesCommand::sourceScopeForInvoice',
        'client_invoices.service_period_start => reader_in:App\Services\Billing\DraftInvoiceTimeRegenerator::regenerate',
        'client_invoices.starting_negative_hours => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.starting_unused_hours => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_invoices.unused_hours_balance => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_time_entries.approved_at => covered_by:Tests\Feature\Billing\AllocationServiceTest::test_fragments_with_and_without_an_approval_timestamp_do_not_recombine',
        'client_time_entries.approved_by_user_id => covered_by:Tests\Feature\Billing\AllocationServiceTest::test_fragments_with_and_without_an_approval_author_do_not_recombine',
        'client_time_entries.billing_rate_amount => covered_by:Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest::test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
        'client_time_entries.billing_rate_amount => reader_in:App\Services\Billing\InvoiceFromTimeService::selectedTimeTerms',
        'client_time_entries.billing_rate_source => covered_by:Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest::test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
        'client_time_entries.billing_rate_source => covered_by:Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest::test_a_stored_rate_with_no_provenance_is_replaced_by_the_agreement_rate',
        'client_time_entries.billing_rate_source => reader_in:App\Services\Billing\AllocationService::canMerge',
        'client_time_entries.client_task_id => covered_by:Tests\Feature\Billing\AllocationServiceTest::test_fragments_with_and_without_a_task_do_not_recombine',
        'client_time_entries.client_task_id => reader_in:App\Services\Billing\AllocationService::canMerge',
        'client_time_entries.client_visible_description => covered_by:Tests\Feature\AgentApi\AgentReadApiTest::test_legacy_client_visible_time_never_falls_back_to_internal_description',
        'client_time_entries.currency => covered_by:Tests\Feature\Engagement\TimeSheetTest::test_approval_supplies_a_currency_an_older_entry_lacks',
        'client_time_entries.currency => reader_in:App\Services\Billing\InvoiceFromTimeService::selectedTimeTerms',
        'client_time_entries.deleted_at => covered_by:Tests\Feature\Billing\DraftInvoiceTimeRegenerationTest::test_deleting_approved_time_rebuilds_the_cadence_draft_without_it',
        'client_time_entries.job_type => covered_by:Tests\Feature\Billing\AllocationServiceTest::test_an_absent_value_is_not_the_word_null',
        'client_time_entries.job_type => reader_in:App\Console\Commands\Billing\BackfillBillingLedgerCommand::applyRow',
        'client_time_entries.job_type => reader_in:App\Services\Billing\AllocationService::canMerge',
        'client_time_entries.split_from_time_entry_id => covered_by:Tests\Feature\Billing\AllocationServiceTest::test_entries_that_merely_look_alike_are_never_merged',
        'client_time_entries.subcontractor_billing_mode => covered_by:Tests\Feature\Billing\RetainerDrawConsistencyTest::test_each_subcontractor_mode_has_one_consistent_billing_path',
        'client_time_entries.subcontractor_billing_mode => covered_by:Tests\Feature\Billing\RetainerDrawConsistencyTest::test_a_null_billing_mode_is_read_as_ordinary_consultant_time',
        'client_time_entries.subcontractor_cost_amount => covered_by:Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest::test_flat_hourly_time_with_a_currency_but_no_amount_is_refused',
        'client_time_entries.subcontractor_cost_amount => covered_by:Tests\Feature\Billing\RetainerDrawConsistencyTest::test_a_cost_with_no_mode_is_excluded_from_the_retainer',
        'client_time_entries.subcontractor_cost_amount => reader_in:App\Services\Billing\InvoiceLineComposer::addFlatHourlySubcontractorEntries',
        'client_time_entries.subcontractor_cost_currency => covered_by:Tests\Feature\AgentApi\AgentTimeBillingWorkflowTest::test_flat_hourly_time_with_an_amount_but_no_currency_is_refused',
        'client_time_entries.subcontractor_cost_currency => reader_in:App\Services\Billing\InvoiceLineComposer::addFlatHourlySubcontractorEntries',
        'client_time_entries.subcontractor_cost_metadata => reader_in:App\Services\Billing\AllocationService::canMerge',
    ];

    /**
     * The columns whose null has no known reader, by name - exactly.
     *
     * A named set rather than a ceiling, for the reason the ceiling failed: a
     * bound leaves reusable slack, so once one pending column resolved, a new
     * unexamined nullable column could take its place in the count and land
     * without anyone deciding anything.
     *
     * Compared as an exact set, not a subset, because a one-way membership test
     * leaves the same kind of slack one level down: a resolved column left
     * listed here is a standing permission for it to revert to PENDING-AUDIT
     * later. Resolving a column therefore means deleting its name from this
     * list, which is one more edit and the entire point - both directions are
     * now deliberate.
     *
     * @var list<string>
     */
    private const PENDING_COLUMNS = [
        'client_agreements.agreement_text',
        'client_agreements.created_at',
        'client_agreements.rollover_policy',
        'client_agreements.signed_by_user_id',
        'client_agreements.signer_name',
        'client_agreements.signer_title',
        'client_agreements.terminated_at',
        'client_agreements.updated_at',
        'client_invoice_lines.created_at',
        'client_invoice_lines.updated_at',
        'client_invoices.created_at',
        'client_invoices.issued_at',
        'client_invoices.notes',
        'client_invoices.updated_at',
        'client_invoices.void_reason',
        'client_invoices.voided_at',
        'client_time_entries.created_at',
        'client_time_entries.updated_at',
    ];

    /**
     * One entry per nullable column on the tables above.
     *
     * Each value is one of:
     *   - `['covered_by' => SomeTest::class, 'method' => 'test_...']`, a
     *     citation of an existing test that constructs this column's null case
     *     and asserts what happens;
     *   - `['reader_in' => SomeClass::class, 'reads' => 'method']`, naming
     *     production code that branches on this column's null where no test
     *     pins the branch yet - exposure, not coverage;
     *   - a list mixing the two, for a column whose null is read in more than
     *     one place, where some branches are pinned and others are only known;
     *   - the string `'PENDING-AUDIT'`, meaning no reader is known.
     *
     * A list rather than a second registry: one citation on a column with two
     * null branches is worse than none, because it reads as settled. That is
     * how `service_period_end` came to look audited while the branch that
     * decides whether overage is charged twice went uncovered until #135.
     *
     * @var array<string, array<string, 'PENDING-AUDIT'|array{covered_by: class-string, method: string}|array{reader_in: class-string, reads: string}|list<array{covered_by: class-string, method: string}|array{reader_in: class-string, reads: string}>>>
     */
    private const REGISTRY = [
        'client_invoices' => [
            // For a *generated* draft, no agreement means no terms to reprice
            // against and regeneration refuses. Not a global rule: an ad-hoc
            // invoice legitimately has no agreement and regenerates from the
            // rate snapshots on its own entries, because the regenerator routes
            // on kind before it asks for an agreement.
            'client_agreement_id' => [
                [
                    'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                    'method' => 'test_a_generated_draft_without_an_agreement_fails_closed',
                ],
                ['reader_in' => DraftInvoiceTimeRegenerator::class, 'reads' => 'regenerate'],
            ],
            // What the citation proves is narrower than it reads: it is the
            // default `InvoiceLifecycleService::createDraft` picks when no kind
            // is passed. It is not a contract that a null schedule means an
            // operator typed the invoice - `ClientInvoicingService` creates
            // cadence invoices without setting this column at all, so a future
            // guard keying "ad hoc" off a null here would misclassify them.
            //
            // That last sentence is a note, not a reader, and an earlier
            // revision wrongly registered `ClientInvoicingService` as one: that
            // class never mentions this column, it merely omits it on create.
            // `reader_in` means the code branches on the null, and only
            // `BillingScheduleService::generateDue()` does - it looks for an
            // existing invoice with `where('client_billing_schedule_id', ...)`,
            // which a null row can never match, so a cadence invoice carrying
            // no schedule is invisible to that duplicate check.
            'client_billing_schedule_id' => [
                [
                    'covered_by' => BillingWorkflowTest::class,
                    'method' => 'test_a_draft_without_a_billing_schedule_is_classified_ad_hoc',
                ],
                ['reader_in' => BillingScheduleService::class, 'reads' => 'generateDue'],
            ],
            // Not issued yet - on the draft path, where issuing stamps the
            // workspace's calendar date. Not a global reading: `issue()`
            // returns early for an invoice that is already charged, so an
            // imported issued or paid invoice with no date keeps the null and
            // nothing ever fills it. The citation covers the transition, not
            // the column's whole meaning.
            'issue_date' => [
                'covered_by' => BillingWorkflowTest::class,
                'method' => 'test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
            ],
            // No stated term: issuing makes it due on the issue date. A null
            // also drops the invoice out of the overdue query, which compares
            // with `whereDate`, so an imported invoice with a balance and no due
            // date is collectible but never overdue. Nothing pins that (#149).
            'due_date' => [
                [
                    'covered_by' => BillingWorkflowTest::class,
                    'method' => 'test_issuing_an_undated_invoice_uses_the_workspace_calendar_date',
                ],
                ['reader_in' => AgentReadController::class, 'reads' => 'summary'],
            ],
            // Was cited against the cadence regeneration refusal, but that
            // fixture nulls the cycle columns too and the refusal fires on
            // either pair, so the citation never isolated this column. The null
            // is also read during generation, where it initialises to the
            // earliest dated work line, and by replay, which builds no source
            // scope at all when either boundary is missing - so the invoice
            // proves against zero source minutes rather than against its own.
            'service_period_start' => [
                ['reader_in' => DraftInvoiceTimeRegenerator::class, 'reads' => 'regenerate'],
                ['reader_in' => ReplayInvoicesCommand::class, 'reads' => 'sourceScopeForInvoice'],
            ],
            // Four branches, two of them pinned. The regeneration refusal is
            // covered; the billed-overage window is covered since #135, where a
            // `<=` that answers false for a null dropped charged invoices out
            // of the sum and their overage was billed twice. The interim
            // generator carries a parallel already-billed sum with its own
            // `orWhereNull`, added in the #139 fix-forward, and nothing pins it.
            // The fourth is a coercion rather than a predicate: the composer
            // hands this column to `Carbon::parse()` unguarded when dating a
            // deferred-termination line, and `parse(null)` is *now*, so a
            // malformed invoice dates its line today. That one is #135 item 2.
            'service_period_end' => [
                [
                    'covered_by' => DraftInvoiceTimeRegenerationTest::class,
                    'method' => 'test_a_cadence_draft_with_no_period_end_fails_closed',
                ],
                [
                    'covered_by' => CapacityAndScopeGuardsTest::class,
                    'method' => 'test_a_charged_invoice_with_no_service_period_is_still_counted_as_billed',
                ],
                ['reader_in' => InterimOverageGenerator::class, 'reads' => 'generateInterimOverageInvoice'],
                ['reader_in' => InvoiceLineComposer::class, 'reads' => 'addDeferredTerminationLine'],
                ['reader_in' => ReplayInvoicesCommand::class, 'reads' => 'sourceScopeForInvoice'],
            ],
            'notes' => 'PENDING-AUDIT',
            'issued_at' => 'PENDING-AUDIT',
            'voided_at' => 'PENDING-AUDIT',
            'created_at' => 'PENDING-AUDIT',
            'updated_at' => 'PENDING-AUDIT',
            'void_reason' => 'PENDING-AUDIT',
            // The sold-cycle SQL guard is pinned. The model's `invoiceKindValue()`
            // fallback is a second, unpinned reading of the same null: it decides
            // whether regeneration takes the ad-hoc selected-time path or the
            // generated cadence one.
            'invoice_kind' => [
                [
                    'covered_by' => CapacityAndScopeGuardsTest::class,
                    'method' => 'test_a_migrated_invoice_with_no_kind_still_counts_as_having_sold_the_cycle',
                ],
                ['reader_in' => DraftInvoiceTimeRegenerator::class, 'reads' => 'regenerate'],
            ],
            // Cited against an interim refusal whose fixture nulls both columns,
            // so neither guard was isolated - dropping one leaves the test
            // failing through the other. The consequential reader is the cycle
            // lookup, which matches on both and cannot see a row missing either
            // (#141).
            'cycle_start' => [
                'reader_in' => InterimOverageGenerator::class,
                'reads' => 'interimOverageHoursForCycle',
            ],
            'cycle_end' => [
                'reader_in' => InterimOverageGenerator::class,
                'reads' => 'interimOverageHoursForCycle',
            ],
            // The invoice hour-balance columns are restore-repair fields, not
            // write-only ones. The backfill fills each only where the
            // destination value is null, and repeats that as a `WHERE ... IS
            // NULL` predicate at write time, so the null selects "the source may
            // fill this hole" against "preserve the operator's correction".
            'paid_on' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'retainer_hours_included' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'hours_worked' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'rollover_hours_used' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'unused_hours_balance' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'negative_hours_balance' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            // The one that proves why PENDING-AUDIT had to stop meaning "inert".
            // This column was filed as something nothing branches on while being
            // the column whose null-drop from a `SUM` is #135: three separate
            // sums total the overage an agreement has already been charged, and
            // SQL aggregation contributes nothing for a null, so a restored
            // charged invoice with a null here reads as zero already billed and
            // its hours are sold a second time (#144).
            'hours_billed_at_rate' => [
                ['reader_in' => ClientInvoicingService::class, 'reads' => 'totalBilledOveragesThrough'],
                ['reader_in' => InterimOverageGenerator::class, 'reads' => 'interimOverageHoursForCycle'],
            ],
            'starting_unused_hours' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
            'starting_negative_hours' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
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
            // Replay reads all three when it builds a line's identity and its
            // correction proofs: a null hours makes the minute conversion return
            // null and the capacity draw unprovable, a null agreement normalises
            // to an empty identity that fails the agreement-owned-line check,
            // and a null recurring item is encoded as "no auxiliary owner" in
            // the allocation signature. None is write-only.
            'hours' => ['reader_in' => ReplayInvoicesCommand::class, 'reads' => 'snapshot'],
            'client_agreement_id' => ['reader_in' => ReplayInvoicesCommand::class, 'reads' => 'snapshot'],
            'client_agreement_recurring_item_id' => ['reader_in' => ReplayInvoicesCommand::class, 'reads' => 'snapshot'],
        ],
        'client_agreements' => [
            // Was cited against the generic derive-rate test, where setting this
            // to the entry's own project leaves every assertion unchanged - so
            // it proved neither that null means company-wide nor that a
            // project-specific agreement outranks a company-wide one.
            //
            // #143's test settles the first half: a company-wide agreement
            // prices work on a project it never names, against a rival scoped
            // to a different project. The rival is given the *later* start
            // date on purpose, so it would win the ordering if the column were
            // not read at all - without that the test passes against a resolver
            // that ignores project scope entirely and falls through to the id
            // tie-break, which the first draft of it did.
            //
            // The specificity ordering - a project-specific agreement
            // outranking a company-wide one - is still unpinned.
            'client_project_id' => [
                'covered_by' => DeriveTimeEntryRatesTest::class,
                'method' => 'test_an_agreement_with_no_project_covers_work_on_any_project',
            ],
            // Proposal acceptance is idempotent only through this column: it
            // asks the proposal's `agreements()` relationship whether one
            // already exists, and a null makes the row invisible to that
            // relationship, so accepting again creates a second active agreement
            // and a second set of recurring items (#148).
            //
            // The covering test pins that as the behaviour rather than
            // asserting it is prevented. #148 established the fix cannot live in
            // `accept()` - the only evidence of the tie is the missing link, and
            // guessing at it inside a write path trades a duplicate for a
            // mis-attribution - so the population is sized by
            // `svc:engagement:audit-unlinked-proposal-agreements` and the links
            // repaired outside. Pinned so that changing it has to be deliberate.
            'source_proposal_id' => [
                [
                    'covered_by' => EngagementWorkflowTest::class,
                    'method' => 'test_an_agreement_whose_proposal_link_is_missing_does_not_stop_a_second_being_created',
                ],
                ['reader_in' => ProposalWorkflow::class, 'reads' => 'accept'],
            ],
            // Three readers, and they do not agree with each other. The pinned
            // one treats a null as "not ready" and reports no capacity. The rate
            // resolver treats it as already in force (`whereNull OR <=`), and so
            // does the active-agreement lookup. The timesheet capacity query
            // uses `whereNotNull` and drops it. So an undated agreement can
            // stamp its rate onto approved time while contributing no capacity -
            // that disagreement is unresolved, not a settled meaning (#147).
            'starts_on' => [
                [
                    'covered_by' => TimeSheetTest::class,
                    'method' => 'test_an_agreement_with_no_start_date_reports_no_capacity',
                ],
                ['reader_in' => AgreementBillingRateResolver::class, 'reads' => 'resolve'],
                ['reader_in' => TimeSheetController::class, 'reads' => 'capacityByMonth'],
            ],
            // Also cited against the derive-rate test, where any date after the
            // work date leaves every assertion unchanged - so the citation could
            // not distinguish "null means open-ended" from "this field is
            // unused". Demoted to the reader that actually clips on it.
            // An open term, not a closed one. The resolver admits `ends_on IS
            // NULL OR ends_on >= worked_on`, so answering false for the null -
            // which a bare comparison does, since SQL says false rather than
            // unknown - would strand every open-ended agreement, which is how
            // an ordinary retainer is written. Pinned against a rival that
            // expired before the work and starts later, so the column has to be
            // read for the open-ended one to win.
            'ends_on' => [
                'covered_by' => DeriveTimeEntryRatesTest::class,
                'method' => 'test_an_agreement_with_no_end_date_is_still_in_force',
            ],
            'agreement_text' => 'PENDING-AUDIT',
            // Unpriced, which is not free - in the rate lookup, which refuses
            // rather than stamping a rate. Four other readers disagree and
            // coerce the null to zero, which prices deferred termination work
            // and interim overage at nothing rather than refusing it.
            'hourly_rate_amount' => [
                [
                    'covered_by' => DeriveTimeEntryRatesTest::class,
                    'method' => 'test_an_agreement_with_no_rate_prices_nothing',
                ],
                ['reader_in' => InvoiceLineComposer::class, 'reads' => 'addDeferredTerminationLine'],
            ],
            // No *monthly* retainer price, so the monthly branch bills no
            // retainer fee. It does not mean no fee: an agreement with
            // `period_retainer_amount` set still bills one on the period branch.
            'retainer_amount' => [
                'covered_by' => RetainerCalculatorTest::class,
                'method' => 'test_an_agreement_with_no_retainer_price_bills_no_retainer_fee',
            ],
            // No *monthly* recurring capacity, on the same terms: an agreement
            // with `period_retainer_minutes` set still grants a pool.
            'retainer_minutes' => [
                'covered_by' => TimeSheetTest::class,
                'method' => 'test_an_agreement_with_no_retainer_reports_no_capacity',
            ],
            'rollover_policy' => 'PENDING-AUDIT',
            // Never activated - on the draft path, where activation stamps the
            // date and a later reactivation preserves the one recorded. Same
            // limit as `issue_date`: `activate()` returns early for an
            // agreement already `active`, so an imported active agreement with
            // no stamp stays active-and-unstamped and null does not mean "never
            // activated" for it.
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
            // Unstated: one hour, capped by the period-aware retainer capacity.
            // One citation pins the ordinary default and the other gives the
            // agreement only half an hour through the period override, pinning
            // both the cap and which retainer reading supplies it.
            'catch_up_threshold_minutes' => [
                [
                    'covered_by' => InvoicingExamplesTest::class,
                    'method' => 'test_an_unset_threshold_defaults_to_one_hour',
                ],
                // #152: the default is derived from period-aware capacity, so a
                // period override moves it, and the cap half of the note above
                // is pinned now too.
                [
                    'covered_by' => InvoicingExamplesTest::class,
                    'method' => 'test_an_unset_threshold_is_capped_by_a_small_period_retainer_override',
                ],
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
            'agreement_link' => ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
        ],
        'client_time_entries' => [
            // Part of the fragment-recombination signature, where null is
            // compared as a typed value distinct from a task id and decides
            // whether two fragments may merge. Treating it as inert would let
            // fragments that differ only in task attribution recombine, and the
            // survivor's values would silently replace the other's.
            //
            // #153 closed #146 and with it the reason this waited: the
            // signature no longer omits the fields the splitter preserves, and
            // that test pins this column's null on its own - the split baseline
            // carries no task and exactly one fragment is given one.
            'client_task_id' => [
                [
                    'covered_by' => AllocationServiceTest::class,
                    'method' => 'test_fragments_with_and_without_a_task_do_not_recombine',
                ],
                ['reader_in' => AllocationService::class, 'reads' => 'canMerge'],
            ],
            // Null is permitted for flat-hourly and direct entries, which is
            // what the citation covers. On ordinary billable time the same null
            // blocks approval and makes the entry unselectable for an explicit
            // invoice, and that branch is unpinned.
            'billing_rate_amount' => [
                [
                    'covered_by' => AgentTimeBillingWorkflowTest::class,
                    'method' => 'test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
                ],
                ['reader_in' => InvoiceFromTimeService::class, 'reads' => 'selectedTimeTerms'],
            ],
            // Approval repairs a missing currency on a draft, which is the
            // pinned branch. It does not reach an entry that is already approved
            // or invoiced: there, explicit invoicing requires the stored currency
            // to equal the invoice's, and a null matches nothing, so a preserved
            // rate with no currency is permanently uninvoiceable.
            'currency' => [
                [
                    'covered_by' => TimeSheetTest::class,
                    'method' => 'test_approval_supplies_a_currency_an_older_entry_lacks',
                ],
                ['reader_in' => InvoiceFromTimeService::class, 'reads' => 'selectedTimeTerms'],
            ],
            // Missing approval metadata, independently of status. Imported or
            // legacy entries may already be `approved` while either stamp is
            // null. #153 put both in the fragment merge signature so a stamped
            // fragment cannot be folded into one whose audit metadata is
            // absent. A merge deletes the loser, so a false equality here loses
            // an approval record rather than merely widening a query.
            'approved_by_user_id' => [
                'covered_by' => AllocationServiceTest::class,
                'method' => 'test_fragments_with_and_without_an_approval_author_do_not_recombine',
            ],
            'approved_at' => [
                'covered_by' => AllocationServiceTest::class,
                'method' => 'test_fragments_with_and_without_an_approval_timestamp_do_not_recombine',
            ],
            // Both once cited a fixture that nulls the pair, while production
            // refuses on `amount === null || currency === ''`. Because that is
            // an OR, deleting either half left the cited test green on the
            // other's null, so neither column was ever isolated. #143 replaced
            // that fixture with one test per column, each holding the sibling
            // non-null so only its own column can decide the refusal - verified
            // by deleting each half of the OR in turn and watching exactly one
            // test fail.
            //
            // The composer's reader stays a named reader: it refuses the same
            // pair, but on an entry that has already been approved, so the
            // approval guard above it means no such row can reach it in a test
            // without writing an impossible fixture.
            'subcontractor_cost_amount' => [
                [
                    'covered_by' => AgentTimeBillingWorkflowTest::class,
                    'method' => 'test_flat_hourly_time_with_a_currency_but_no_amount_is_refused',
                ],
                // The retainer scope reads it too, and reads it differently: a
                // cost with no mode is excluded fail-closed rather than
                // refused. Isolated with the mode held null on both rows.
                [
                    'covered_by' => RetainerDrawConsistencyTest::class,
                    'method' => 'test_a_cost_with_no_mode_is_excluded_from_the_retainer',
                ],
                ['reader_in' => InvoiceLineComposer::class, 'reads' => 'addFlatHourlySubcontractorEntries'],
            ],
            'subcontractor_cost_currency' => [
                [
                    'covered_by' => AgentTimeBillingWorkflowTest::class,
                    'method' => 'test_flat_hourly_time_with_an_amount_but_no_currency_is_refused',
                ],
                ['reader_in' => InvoiceLineComposer::class, 'reads' => 'addFlatHourlySubcontractorEntries'],
            ],
            // Also part of the fragment signature. #153 replaced the json
            // encoding this note described with a direct typed comparison,
            // because encoding reintroduced the ambiguity it was meant to
            // remove - `?? 'null'` made a real null and the literal string
            // "null" identical. Compared as arrays, a null stays a null.
            'subcontractor_cost_metadata' => ['reader_in' => AllocationService::class, 'reads' => 'canMerge'],
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
            // Irrelevant on flat and direct entries, which is what the citation
            // covers. On ordinary time it is load-bearing in the other
            // direction: the "preserve the explicit stored rate" branch fires
            // only on `source === 'explicit'`, so a null sends a legacy draft
            // back through agreement-rate resolution and a rate change silently
            // replaces the stored one.
            'billing_rate_source' => [
                [
                    'covered_by' => AgentTimeBillingWorkflowTest::class,
                    'method' => 'test_flat_hourly_and_direct_entries_approve_without_an_ordinary_agreement_rate',
                ],
                // The branch that discards a stated rate. #143 pins it with a
                // pair differing *only* in the source - both carry the same
                // stored amount - so the citation cannot be satisfied by the
                // `billing_rate_amount !== null` half of the same condition.
                [
                    'covered_by' => AgentTimeBillingWorkflowTest::class,
                    'method' => 'test_a_stored_rate_with_no_provenance_is_replaced_by_the_agreement_rate',
                ],
                // Third reader, added by #153: provenance survives
                // recombination, so an `explicit` rate is never replaced by one
                // re-resolved from the agreement. Not a second citation - that
                // case diverges two non-null sources, so it pins no null.
                ['reader_in' => AllocationService::class, 'reads' => 'canMerge'],
            ],
            // Also in the fragment signature. The dedicated collision test
            // isolates a real null from the literal text `null`, proving the
            // typed tuple keeps absence distinct from user-entered content.
            'job_type' => [
                [
                    'covered_by' => AllocationServiceTest::class,
                    'method' => 'test_an_absent_value_is_not_the_word_null',
                ],
                ['reader_in' => BackfillBillingLedgerCommand::class, 'reads' => 'applyRow'],
                ['reader_in' => AllocationService::class, 'reads' => 'canMerge'],
            ],
            // Not a fragment of anything. Lineage is the only thing that makes
            // two rows one entry, so entries that merely look alike - same day,
            // person, project and description - are never merged.
            'split_from_time_entry_id' => [
                'covered_by' => AllocationServiceTest::class,
                'method' => 'test_entries_that_merely_look_alike_are_never_merged',
            ],
            // Consultant time, not "unknown": null draws on the retainer pool
            // and is invoiceable at the client rate. The mode-consistency test
            // covers the three stated modes; #143 added the isolating half,
            // which varies only the mode at a null cost - the earlier fixture
            // varied the mode on a cost-bearing pair and passed with the
            // null-mode requirement deleted from the scope entirely, because
            // the cost excluded the row on its own.
            'subcontractor_billing_mode' => [
                [
                    'covered_by' => RetainerDrawConsistencyTest::class,
                    'method' => 'test_each_subcontractor_mode_has_one_consistent_billing_path',
                ],
                [
                    'covered_by' => RetainerDrawConsistencyTest::class,
                    'method' => 'test_a_null_billing_mode_is_read_as_ordinary_consultant_time',
                ],
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
            $registered = array_keys(self::REGISTRY[$table]);
            $gap = array_diff($this->nullableColumns($table), $registered);

            if ($gap !== []) {
                $missing[] = sprintf('%s: %s', $table, implode(', ', $gap));
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These nullable columns have no entry in NullSemanticsRegistryTest::REGISTRY:\n\n%s\n\n".
            'Add an entry citing the test that covers the null branch, naming the reader that '.
            "branches on it, or 'PENDING-AUDIT' if no reader is known.",
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

            foreach (array_keys(self::REGISTRY[$table]) as $column) {
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
     * A citation is only as good as the test it points at, and a named reader
     * only as good as the code it points at. Resolve both by reflection so a
     * rename, a typo or a deletion fails loudly instead of standing in for
     * something that no longer exists.
     *
     * A citation must additionally resolve to a *public test method on a test
     * class*. A bare `hasMethod()` accepted `setUp`, data providers, protected
     * helpers and inherited methods, which meant a citation could be pointed at
     * a method that runs no assertions and still pass.
     */
    public function test_every_citation_names_a_real_test_method(): void
    {
        $bad = [];

        foreach (self::REGISTRY as $table => $columns) {
            foreach ($columns as $column => $entry) {
                if ($entry === 'PENDING-AUDIT') {
                    continue;
                }

                // One entry or several: a column whose null is branched on in
                // more than one place carries one per branch.
                $entries = isset($entry['covered_by']) || isset($entry['reader_in']) ? [$entry] : $entry;
                $seen = [];

                foreach ($entries as $one) {
                    $bad = [...$bad, ...$this->problemsWith($table, (string) $column, $one)];

                    // A list is a claim that the null is branched on in more
                    // than one place. Repeating one entry makes a column look
                    // twice-evidenced on the strength of a single test, which
                    // is the over-claim this registry exists to prevent - in
                    // miniature. Across columns a shared citation is often
                    // legitimate, so this is scoped to one column's list.
                    //
                    // Lowercased because PHP resolves both class and method
                    // names case-insensitively: `test_a_thing` and
                    // `test_A_thing` reach the same method, so comparing the
                    // strings as written would let one branch be spelled two
                    // ways and counted twice.
                    $key = strtolower(implode('::', $one));

                    if (isset($seen[$key])) {
                        $bad[] = sprintf('%s.%s: names %s twice; a list must be one entry per distinct branch', $table, $column, $key);
                    }

                    $seen[$key] = true;
                }

                // An entry must register at least one branch. `[]` is the way
                // that fails: it satisfies neither `isset()` above, so it is
                // taken for a list, the loop runs zero times, and the column
                // keeps its key for both schema guards while every scrap of
                // evidence for it is gone.
                //
                // Asked of the same helper the ratchet uses, rather than by
                // comparing `$entry` or a counter against zero. Both of those
                // are provably false against the current literal and read as
                // dead code. This phrasing states the invariant the ratchet
                // actually consumes - "this entry yields a branch" - and asks
                // it of a `mixed` parameter, so it stays a real question.
                //
                // Worth stating why this is checked at runtime at all: it was
                // briefly deleted on the strength of a PHPStan proof, and that
                // proof does not hold in CI. `phpstan.neon` analyses app/,
                // bootstrap/, config/, database/ and routes/ - `tests/` is not
                // among them, and the analysis only ran here because the file
                // was named on the command line, which overrides the configured
                // paths. A guard enforced only by an argument nobody passes is
                // not enforced.
                if ($this->branchIdentities($entry) === []) {
                    $bad[] = sprintf('%s.%s: registers no branch at all', $table, $column);
                }
            }
        }

        $this->assertSame([], $bad, "These registry entries do not resolve:\n\n".implode("\n", $bad));
    }

    /**
     * The audited table list and the registry must name the same tables.
     *
     * Without this the schema ratchet has a hole big enough to drive a table
     * through. The two schema-direction tests iterate TABLES, while citation
     * checking and the pending ceiling iterate REGISTRY - so dropping a table
     * from TABLES and leaving its REGISTRY block in place removes it from
     * schema checking entirely, while its citations still resolve and its
     * PENDING entries still count. A new nullable column on that table then
     * lands unregistered and nothing says so. The reverse - a registry block
     * for a table nobody audits - is padding that inflates the pending ceiling
     * against a schema that is never consulted.
     *
     * The literal list is deliberate duplication, and it is the ratchet: it
     * makes narrowing the audit an edit someone has to make on purpose, rather
     * than a deletion that looks like tidying.
     */
    public function test_the_audited_tables_are_exactly_the_registered_ones(): void
    {
        $audited = self::TABLES;
        $registered = array_keys(self::REGISTRY);
        sort($audited);
        sort($registered);

        $this->assertSame(
            $audited,
            $registered,
            'NullSemanticsRegistryTest::TABLES and ::REGISTRY name different tables. '.
            'Every audited table needs a registry block, and every registry block needs to be audited.',
        );

        $expected = [
            'client_agreements',
            'client_invoice_lines',
            'client_invoices',
            'client_time_entries',
        ];

        $this->assertSame(
            $expected,
            $audited,
            'The set of tables audited by #115 changed. Removing one drops it out of the schema ratchet '.
            'silently, so this list has to be edited on purpose and defended in review.',
        );
    }

    /**
     * The ratchet proper: no registered branch may disappear, and no column may
     * quietly join the unexamined set.
     *
     * Three previous versions of this were defeatable, each in the same way -
     * they bounded an aggregate, and an aggregate survives a trade. A pinned
     * count fell to demote-one/promote-one. A floor under the covered count
     * fell to the same. Naming the covered columns fell to cutting a column
     * with four registered branches back to one, since it still "carried a
     * citation". The lesson each time was the same and is finally applied
     * here: ratchet on identity, never on a total.
     */
    public function test_the_registry_may_only_get_stronger(): void
    {
        $present = [];

        foreach (self::REGISTRY as $table => $columns) {
            foreach ($columns as $column => $entry) {
                foreach ($this->branchIdentities($entry) as $identity) {
                    $present[strtolower(sprintf('%s.%s => %s', $table, $column, $identity))] = true;
                }
            }
        }

        $listedBranches = array_map(strtolower(...), self::REGISTERED_BRANCHES);
        $presentBranches = array_keys($present);
        sort($listedBranches);
        sort($presentBranches);

        // Equality, not containment. A subset check protects only what is
        // already listed, so a branch added to REGISTRY alone is unprotected
        // from the moment it lands - and once that head merges, deleting it
        // again passes too, because it never entered the pinned set. The
        // registry could then weaken relative to the head before it while every
        // guard stayed green. Requiring equality means an addition updates the
        // ratchet in the same commit that makes it.
        $this->assertSame($listedBranches, $presentBranches, sprintf(
            "REGISTERED_BRANCHES no longer matches the registry.\n\n".
            "Registered but not pinned: %s\nPinned but no longer registered: %s\n\n".
            'A branch is one place the null is read. Add new ones to the constant in the commit that adds them, '.
            'and remove a pin only deliberately - losing a branch loses the evidence for it, whatever else the '.
            'column still carries.',
            implode(', ', array_diff($presentBranches, $listedBranches)) ?: '(none)',
            implode(', ', array_diff($listedBranches, $presentBranches)) ?: '(none)',
        ));

        $pending = [];

        foreach (self::REGISTRY as $table => $columns) {
            foreach ($columns as $column => $entry) {
                if ($entry === 'PENDING-AUDIT') {
                    $pending[] = sprintf('%s.%s', $table, $column);
                }
            }
        }

        $listed = self::PENDING_COLUMNS;
        sort($pending);
        sort($listed);

        $this->assertSame($listed, $pending, sprintf(
            "The PENDING-AUDIT set no longer matches PENDING_COLUMNS.\n\n".
            "Joined without being listed: %s\nListed but no longer pending: %s\n\n".
            'Both directions are deliberate. A column joining the unexamined set has to be admitted by name. '.
            'A column leaving it has to be struck off, because a resolved name left listed is a standing '.
            'permission for that column to revert to PENDING-AUDIT later and still pass.',
            implode(', ', array_diff($pending, $listed)) ?: '(none)',
            implode(', ', array_diff($listed, $pending)) ?: '(none)',
        ));
    }

    /**
     * The identity of every branch an entry registers.
     *
     * `kind:Fully\Qualified\Class::method`. The kind is what stops a citation
     * being rewritten as a named reader against the same test and passing as
     * the same branch; the qualified name is what stops two same-named classes
     * in different namespaces standing in for each other.
     *
     * @return list<string>
     */
    private function branchIdentities(mixed $entry): array
    {
        if ($entry === 'PENDING-AUDIT' || ! is_array($entry) || $entry === []) {
            return [];
        }

        $entries = isset($entry['covered_by']) || isset($entry['reader_in']) ? [$entry] : $entry;
        $identities = [];

        foreach ($entries as $one) {
            if (! is_array($one)) {
                continue;
            }

            $kind = isset($one['covered_by']) ? 'covered_by' : (isset($one['reader_in']) ? 'reader_in' : null);

            if ($kind === null) {
                continue;
            }

            $class = $kind === 'covered_by' ? $one['covered_by'] : $one['reader_in'];
            $method = $one[$kind === 'covered_by' ? 'method' : 'reads'] ?? null;

            if (! is_string($class) || ! is_string($method)) {
                continue;
            }

            $identities[] = sprintf('%s:%s::%s', $kind, $class, $method);
        }

        return $identities;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function problemsWith(string $table, string $column, array $entry): array
    {
        $keys = array_keys($entry);

        if ($keys === ['covered_by', 'method']) {
            return $this->problemsWithCitation($table, $column, $entry['covered_by'], $entry['method']);
        }

        if ($keys === ['reader_in', 'reads']) {
            return $this->problemsWithReader($table, $column, $entry['reader_in'], $entry['reads']);
        }

        return [sprintf(
            "%s.%s: each entry must be ['covered_by' => ..., 'method' => ...] or ['reader_in' => ..., 'reads' => ...]",
            $table,
            $column,
        )];
    }

    /**
     * @return list<string>
     */
    private function problemsWithCitation(string $table, string $column, mixed $class, mixed $method): array
    {
        if (! is_string($class) || ! class_exists($class)) {
            return [sprintf('%s.%s: cited class %s does not exist', $table, $column, (string) $class)];
        }

        if (! is_subclass_of($class, TestCase::class)) {
            return [sprintf('%s.%s: cited class %s is not a test case', $table, $column, $class)];
        }

        $reflection = new ReflectionClass($class);

        // An abstract class satisfies `is_subclass_of` and can declare a public
        // `test_`-prefixed method that passes every check below, while PHPUnit
        // can neither instantiate nor run it.
        if ($reflection->isAbstract()) {
            return [sprintf('%s.%s: cited class %s is abstract, so PHPUnit never runs it', $table, $column, $class)];
        }

        // Concreteness is not discovery. Composer autoloads every `Tests\`
        // class, but PHPUnit only collects files under the directories named in
        // phpunit.xml whose names end in the configured suffix - so a perfectly
        // concrete test class in `tests/Support/Helpers.php` satisfies every
        // reflection check above and never runs. The suites are read from
        // phpunit.xml rather than hardcoded, so this cannot drift from the
        // configuration it is asserting about.
        $file = $reflection->getFileName();

        if ($file === false || ! $this->isDiscoverableByPhpunit($file)) {
            return [sprintf(
                '%s.%s: cited class %s is not in a file PHPUnit collects (see the testsuite directories and suffix in phpunit.xml)',
                $table,
                $column,
                $class,
            )];
        }

        if (! is_string($method) || ! $reflection->hasMethod($method)) {
            return [sprintf('%s.%s: cited method %s::%s does not exist', $table, $column, $class, (string) $method)];
        }

        $declaring = $reflection->getMethod($method);

        // The *declared* name, not the citation's spelling of it. Reflection
        // resolves method names case-insensitively, so `hasMethod('test_x')`
        // happily finds `Test_x` - while PHPUnit decides what is a test from
        // the real, case-sensitive name and would not run it. Checking the
        // citation string would accept a method PHPUnit ignores.
        if (! str_starts_with($declaring->getName(), 'test_')) {
            return [sprintf(
                '%s.%s: cited method resolves to %s::%s, which PHPUnit does not treat as a test',
                $table,
                $column,
                $class,
                $declaring->getName(),
            )];
        }

        // Declared on the cited class, not inherited: a citation that resolves
        // to a base-class method names a test the cited class does not run.
        if (! $declaring->isPublic() || $declaring->getDeclaringClass()->getName() !== $class) {
            return [sprintf('%s.%s: cited method %s::%s is not a public test declared on that class', $table, $column, $class, $method)];
        }

        return [];
    }

    /**
     * Would PHPUnit actually collect this file?
     *
     * Reads the testsuite directories and file suffix straight out of
     * phpunit.xml, so the answer tracks the configuration instead of a copy of
     * it. A citation naming a class PHPUnit never collects is a citation of a
     * test that never runs.
     */
    private function isDiscoverableByPhpunit(string $file): bool
    {
        $config = simplexml_load_file(base_path('phpunit.xml'));

        if ($config === false) {
            return false;
        }

        $real = realpath($file);

        if ($real === false) {
            return false;
        }

        foreach ($config->xpath('//testsuites/testsuite') ?: [] as $suite) {
            // Per suite, not globally. PHPUnit scopes `<exclude>` to the suite
            // that declares it, so a file excluded from one suite and included
            // by another is still collected. Rejecting on the first exclusion
            // anywhere fails *closed* - it would refuse a citation that does in
            // fact run - which is the safe direction to be wrong in but wrong
            // all the same.
            $excluded = false;

            foreach ($suite->exclude ?? [] as $path) {
                $root = realpath(base_path((string) $path));

                if ($root !== false && ($real === $root || str_starts_with($real, $root.DIRECTORY_SEPARATOR))) {
                    $excluded = true;

                    break;
                }
            }

            if ($excluded) {
                continue;
            }

            foreach ($suite->directory ?? [] as $directory) {
                // `prefix` and `suffix` both filter the *file name*, and
                // ignoring the prefix fails open: a PSR-4-loadable `FooTest.php`
                // would be accepted under `prefix="Integration"` even though
                // PHPUnit never collects it.
                $suffix = (string) ($directory['suffix'] ?? '') ?: 'Test.php';
                $prefix = (string) ($directory['prefix'] ?? '');
                $root = realpath(base_path((string) $directory));

                if ($root === false || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
                    continue;
                }

                $name = basename($real);

                if (str_ends_with($name, $suffix) && ($prefix === '' || str_starts_with($name, $prefix))) {
                    return true;
                }
            }

            foreach ($suite->file ?? [] as $named) {
                if (realpath(base_path((string) $named)) === $real) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function problemsWithReader(string $table, string $column, mixed $class, mixed $method): array
    {
        if (! is_string($class) || ! class_exists($class)) {
            return [sprintf('%s.%s: named reader class %s does not exist', $table, $column, (string) $class)];
        }

        if (! is_string($method) || ! (new ReflectionClass($class))->hasMethod($method)) {
            return [sprintf('%s.%s: named reader %s::%s does not exist', $table, $column, $class, (string) $method)];
        }

        return [];
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
