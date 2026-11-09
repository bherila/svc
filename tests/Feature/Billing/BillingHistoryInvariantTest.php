<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientProject;
use App\Models\ClientTimeEntry;
use App\Models\ServicePackage;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Approval\DisputedDraftRebuilder;
use App\Services\Approval\DisputeSettlements;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\DeferredBacklogReader;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Engagement\AgreementWorkflow;
use App\Services\Packages\PackageAgreementTerms;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageMigrationPlanner;
use App\Services\Packages\PackageMigrationRecords;
use App\Services\Packages\PackageMigrationWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
use App\Support\Packages\Migration\ContinuedRecurrence;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Invariants of a billing history, checked across scripted histories rather
 * than case by case (docs/ux-overhaul.md 7b″).
 *
 * The reviews of #655 found the same few shapes again and again: a draft
 * priced from state that later moved, and issued anyway; a rebuild that missed
 * a draft depending on the row; a bulk walk pricing a period from a ledger
 * computed before the last one was written; an agreement collecting work that
 * belongs to its successor; a final reconciliation leaving its own deferred
 * work waiting. Each is a property of the whole history, so each is asserted
 * as one:
 *
 * - **Stale is refused.** Once every hour of the horizon is logged, a draft
 *   that regenerating would change must not be issued as it is. Regeneration
 *   prices from the current state; a draft that differs was priced from
 *   something that has since moved, and the issue has to notice.
 * - **Charged once.** No hour is allocated by two live invoices.
 * - **Own segment.** No invoice of an agreement allocates work dated after its
 *   segment ends - its termination, or the day before a successor starts -
 *   or sells a retainer cycle or recurring-item incidence dated after it.
 * - **Nothing left behind.** Once an ended agreement's invoices are issued,
 *   none of its own deferred work still waits.
 * - **Bulk is sequential.** Generating every period at once prices each exactly
 *   as generating them one at a time does.
 */
final class BillingHistoryInvariantTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * The re-review's history: historical work disputed, answered, disputed
     * again and written off, with an issue and a void between, the stale-draft
     * oracle asked after every step.
     */
    public function test_a_monthly_history_of_disputes_settlements_and_voids_keeps_its_invariants(): void
    {
        [$workspace, $company, $project, $owner] = $this->world('monthly');
        $agreement = $this->agreement($workspace, $company, ['retainer_minutes' => 600]);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 1200);
        $b = $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1800);
        $this->hours($workspace, $company, $project, $owner, '2026-03-05', 300);
        $february = $this->period($company, $agreement, '2026-02-01');
        $this->period($company, $agreement, '2026-03-01');
        $this->period($company, $agreement, '2026-04-01');
        $this->assertInvariants($company, 'generated');

        $this->dispute($workspace, $a);
        $this->assertInvariants($company, 'A disputed and rebuilt', rebuilt: true);
        $this->settle($workspace, $a, $owner, DisputeSettlement::Answered);
        $this->assertInvariants($company, 'A answered', rebuilt: true);
        $this->dispute($workspace, $a);
        $this->assertInvariants($company, 'A disputed again', rebuilt: true);

        app(InvoiceLifecycleService::class)->issue($february->fresh() ?? $february, $workspace);
        $this->assertInvariants($company, 'February issued');
        $this->dispute($workspace, $b);
        $this->assertInvariants($company, 'B disputed', rebuilt: true);
        $this->settle($workspace, $b, $owner, DisputeSettlement::WrittenOff);
        $this->assertInvariants($company, 'B written off', rebuilt: true);

        app(InvoiceLifecycleService::class)->void($february->fresh() ?? $february, $workspace, 'Synthetic void');
        $this->assertInvariants($company, 'February voided, no rebuild');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');
    }

    /**
     * The third review's void: an hour charged as a retainer draw, with no
     * overage, disputed after its charge - so it stays charged - and then
     * released by voiding the invoice. Held now, it leaves January's capacity
     * to roll into February, which March was priced without.
     */
    public function test_voiding_a_zero_overage_charge_of_a_disputed_hour_leaves_no_issuable_stale_draft(): void
    {
        [$workspace, $company, $project, $owner] = $this->world('void');
        $agreement = $this->agreement($workspace, $company, ['retainer_minutes' => 600, 'rollover_months' => 1]);
        $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 600);
        $this->hours($workspace, $company, $project, $owner, '2026-02-10', 1800);
        $february = $this->period($company, $agreement, '2026-02-01');
        app(InvoiceLifecycleService::class)->issue($february, $workspace);
        $this->period($company, $agreement, '2026-03-01');
        $this->assertInvariants($company, 'March generated');

        $this->dispute($workspace, $a);
        $this->assertInvariants($company, 'A disputed after its charge', rebuilt: true);
        app(InvoiceLifecycleService::class)->void($february->fresh() ?? $february, $workspace, 'Synthetic void');
        $this->assertInvariants($company, 'February voided, no rebuild');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');
    }

    /** A dispute answered before anything is issued leaves every draft as it was. */
    public function test_a_dispute_answered_before_any_issue_changes_nothing(): void
    {
        $totals = [];
        foreach (['untouched', 'disputed'] as $variant) {
            [$workspace, $company, $project, $owner] = $this->world('noop-'.$variant);
            $agreement = $this->agreement($workspace, $company, ['retainer_minutes' => 600, 'rollover_months' => 2]);
            $a = $this->hours($workspace, $company, $project, $owner, '2026-01-20', 1500);
            $this->hours($workspace, $company, $project, $owner, '2026-02-10', 300);
            $this->hours($workspace, $company, $project, $owner, '2026-03-10', 900);
            foreach (['2026-02-01', '2026-03-01', '2026-04-01'] as $period) {
                $this->period($company, $agreement, $period);
            }
            if ($variant === 'disputed') {
                $this->dispute($workspace, $a);
                $this->settle($workspace, $a, $owner, DisputeSettlement::Answered);
            }
            $this->assertInvariants($company, $variant);
            $totals[$variant] = $this->draftTotals($company);
        }

        $this->assertSame($totals['untouched'], $totals['disputed']);
    }

    /**
     * An agreement ending mid-quarter and succeeded the next day, each with
     * deferred work, generated in bulk and issued.
     */
    public function test_an_agreement_succeeded_mid_quarter_bills_its_own_segment_and_leaves_nothing_behind(): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        [$workspace, $company, $project, $owner] = $this->world('successor');
        $outgoing = $this->agreement($workspace, $company, [
            'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'ends_on' => '2026-05-15', 'status' => 'terminated',
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'period_retainer_minutes' => 0, 'period_retainer_amount' => 0,
        ]);
        $successor = $this->agreement($workspace, $company, [
            'starts_on' => '2026-05-16', 'billing_cadence' => 'quarterly', 'bill_overage_interim' => false,
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 5000,
        ]);
        foreach (['2026-02-10', '2026-05-01', '2026-06-10', '2026-08-03'] as $day) {
            ClientTimeEntry::query()->whereKey($this->hours($workspace, $company, $project, $owner, $day, 120)->id)->update(['is_deferred' => true]);
        }
        $this->hours($workspace, $company, $project, $owner, '2026-03-03', 60);
        // Ordinary work on both sides of the handover, at different rates.
        $before = $this->hours($workspace, $company, $project, $owner, '2026-05-12', 60);
        $after = $this->hours($workspace, $company, $project, $owner, '2026-06-17', 60);

        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertInvariants($company, 'generated in bulk');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');

        $this->assertSame([[$outgoing->id, 10000]], $this->chargesOf($before), 'Ordinary work before the handover is the outgoing agreement\'s, at its rate.');
        $this->assertSame([[$successor->id, 5000]], $this->chargesOf($after), 'Ordinary work after it is the successor\'s, at its rate.');

        $segmentEnd = app(DeferredBacklogReader::class)->segmentEnd($workspace, $outgoing, CarbonImmutable::parse('2026-12-31'));
        // With no retainer there is no free capacity: the outgoing agreement's
        // deferred work is collected at its hourly rate, on its own invoices.
        $collected = DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('invoice.workspace_id', $workspace->id)->where('entry.is_deferred', true)
            ->whereDate('entry.worked_on', '<=', $segmentEnd->toDateString())
            ->get(['invoice.client_agreement_id', 'line.unit_amount']);
        $this->assertCount(2, $collected, 'Both of its deferred entries are collected.');
        foreach ($collected as $line) {
            $this->assertSame($outgoing->id, (int) $line->client_agreement_id);
            $this->assertSame(10000, (int) $line->unit_amount, 'Collected at the hourly rate, not for nothing.');
        }
        $this->assertSame(0, ClientTimeEntry::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->whereDate('worked_on', '<=', $segmentEnd->toDateString())->awaitingDeferredBilling()->count(), 'The outgoing agreement left its own deferred work waiting.');
    }

    private const array ORDINARY_HISTORY = [
        ['2026-01-12', 300, false], ['2026-02-09', 1500, false], ['2026-03-02', 240, true], ['2026-03-16', 200, false],
        ['2026-04-20', 900, false], ['2026-05-11', 100, false], ['2026-07-07', 1300, false], ['2026-08-18', 60, false],
    ];

    /** Deferred work drawn on Q1's unused rollover pool, then an overage the pool must not cover twice. */
    private const array ROLLOVER_DRAW_HISTORY = [['2026-01-20', 1800, true], ['2026-06-10', 3600, false]];

    /** @return iterable<string, array{string, string, list<string>, string, string}> cadence, successor start, gap days, a day after, now */
    public static function gapCadences(): iterable
    {
        yield 'monthly, a month' => ['monthly', '2026-06-16', ['2026-06-10'], '2026-06-20', '2026-10-20'];
        yield 'quarterly, a month' => ['quarterly', '2026-06-16', ['2026-06-10'], '2026-06-20', '2026-10-20'];
        yield 'monthly, several cycles' => ['monthly', '2026-11-16', ['2026-07-10', '2026-09-10', '2026-11-12'], '2026-11-20', '2027-01-20'];
        yield 'quarterly, several cycles' => ['quarterly', '2026-11-16', ['2026-07-10', '2026-09-10', '2026-11-12'], '2026-11-20', '2027-01-20'];
    }

    /**
     * Codex's gaps: the outgoing agreement ends on May 15 and its successor
     * starts later - the next month, or after several cycles. Ordinary work up
     * to the day before the successor starts is the outgoing agreement's, gap
     * included, at its rate, and the successor's from its start, at its own -
     * each billed exactly once, by a bulk walk that reaches the whole gap.
     *
     * @param  list<string>  $gapDays
     */
    #[DataProvider('gapCadences')]
    public function test_work_in_a_gap_before_a_successor_is_billed_once_by_the_outgoing_agreement(string $cadence, string $successorStart, array $gapDays, string $afterDay, string $now): void
    {
        Carbon::setTestNow($now.' 12:00:00');
        CarbonImmutable::setTestNow($now.' 12:00:00');
        [$workspace, $company, $project, $owner] = $this->world('gap-'.$cadence.'-'.$successorStart);
        $outgoing = $this->agreement($workspace, $company, [
            'billing_cadence' => $cadence, 'bill_overage_interim' => false, 'ends_on' => '2026-05-15', 'status' => 'terminated',
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 20000,
        ]);
        $successor = $this->agreement($workspace, $company, [
            'starts_on' => $successorStart, 'billing_cadence' => $cadence, 'bill_overage_interim' => false,
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 10000,
        ]);
        $may = $this->hours($workspace, $company, $project, $owner, '2026-05-12', 60);
        $gap = array_map(fn (string $day): ClientTimeEntry => $this->hours($workspace, $company, $project, $owner, $day, 60), $gapDays);
        $after = $this->hours($workspace, $company, $project, $owner, $afterDay, 60);

        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertInvariants($company, 'generated in bulk');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');

        $this->assertSame([[$outgoing->id, 20000]], $this->chargesOf($may));
        foreach ($gap as $entry) {
            $this->assertSame([[$outgoing->id, 20000]], $this->chargesOf($entry), 'Work in the gap, on '.$entry->worked_on->toDateString().', is the outgoing agreement\'s, once, at its rate.');
        }
        $this->assertSame([[$successor->id, 10000]], $this->chargesOf($after), 'Work from the successor\'s start is the successor\'s.');
    }

    /**
     * Codex's late predecessor: the successor's draft is generated while it
     * stands alone, charging June 10 at its $100; then the agreement it took
     * over from is recorded, January 1 to May 15 at $200. The unchanged draft
     * is refused; regenerated, it leaves June 10 to the predecessor, whose walk
     * reaches the gap and bills it once at its own rate.
     */
    public function test_a_predecessor_added_after_the_successors_draft_takes_back_its_work(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        [$workspace, $company, $project, $owner] = $this->world('late-predecessor');
        $successor = $this->agreement($workspace, $company, [
            'starts_on' => '2026-06-16', 'billing_cadence' => 'monthly', 'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 10000,
        ]);
        $june = $this->hours($workspace, $company, $project, $owner, '2026-06-10', 120);
        $after = $this->hours($workspace, $company, $project, $owner, '2026-06-20', 60);
        $this->period($company, $successor, '2026-07-01');
        $this->assertSame([[$successor->id, 10000]], $this->chargesOf($june), 'Alone, the successor\'s draft charges June 10.');

        $predecessor = $this->agreement($workspace, $company, [
            'billing_cadence' => 'monthly', 'ends_on' => '2026-05-15', 'status' => 'terminated',
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 20000,
        ]);
        $this->assertInvariants($company, 'the predecessor recorded, no rebuild');
        $this->issueEveryDraft($company, $workspace);
        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertInvariants($company, 'the predecessor generated');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');

        $this->assertSame([[$predecessor->id, 20000]], $this->chargesOf($june), 'June 10 is the predecessor\'s, once, at its rate.');
        $this->assertSame([[$successor->id, 10000]], $this->chargesOf($after));
    }

    /**
     * The post-merge review's history: the outgoing draft is generated while
     * the agreement is open, then the agreement is terminated backdated and a
     * successor starts the next day at another rate. The unchanged draft is
     * refused; regenerated and issued, it leaves June to the successor, which
     * bills it once at its own rate.
     */
    public function test_a_backdated_handover_after_the_outgoing_draft_leaves_the_successors_work_to_it(): void
    {
        Carbon::setTestNow('2026-07-10 12:00:00');
        CarbonImmutable::setTestNow('2026-07-10 12:00:00');
        [$workspace, $company, $project, $owner] = $this->world('backdated');
        $outgoing = $this->agreement($workspace, $company, [
            'billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 20000,
        ]);
        $june = $this->hours($workspace, $company, $project, $owner, '2026-06-10', 120);
        $this->period($company, $outgoing, '2026-07-01');
        $this->assertInvariants($company, 'the outgoing draft generated');

        app(AgreementWorkflow::class)->terminate($outgoing->fresh() ?? $outgoing, '2026-05-15');
        $successor = $this->agreement($workspace, $company, [
            'starts_on' => '2026-05-16', 'billing_cadence' => 'quarterly', 'bill_overage_interim' => false,
            'retainer_minutes' => 0, 'retainer_amount' => 0, 'hourly_rate_amount' => 10000,
        ]);
        $this->assertInvariants($company, 'the backdated handover, no rebuild');
        $this->issueEveryDraft($company, $workspace);

        Carbon::setTestNow('2026-08-20 12:00:00');
        CarbonImmutable::setTestNow('2026-08-20 12:00:00');
        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertInvariants($company, 'the successor generated');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');
        $this->assertSame([[$successor->id, 10000]], $this->chargesOf($june), 'June is the successor\'s, once, at its rate.');
    }

    /** @return iterable<string, array{array<string, mixed>, list<array{string, int, bool}>}> */
    public static function bulkWorlds(): iterable
    {
        yield 'monthly with rollover' => [['retainer_minutes' => 600, 'rollover_months' => 2], self::ORDINARY_HISTORY];
        yield 'monthly with a catch-up threshold' => [['retainer_minutes' => 600, 'catch_up_threshold_minutes' => 300], self::ORDINARY_HISTORY];
        yield 'quarterly with rollover' => [['billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'rollover_months' => 3], self::ORDINARY_HISTORY];
        yield 'quarterly, interim overage' => [['billing_cadence' => 'quarterly', 'bill_overage_interim' => true, 'retainer_minutes' => 600], self::ORDINARY_HISTORY];
        yield 'quarterly, deferred work on a rollover pool' => [['billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'rollover_months' => 6], self::ROLLOVER_DRAW_HISTORY];
    }

    /**
     * Bulk first, then the same periods generated one at a time, in order, in
     * a twin world: every cadence period is priced the same.
     *
     * @param  array<string, mixed>  $terms
     * @param  list<array{string, int, bool}>  $history  day, minutes, deferred
     */
    #[DataProvider('bulkWorlds')]
    public function test_bulk_generation_prices_every_period_as_sequential_generation_does(array $terms, array $history): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        $totals = [];
        $periods = [];
        foreach (['bulk', 'sequential'] as $mode) {
            [$workspace, $company, $project, $owner] = $this->world('bulk-'.$mode);
            $agreement = $this->agreement($workspace, $company, $terms);
            foreach ($history as [$day, $minutes, $deferred]) {
                $entry = $this->hours($workspace, $company, $project, $owner, $day, $minutes);
                if ($deferred) {
                    ClientTimeEntry::query()->whereKey($entry->id)->update(['is_deferred' => true]);
                }
            }
            if ($mode === 'bulk') {
                app(ClientInvoicingService::class)->generateAllInvoices($company);
                $periods = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('invoice_kind', 'cadence_period')
                    ->orderBy('service_period_start')->pluck('service_period_start')->map(fn ($start): string => Carbon::parse($start)->toDateString())->all();
            } else {
                $months = $agreement->billing_cadence === 'quarterly' ? 3 : 1;
                foreach ($periods as $workStart) {
                    app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement->fresh() ?? $agreement, Carbon::parse($workStart)->addMonthsNoOverflow($months));
                }
            }
            $this->assertInvariants($company, $mode);
            $totals[$mode] = $this->draftTotals($company, 'cadence_period');
        }

        $this->assertGreaterThanOrEqual(2, count($totals['bulk']));
        $this->assertSame($totals['sequential'], $totals['bulk']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function concurrentProjectWorlds(): iterable
    {
        yield 'monthly' => [['retainer_minutes' => 600, 'rollover_months' => 1]];
        yield 'quarterly' => [['billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'rollover_months' => 3]];
    }

    /**
     * Two agreements of one company on two projects, billing the same dates
     * (docs/service-packages.md, slice 4: the overlap guard compares invoices
     * whose work can overlap). Each prices exactly as it would alone: the
     * other's work, invoices and ledger change nothing about it, and no hour
     * is charged twice or by the other agreement.
     *
     * @param  array<string, mixed>  $terms
     */
    #[DataProvider('concurrentProjectWorlds')]
    public function test_two_project_agreements_of_one_company_bill_the_same_periods_as_each_would_alone(array $terms): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        $history = [
            'first' => [['2026-01-12', 300, false], ['2026-02-09', 1500, false], ['2026-03-02', 240, true], ['2026-05-11', 100, false]],
            'second' => [['2026-01-20', 900, false], ['2026-04-20', 200, false], ['2026-07-07', 1300, false]],
        ];
        $totals = [];
        foreach (['together' => ['first', 'second'], 'first alone' => ['first'], 'second alone' => ['second']] as $world => $names) {
            [$workspace, $company, , $owner] = $this->world('projects-'.str_replace(' ', '-', $world));
            $agreements = [];
            foreach ($names as $name) {
                $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic '.$name.' project']);
                $agreements[$name] = [$this->agreement($workspace, $company, $terms + ['client_project_id' => $project->id]), $project];
                foreach ($history[$name] as [$day, $minutes, $deferred]) {
                    $entry = $this->hours($workspace, $company, $project, $owner, $day, $minutes);
                    if ($deferred) {
                        ClientTimeEntry::query()->whereKey($entry->id)->update(['is_deferred' => true]);
                    }
                }
            }

            app(ClientInvoicingService::class)->generateAllInvoices($company);
            $this->assertInvariants($company, $world.', generated in bulk');
            $this->issueEveryDraft($company, $workspace);
            $this->assertInvariants($company, $world.', everything issued');

            foreach ($agreements as $name => [$agreement, $project]) {
                $foreign = DB::table('client_invoice_line_time_entries as allocation')
                    ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
                    ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
                    ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
                    ->where('invoice.workspace_id', $workspace->id)->where('invoice.client_agreement_id', $agreement->id)
                    ->where('entry.client_project_id', '!=', $project->id)->count();
                $this->assertSame(0, $foreign, "{$world}: the {$name} agreement billed another project's work.");
                $totals[$world][$name] = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)
                    ->where('invoice_kind', 'cadence_period')->orderBy('cycle_start')->get()
                    ->mapWithKeys(fn (ClientInvoice $invoice): array => [$invoice->cycle_start?->toDateString() => [$invoice->status, (int) $invoice->total_amount]])->all();
            }
        }

        $this->assertGreaterThanOrEqual(2, count($totals['together']['first']));
        $this->assertSame($totals['first alone']['first'], $totals['together']['first']);
        $this->assertSame($totals['second alone']['second'], $totals['together']['second']);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function cancelledWorlds(): iterable
    {
        // From Oct 10 with no notice, the earliest end is the close of the period holding it.
        yield 'monthly' => [['retainer_minutes' => 600, 'rollover_months' => 1], '2026-10-31'];
        yield 'quarterly' => [['billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'rollover_months' => 3], '2026-12-31'];
    }

    /**
     * A cancellation is a scheduled end and nothing else (docs/service-packages.md,
     * CAN-5, EDGE-9). Cancelled at a period close and terminated on that date by
     * the scheduled run, an agreement bills exactly as one ended there by hand,
     * under the existing termination rules: its last period whole, its deferred
     * work collected. Undone, it bills exactly as one never cancelled.
     *
     * @param  array<string, mixed>  $terms
     */
    #[DataProvider('cancelledWorlds')]
    public function test_a_cancellation_bills_as_the_end_it_schedules_and_an_undone_one_as_none(array $terms, string $end): void
    {
        config(['svc.service_packages.enabled' => true]);
        // Work up to the end; what an ended agreement does with later work is the engine's existing rule, not this.
        $history = array_filter([['2026-02-09', 1500, false], ['2026-03-02', 240, true], ['2026-07-14', 900, false], ['2026-10-20', 1300, false], ['2026-11-20', 300, false]],
            static fn (array $entry): bool => $entry[0] <= $end);
        $totals = [];
        foreach (['cancelled', 'ended by hand', 'undone', 'never cancelled'] as $world) {
            Carbon::setTestNow('2026-10-10 12:00:00');
            CarbonImmutable::setTestNow('2026-10-10 12:00:00');
            [$workspace, $company, $project, $owner] = $this->world('cancellation-'.str_replace(' ', '-', $world));
            $agreement = $this->agreement($workspace, $company, $terms + ['cancellation_mode' => 'self_serve', 'notice_days' => 0]);
            foreach ($history as [$day, $minutes, $deferred]) {
                $entry = $this->hours($workspace, $company, $project, $owner, $day, $minutes);
                if ($deferred) {
                    ClientTimeEntry::query()->whereKey($entry->id)->update(['is_deferred' => true]);
                }
            }
            $cancellations = app(AgreementCancellations::class);
            if ($world === 'cancelled' || $world === 'undone') {
                $cancellations->schedule($owner, $workspace, $agreement, $end, false);
            }
            if ($world === 'undone') {
                $cancellations->undo($owner, $workspace, $agreement);
            }

            Carbon::setTestNow($end.' 06:00:00');
            CarbonImmutable::setTestNow($end.' 06:00:00');
            if ($world === 'cancelled') {
                $this->artisan('svc:agreements:terminate-scheduled', ['--apply' => true])->assertSuccessful();
            }
            if ($world === 'ended by hand') {
                app(AgreementWorkflow::class)->terminate($agreement, $end);
            }

            Carbon::setTestNow('2027-01-20 12:00:00');
            CarbonImmutable::setTestNow('2027-01-20 12:00:00');
            app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
            $this->assertInvariants($company, $world.', generated');
            $this->issueEveryDraft($company, $workspace);
            $this->assertInvariants($company, $world.', everything issued');
            $totals[$world] = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('client_agreement_id', $agreement->id)
                ->orderBy('cycle_start')->orderBy('id')->get()
                ->map(fn (ClientInvoice $invoice): array => [$invoice->invoice_kind, $invoice->cycle_start?->toDateString(), $invoice->cycle_end?->toDateString(), $invoice->status, (int) $invoice->total_amount])->all();
        }

        $this->assertSame('terminated', ClientAgreement::query()->where('title', 'Synthetic agreement')->orderBy('id')->firstOrFail()->status);
        $this->assertSame($totals['ended by hand'], $totals['cancelled']);
        $this->assertSame($totals['never cancelled'], $totals['undone']);
        // The last period sold is whole: one closes on the end. What follows it
        // is the engine's existing settlement of an ended agreement, the same
        // whether the end was cancelled or set by hand.
        $this->assertContains($end, array_column($totals['cancelled'], 2));
        $this->assertNotSame($totals['never cancelled'], $totals['cancelled']);
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, int}> */
    public static function movedWorlds(): iterable
    {
        // Version 1, version 2, and how many periods are sold from January
        // through the walk on October 1 (which reaches one period ahead).
        yield 'monthly' => [
            ['billing_cadence' => 'monthly', 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'monthly', 'amount' => 4500]]],
            ['billing_cadence' => 'monthly', 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'monthly', 'amount' => 5000]]],
            11,
        ];
        yield 'quarterly' => [
            ['billing_cadence' => 'quarterly', 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'quarterly', 'amount' => 13500]]],
            ['billing_cadence' => 'quarterly', 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'quarterly', 'amount' => 15000]]],
            5,
        ];
        // The new version bills a setup fee with a new sign-up's first cycle;
        // a subscriber who moves set up long ago and is never charged it.
        yield 'monthly, to a version with a setup fee billed with the first cycle' => [
            ['billing_cadence' => 'monthly', 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'monthly', 'amount' => 4500]]],
            ['billing_cadence' => 'monthly', 'charge_setup_fee_at_signup' => false, 'components' => [
                ['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => 'monthly', 'amount' => 5000],
                ['kind' => 'setup_fee', 'description' => 'Synthetic setup', 'amount' => 20000],
            ]],
            11,
        ];
    }

    /**
     * A package agreement moved to a new version mid-life (docs/service-packages.md,
     * MIG-3), invoiced and issued period by period as the year goes: every
     * period before the effective date bills on the old terms and every period
     * from it on the new, with no period sold twice or skipped. The draft the
     * walk had already made for the first new period is discarded, never
     * issued on the old terms, and the issued invoices before the date are
     * never touched.
     *
     * Three twins: moved by the scheduled move, renewed by hand on the same
     * date (the old agreement ended the day before with its recurring items, a
     * new one from the new version's terms started on it), and never moved.
     * The move bills exactly as the hand-made renewal, and every period before
     * the date exactly as the agreement never moved.
     *
     * Fixed-price terms on one cadence: a move refuses an agreement that bills
     * by usage (MIG-1), whose last period's work the engine settles on the
     * invoice that also opens the next, and one that would change its cadence
     * (a successor's first period of another length claims work periods the
     * old agreement already sold). A setup fee on the new version is a new
     * sign-up's, never charged to a subscriber who moves.
     *
     * @param  array<string, mixed>  $v1
     * @param  array<string, mixed>  $v2
     */
    #[DataProvider('movedWorlds')]
    #[Requirement('MIG-3')]
    public function test_a_mid_life_move_to_a_new_version_bills_each_period_once_on_the_terms_in_force(array $v1, array $v2, int $periods): void
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $effective = '2026-07-01';
        $invoices = [];
        $issuedBefore = [];
        foreach (['moved', 'renewed by hand', 'never moved'] as $world) {
            $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
            [$workspace, $owner] = $this->packageWorkspace('Synthetic invariants move '.$world);
            $workspace->forceFill(['timezone' => 'UTC'])->save();
            $packages = app(ServicePackageWorkflow::class);
            $package = $packages->publish($owner, $workspace, $packages->create($owner, $workspace, $this->hostingPackageInput([
                'link_name' => 'synthetic-care', 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0, ...$v1,
            ])));
            $from = ServicePackageVersion::query()->findOrFail($package->published_version_id);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic moved client', 'slug' => 'synthetic-moved-'.Str::slug($world)]);
            $contact = User::factory()->create();
            ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
            $enrollments = app(PackageEnrollmentWorkflow::class);
            $enrollment = $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Synthetic Signer'));
            $project = ClientProject::query()->findOrFail($enrollment->client_project_id);
            $original = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
            $packages->update($owner, $workspace, $package->refresh(), $v2);
            $package = $packages->publish($owner, $workspace, $package->refresh());

            for ($month = CarbonImmutable::parse('2026-01-01'); $month->lte(CarbonImmutable::parse('2026-10-01')); $month = $month->addMonth()) {
                $this->travelTo($month->setTime(6, 0));
                if ($month->toDateString() === $effective && $world === 'moved') {
                    $issuedBefore = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', '!=', InvoiceStatus::Draft->value)
                        ->orderBy('id')->get()->map(fn (ClientInvoice $invoice): array => [$invoice->id, $invoice->status, (int) $invoice->total_amount, $invoice->lock_version])->all();
                    $this->assertSame(0, Artisan::call('svc:packages:apply-migrations', ['--apply' => true]));
                    $this->assertSame('applied', ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->sole()->status);
                    $this->assertSame($issuedBefore, ClientInvoice::query()->whereIn('id', array_column($issuedBefore, 0))->orderBy('id')->get()
                        ->map(fn (ClientInvoice $invoice): array => [$invoice->id, $invoice->status, (int) $invoice->total_amount, $invoice->lock_version])->all(), 'No issued invoice is touched.');
                }
                if ($month->toDateString() === $effective && $world === 'renewed by hand') {
                    $this->renewByHand($workspace, $company, $project, $original, $package, $effective);
                }
                app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
                $this->issueEveryDraft($company, $workspace);
                $this->assertInvariants($company, $world.', '.$month->toDateString());
                if ($month->toDateString() === '2026-06-01' && $world === 'moved') {
                    $this->travelTo(CarbonImmutable::parse('2026-06-10 12:00:00', 'UTC'));
                    $preview = app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package, $from->public_id, null);
                    $this->assertSame($effective, $preview['effective_on'], 'June is invoiced; July is the first period a move may take.');
                    app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, $effective, [$enrollment->public_id], null);
                }
            }

            $invoices[$world] = ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', '!=', InvoiceStatus::Void->value)
                ->orderBy('cycle_start')->orderBy('id')->get()
                ->map(fn (ClientInvoice $invoice): array => [$invoice->invoice_kind, $invoice->cycle_start?->toDateString(), $invoice->cycle_end?->toDateString(), $invoice->status, (int) $invoice->total_amount])->all();

            // Each period's service sold once, on the terms in force for it.
            $sold = ClientInvoiceLine::query()->join('client_invoices as invoice', 'invoice.id', '=', 'client_invoice_lines.client_invoice_id')
                ->where('invoice.workspace_id', $workspace->id)->where('invoice.status', '!=', InvoiceStatus::Void->value)
                ->where('client_invoice_lines.type', 'recurring_item')
                ->orderBy('client_invoice_lines.line_date')->get(['client_invoice_lines.line_date', 'client_invoice_lines.total_amount'])
                ->map(fn (ClientInvoiceLine $line): array => [$line->line_date?->toDateString(), (int) $line->total_amount])->all();
            $this->assertCount($periods, $sold, "{$world}: every period sold, none skipped.");
            $this->assertSame($periods, count(array_unique(array_column($sold, 0))), "{$world}: no period sold twice.");
            foreach ($sold as [$day, $amount]) {
                $terms = $world === 'never moved' || $day < $effective ? $v1 : $v2;
                $this->assertSame($terms['components'][0]['amount'], $amount, "{$world}: the period from {$day}.");
            }
        }

        $this->assertSame($invoices['renewed by hand'], $invoices['moved']);
        $before = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row[1] !== null && $row[1] < $effective));
        $this->assertSame($before($invoices['never moved']), $before($invoices['moved']), 'Every period before the move bills as if it had never been scheduled.');
        $this->assertNotSame($invoices['never moved'], $invoices['moved']);
        $this->assertNotSame([], $issuedBefore);
    }

    /** @return iterable<string, array{string, int, string, string, string, string|null, int}> */
    public static function heldMoveWorlds(): iterable
    {
        // Cadence, one cycle's price on version 1 (version 2 adds $5.00 a
        // month's worth), what the owner does about the held move and on
        // which day (before that day's runs), what the move's target comes
        // to, the first period on the new terms (null: none in the walk), and
        // how many periods are sold through mid-February 2027.
        foreach ([['monthly', 4500, 14, '2026-07-15', '2026-08-01', '2026-08-15', '2026-09-01'], ['quarterly', 13500, 5, '2026-08-15', '2026-10-01', '2026-10-15', '2027-01-01']] as [$cadence, $price, $periods, $inside, $boundary, $after, $rescheduled]) {
            yield "{$cadence}, declined inside the first new period" => [$cadence, $price, 'decline', $inside, 'applied', '2026-07-01', $periods];
            yield "{$cadence}, declined after it ended" => [$cadence, $price, 'decline', $after, 'effective_date_passed', null, $periods];
            yield "{$cadence}, the held subscriber cancelled after it ended" => [$cadence, $price, 'cancel beside a moved subscriber', $boundary, 'cancelled', null, $periods];
            yield "{$cadence}, the whole move cancelled after it ended" => [$cadence, $price, 'cancel', $boundary, 'cancelled', null, $periods];
            yield "{$cadence}, cancelled after it ended and scheduled again" => [$cadence, $price, 'cancel and reschedule', $boundary, 'cancelled', $rescheduled, $periods];
            yield "{$cadence}, cancelled, its late invoice refused, and replaced" => [$cadence, $price, 'cancel, refused and replaced', $boundary, 'cancelled', $rescheduled, $periods];
        }
    }

    /**
     * A move held on its date - the client asked to leave before it, and the
     * owner deals with it late - invoiced automatically twice a month as the
     * year goes (docs/service-packages.md, MIG-3, MIG-5, INV-1). Answered
     * while the first period on the new terms is still current, the move
     * applies and that period bills once, on the new terms. After it ended,
     * the move never starts an agreement in the past: it waits for an owner
     * (`effective_date_passed`), and the period that waited for it is billed
     * late on the current terms - whatever the owner then does, on the very
     * day the next period starts and before any run: cancel the held
     * subscriber beside one already moved, cancel the whole move, or cancel
     * it and schedule another. When that late invoice is refused (an ended
     * company-wide agreement covered the work dates) and a replacement move
     * comes due, the replacement waits (`owed_period_unbilled`) rather than
     * end the agreement that owes the period; once the refusal is resolved,
     * inside the replacement's first period, the period is invoiced and the
     * replacement applies. Monthly and quarterly follow one rule (Codex P1s
     * on #674: the move used to apply backdated, a cancelled move dropped the
     * period that had waited for it, and a replacement move ended the
     * agreement that still owed it). Every tick runs the hourly pair twice,
     * as two consecutive hours would.
     */
    #[DataProvider('heldMoveWorlds')]
    #[Requirement('MIG-3', 'MIG-5', 'INV-1')]
    public function test_a_move_held_on_its_date_never_leaves_a_period_unbilled(string $cadence, int $price, string $resolution, string $on, string $outcome, ?string $newFrom, int $periods): void
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $effective = '2026-07-01';
        $this->travelTo(CarbonImmutable::parse('2026-01-01 10:00:00', 'UTC'));
        [$workspace, $owner] = $this->packageWorkspace('Synthetic invariants held move');
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $hosting = static fn (int $amount): array => ['billing_cadence' => $cadence, 'components' => [['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => $cadence, 'amount' => $amount]]];
        $packages = app(ServicePackageWorkflow::class);
        $package = $packages->publish($owner, $workspace, $packages->create($owner, $workspace, $this->hostingPackageInput([
            'link_name' => 'synthetic-care', 'invoice_issuing' => 'automatic', 'activation_mode' => 'automatic', 'notice_days' => 0, 'cancellation_mode' => 'request', ...$hosting($price),
        ])));
        $from = ServicePackageVersion::query()->findOrFail($package->published_version_id);
        $enrollments = app(PackageEnrollmentWorkflow::class);
        $client = function (string $name) use ($workspace, $package, $enrollments): array {
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic '.$name, 'slug' => 'synthetic-'.Str::slug($name)]);
            $contact = User::factory()->create();
            ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);

            return [$company, $contact, $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Synthetic Signer'))];
        };
        [$company, $contact, $enrollment] = $client('held client');
        $beside = $resolution === 'cancel beside a moved subscriber' ? $client('moved client') : null;
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $newPrice = $price + ($cadence === 'monthly' ? 500 : 1500);
        $packages->update($owner, $workspace, $package->refresh(), $hosting($newPrice));
        $package = $packages->publish($owner, $workspace, $package->refresh());

        $request = null;
        $migration = null;
        $blocker = null;
        $months = $cadence === 'monthly' ? 1 : 3;
        $resolved = $newFrom === null ? null : CarbonImmutable::parse($newFrom)->addDays(14)->toDateString();
        for ($month = CarbonImmutable::parse('2026-01-01'); $month->lte(CarbonImmutable::parse('2027-02-01')); $month = $month->addMonth()) {
            foreach ([$month, $month->addDays(14)] as $day) {
                $this->travelTo($day->setTime(0, 30));
                if ($day->toDateString() === '2026-06-15') {
                    $migration = app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package->refresh(), $from->public_id, $effective,
                        array_values(array_filter([$enrollment->public_id, $beside[2]->public_id ?? null])), null);
                    $request = app(AgreementCancellations::class)->cancelByContact($contact, $company, $old->refresh(), '2026-06-30', null);
                }
                if ($day->toDateString() === $on) {
                    if ($resolution !== 'decline') {
                        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration?->refresh() ?? throw new \LogicException('No move.'));
                    }
                    app(AgreementCancellations::class)->decline($owner, $workspace, $request?->refresh() ?? throw new \LogicException('No request.'), null);
                    if ($resolution === 'cancel, refused and replaced') {
                        // A company-wide agreement that ended in the work
                        // period before the deferred one blocks its late invoice.
                        $blockedFrom = CarbonImmutable::parse($effective)->subMonths($months);
                        $blocker = ClientAgreement::query()->create([
                            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => null, 'title' => 'Synthetic retired retainer',
                            'status' => 'terminated', 'billing_cadence' => 'monthly', 'currency' => 'USD',
                            'starts_on' => $blockedFrom->toDateString(), 'ends_on' => $blockedFrom->addDays(14)->toDateString(),
                        ]);
                    }
                    if (in_array($resolution, ['cancel and reschedule', 'cancel, refused and replaced'], true)) {
                        $preview = app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package->refresh(), $from->public_id, null);
                        $this->assertSame($newFrom, $preview['effective_on']);
                        app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, (string) $newFrom, [$enrollment->public_id], null);
                    }
                }
                if ($blocker !== null && $day->toDateString() === $resolved) {
                    $blocker->delete();
                    $blocker = null;
                }
                foreach ([1, 2] as $hour) {
                    $this->assertSame(0, Artisan::call('svc:packages:apply-migrations', ['--apply' => true]), Artisan::output());
                    // Only the blocked late invoice is ever refused.
                    $this->assertSame($blocker === null ? 0 : 1, Artisan::call('svc:packages:issue-invoices', ['--apply' => true]), Artisan::output());
                    if ($blocker !== null && $newFrom !== null && $day->toDateString() >= $newFrom) {
                        $this->assertSame('owed_period_unbilled', ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->where('status', 'scheduled')->sole()->outcome_code);
                    }
                }
                $this->assertInvariants($company, $cadence.', '.$day->toDateString());
                if ($beside !== null) {
                    $this->assertInvariants($beside[0], $cadence.', the moved client, '.$day->toDateString());
                }
            }
        }

        $first = ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->where('service_package_enrollment_id', $enrollment->id)->orderBy('id')->firstOrFail();
        $this->assertSame($outcome, $resolution === 'decline' ? ($first->outcome_code ?? $first->status) : $first->status);
        if ($resolution !== 'decline') {
            $this->assertSame($beside !== null ? 'completed' : 'cancelled', $migration?->refresh()->status);
        }
        $this->assertSoldOnce($company, $periods, $price, $newPrice, $newFrom);
        if ($beside !== null) {
            $this->assertSoldOnce($beside[0], $periods, $price, $newPrice, $effective);
        }
        $this->assertSame(ClientInvoice::query()->where('workspace_id', $workspace->id)->count(), ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', InvoiceStatus::Issued->value)->count(), 'Each was issued.');
    }

    /** Each of the company's periods sold once, on the old price before `$newFrom` and the new price from it. */
    private function assertSoldOnce(ClientCompany $company, int $periods, int $price, int $newPrice, ?string $newFrom): void
    {
        $sold = ClientInvoiceLine::query()->join('client_invoices as invoice', 'invoice.id', '=', 'client_invoice_lines.client_invoice_id')
            ->where('invoice.client_company_id', $company->id)->where('invoice.status', '!=', InvoiceStatus::Void->value)
            ->where('client_invoice_lines.type', 'recurring_item')
            ->orderBy('client_invoice_lines.line_date')->get(['client_invoice_lines.line_date', 'client_invoice_lines.total_amount'])
            ->map(fn (ClientInvoiceLine $line): array => [$line->line_date?->toDateString(), (int) $line->total_amount])->all();
        $this->assertCount($periods, $sold, "{$company->name}: every period sold, none skipped.");
        $this->assertSame($periods, count(array_unique(array_column($sold, 0))), "{$company->name}: no period sold twice.");
        foreach ($sold as [$day, $amount]) {
            $this->assertSame($newFrom !== null && $day >= $newFrom ? $newPrice : $price, $amount, "{$company->name}: the period from {$day}.");
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function continuedScheduleWorlds(): iterable
    {
        foreach (['monthly' => 4500, 'quarterly' => 12000, 'semi_annual' => 24000, 'annual' => 48000] as $cadence => $amount) {
            foreach ([1, 9, 31] as $day) {
                foreach (['one component', 'two components', 'a setup fee with the first cycle'] as $components) {
                    yield "{$cadence}, started on day {$day}, {$components}" => [$cadence, $day, $components];
                }
            }
        }
    }

    /**
     * Property (docs/service-packages.md, MIG-3): for identical terms, a
     * service moved to a new version bills exactly as the same service never
     * moved - the same total for every period, and every period exactly
     * once - on every cadence, whatever day of the month it started, for each
     * of the first two dates a move may take (one full cycle apart), with one
     * or two recurring components on its cadence or a setup fee billed with
     * the first cycle. Version 2 differs from version 1 only in wording, so
     * nothing a client pays may change (Codex P1 on #674: a quarterly service
     * started Nov 9 and moved Feb 9 was charged Feb 9 and May 1 in its first
     * new quarter, where the never-moved twin is charged May 1 alone).
     */
    #[DataProvider('continuedScheduleWorlds')]
    #[Requirement('MIG-3')]
    public function test_a_move_to_identical_terms_bills_every_period_as_never_moving(string $cadence, int $day, string $components): void
    {
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $amounts = ['monthly' => 4500, 'quarterly' => 12000, 'semi_annual' => 24000, 'annual' => 48000];
        $months = ['monthly' => 1, 'quarterly' => 3, 'semi_annual' => 6, 'annual' => 12][$cadence];
        $terms = [
            'billing_cadence' => $cadence, 'invoice_issuing' => 'draft', 'activation_mode' => 'automatic', 'notice_days' => 0, 'charge_setup_fee_at_signup' => false,
            'components' => [
                ['kind' => 'recurring', 'description' => 'Synthetic hosting', 'cadence' => $cadence, 'amount' => $amounts[$cadence]],
                ...match ($components) {
                    'one component' => [],
                    'two components' => [['kind' => 'recurring', 'description' => 'Synthetic backups', 'cadence' => $cadence, 'amount' => 700]],
                    'a setup fee with the first cycle' => [['kind' => 'setup_fee', 'description' => 'Synthetic setup', 'amount' => 15000]],
                },
            ],
        ];
        $start = CarbonImmutable::parse(sprintf('2026-01-%02d 10:00:00', $day), 'UTC');

        $billed = [];
        $dates = [];
        foreach (['never moved', 'moved on the first date', 'moved on the second date'] as $index => $twin) {
            $this->travelTo($start);
            [$workspace, $owner] = $this->packageWorkspace('Synthetic schedule '.$twin);
            $workspace->forceFill(['timezone' => 'UTC'])->save();
            $packages = app(ServicePackageWorkflow::class);
            $package = $packages->publish($owner, $workspace, $packages->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'synthetic-schedule', ...$terms])));
            $from = ServicePackageVersion::query()->findOrFail($package->published_version_id);
            $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic schedule client', 'slug' => 'synthetic-schedule-'.$index]);
            $contact = User::factory()->create();
            ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
            $enrollments = app(PackageEnrollmentWorkflow::class);
            $enrollment = $enrollments->accept($contact, $company, $offer = $enrollments->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Synthetic Signer'));
            $packages->update($owner, $workspace, $package->refresh(), ['summary' => 'Synthetic summary, reworded.']);
            $package = $packages->publish($owner, $workspace, $package->refresh());
            if ($dates === []) {
                $dates = array_slice(app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package, $from->public_id, null)['subscribers'][0]['effective_dates'], 0, 2);
                $this->assertCount(2, $dates);
            }
            $end = CarbonImmutable::parse($dates[1])->addMonthsNoOverflow(2 * $months)->setTime(6, 0);
            $this->tickAndIssue($company, $workspace, $start->setTime(12, 0), null);

            if ($index > 0) {
                $effective = $dates[$index - 1];
                app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, $effective, [$enrollment->public_id], null);
                $this->tickAndIssue($company, $workspace, CarbonImmutable::parse($effective)->subDay()->setTime(12, 0), $effective);
                $this->travelTo(CarbonImmutable::parse($effective)->setTime(0, 30));
                $this->assertSame(0, Artisan::call('svc:packages:apply-migrations', ['--apply' => true]), Artisan::output());
                $this->assertSame('applied', ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->sole()->status, $twin);
                $this->tickAndIssue($company, $workspace, CarbonImmutable::parse($effective)->setTime(1, 0), null);
            }
            $this->tickAndIssue($company, $workspace, $end, null);

            $invoices = ClientInvoice::query()->where('client_company_id', $company->id)->where('status', '!=', InvoiceStatus::Void->value)->orderBy('cycle_start')->orderBy('id')->get();
            $periods = $invoices->map(fn (ClientInvoice $invoice): string => (string) $invoice->cycle_start?->toDateString())->all();
            $this->assertSame(array_values(array_unique($periods)), $periods, "{$twin}: every period invoiced once.");
            $billed[$twin] = $invoices->mapWithKeys(fn (ClientInvoice $invoice): array => [(string) $invoice->cycle_start?->toDateString() => (int) $invoice->total_amount])->all();
        }

        $this->assertNotSame([], $billed['never moved']);
        $this->assertSame($billed['never moved'], $billed['moved on the first date'], "Moved on {$dates[0]}.");
        $this->assertSame($billed['never moved'], $billed['moved on the second date'], "Moved on {$dates[1]}.");
    }

    /** Travel to `$at`, generate every period due, and issue every draft - before `$before` only, when given. */
    private function tickAndIssue(ClientCompany $company, Workspace $workspace, CarbonImmutable $at, ?string $before): void
    {
        $this->travelTo($at);
        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        foreach ($this->drafts($company) as $draft) {
            $dated = $draft->issue_date?->toDateString();
            if (($dated === null || $dated <= $at->toDateString()) && ($before === null || ($draft->cycle_start !== null && $draft->cycle_start->toDateString() < $before))) {
                app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $workspace);
            }
        }
    }

    /**
     * What the move does, done by hand: the agreement ended the day before
     * with its recurring items, the draft for the first new period discarded,
     * and a new agreement from the new version started on the date.
     */
    private function renewByHand(Workspace $workspace, ClientCompany $company, ClientProject $project, ClientAgreement $old, ServicePackage $package, string $effective): void
    {
        $version = ServicePackageVersion::query()->with('components')->findOrFail($package->refresh()->published_version_id);
        $agreements = app(AgreementWorkflow::class);
        // The schedule the renewal continues, read from the old items as the move reads them.
        $recurrence = ContinuedRecurrence::fromItems($old->effectiveBillingCadence(), $old->starts_on, CarbonImmutable::parse($effective), PackageMigrationRecords::scheduleFacts(
            ClientAgreementRecurringItem::query()->where('client_agreement_id', $old->id)->where('is_active', true)->where('cadence', '!=', 'one_time')->orderBy('id')->get(),
        ));
        $agreements->terminate($old, CarbonImmutable::parse($effective)->subDay()->toDateString());
        ClientAgreementRecurringItem::query()->where('client_agreement_id', $old->id)->update(['expires_on' => CarbonImmutable::parse($effective)->subDay()->toDateString()]);
        foreach (ClientInvoice::query()->where('client_agreement_id', $old->id)->where('status', InvoiceStatus::Draft->value)->whereDate('cycle_start', '>=', $effective)->orderByDesc('cycle_start')->get() as $draft) {
            app(InvoiceLifecycleService::class)->discardDraft($draft, $workspace, 'Synthetic renewal');
        }
        $renewal = app(PackageAgreementTerms::class)->createDraft($workspace, $company, $project, $version, CarbonImmutable::parse($effective), continuing: $recurrence ?? throw new \LogicException('No schedule to continue.'));
        $agreements->activate($renewal);
    }

    /** @return iterable<string, array{array<string, mixed>, string, list<string>}> */
    public static function terminatedWithItemsWorlds(): iterable
    {
        $monthly = ['retainer_minutes' => 600, 'rollover_months' => 1];
        $quarterly = ['billing_cadence' => 'quarterly', 'bill_overage_interim' => false, 'retainer_minutes' => 600, 'rollover_months' => 3];
        $hosting = static fn (int $through): array => array_map(static fn (int $month): string => sprintf('2026-%02d-20', $month), range(1, $through));
        yield 'monthly, ended at a period close' => [$monthly, '2026-06-30', $hosting(6)];
        yield 'monthly, ended mid-period' => [$monthly, '2026-06-15', $hosting(5)];
        yield 'quarterly, ended at a period close' => [$quarterly, '2026-06-30', $hosting(6)];
        yield 'quarterly, ended mid-period' => [$quarterly, '2026-05-15', $hosting(4)];
    }

    /**
     * A recurring item with no expiry of its own ends with its agreement
     * (docs/client-management/cadence-billing.md): the closing invoice of a
     * terminated agreement, and every period the walk reaches after it, sells
     * no incidence dated after the end, exactly as it sells no retainer there.
     * Each incidence up to the end is still billed, once.
     *
     * @param  array<string, mixed>  $terms
     * @param  list<string>  $hostingBilled  the monthly item's incidences on or before the end
     */
    #[DataProvider('terminatedWithItemsWorlds')]
    public function test_a_terminated_agreement_bills_no_recurring_item_after_its_end(array $terms, string $end, array $hostingBilled): void
    {
        Carbon::setTestNow('2026-10-10 12:00:00');
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        [$workspace, $company, $project, $owner] = $this->world('terminated-items');
        $agreement = $this->agreement($workspace, $company, $terms);
        $hosting = $this->recurringItem($workspace, $agreement, 'Synthetic hosting', ['cadence' => 'monthly', 'anchor_day' => 20]);
        $support = $this->recurringItem($workspace, $agreement, 'Synthetic support plan', ['cadence' => 'quarterly', 'anchor_month' => 1, 'anchor_day' => 1]);
        foreach ([['2026-02-09', 1500], ['2026-04-14', 300], ['2026-05-04', 240], ['2026-06-08', 900]] as [$day, $minutes]) {
            if ($day <= $end) {
                $this->hours($workspace, $company, $project, $owner, $day, $minutes);
            }
        }
        app(AgreementWorkflow::class)->terminate($agreement, $end);

        Carbon::setTestNow('2027-01-20 12:00:00');
        CarbonImmutable::setTestNow('2027-01-20 12:00:00');
        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        $this->assertInvariants($company, 'generated after the termination');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');

        $billed = fn (int $itemId): array => DB::table('client_invoice_lines as line')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->where('invoice.workspace_id', $workspace->id)->where('invoice.status', '!=', InvoiceStatus::Void->value)
            ->where('line.client_agreement_recurring_item_id', $itemId)
            ->orderBy('line.line_date')->pluck('line.line_date')
            ->map(fn ($date): string => Carbon::parse((string) $date)->toDateString())->all();
        $this->assertSame($hostingBilled, $billed($hosting), 'The monthly item bills each incidence up to the end, once, and none after it.');
        $this->assertSame(['2026-01-01', '2026-04-01'], $billed($support), 'The quarterly item bills each incidence up to the end, once, and none after it.');
    }

    public function test_a_backdated_termination_refreshes_non_monthly_drafts_after_its_end(): void
    {
        Carbon::setTestNow('2026-11-09 12:00:00');
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');
        [$workspace, $company] = $this->world('backdated-termination');
        $agreement = $this->agreement($workspace, $company, [
            'billing_cadence' => 'quarterly',
            'bill_overage_interim' => false,
            'retainer_minutes' => 600,
            'rollover_months' => 3,
        ]);

        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());

        app(AgreementWorkflow::class)->terminate($agreement->fresh() ?? $agreement, '2026-09-30');

        app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        $this->assertInvariants($company, 'regenerated after backdated termination');

        $q1Draft = ClientInvoice::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_agreement_id', $agreement->id)
            ->whereDate('cycle_start', '2027-01-01')
            ->first();
        $this->assertNotNull($q1Draft);
        $this->assertSame(0, (int) $q1Draft->total_amount);
        $this->assertSame(0, $q1Draft->lines()->where('type', InvoiceLineType::Retainer->value)->count());

        Carbon::setTestNow('2027-01-02 12:00:00');
        CarbonImmutable::setTestNow('2027-01-02 12:00:00');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');
    }

    // ---- the invariants ----

    /**
     * @param  bool  $rebuilt  the step ran a dispute's rebuild, which must have
     *                         reached every draft depending on the row: none is
     *                         left for regeneration to change
     */
    private function assertInvariants(ClientCompany $company, string $step, bool $rebuilt = false): void
    {
        $this->assertStaleDraftsAreRefused($company, $step, $rebuilt);
        $this->assertNoHourIsChargedTwice($company, $step);
        $this->assertEachAgreementBillsItsOwnSegment($company, $step);
    }

    /**
     * Regenerate each draft in a savepoint; if that would change it, issuing
     * it as it stands, in another savepoint, must be refused.
     */
    private function assertStaleDraftsAreRefused(ClientCompany $company, string $step, bool $rebuilt): void
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        foreach ($this->drafts($company) as $draft) {
            $before = $this->shape($draft);
            $after = $this->inSavepoint(function () use ($draft): ?array {
                $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($draft);

                return $rebuilt === null ? null : $this->shape($rebuilt->fresh() ?? $rebuilt);
            });
            // An empty draft regeneration discards charges nothing either way.
            if ($after === $before || ($after === null && $before === [])) {
                continue;
            }
            $this->assertFalse($rebuilt, sprintf(
                "After %s, its rebuild left draft %s for %s stale.\nNow: %s\nRegenerated: %s",
                $step, $draft->invoice_number, $draft->service_period_start?->toDateString(), json_encode($before), json_encode($after),
            ));
            $issued = $this->inSavepoint(function () use ($draft, $workspace): bool {
                try {
                    app(InvoiceLifecycleService::class)->issue($draft->fresh() ?? $draft, $workspace);

                    return true;
                } catch (DomainException) {
                    return false;
                }
            });
            $this->assertFalse($issued, sprintf(
                "After %s, draft %s for %s would change on regeneration, and issued as it was.\nNow: %s\nRegenerated: %s",
                $step, $draft->invoice_number, $draft->service_period_start?->toDateString(), json_encode($before), json_encode($after),
            ));
        }
    }

    private function assertNoHourIsChargedTwice(ClientCompany $company, string $step): void
    {
        $twice = DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->where('invoice.workspace_id', $company->workspace_id)
            ->where('invoice.client_company_id', $company->id)
            ->where('invoice.status', '!=', InvoiceStatus::Void->value)
            ->groupBy('allocation.client_time_entry_id')
            ->havingRaw('count(distinct invoice.id) > 1')
            ->pluck('allocation.client_time_entry_id')
            ->all();
        $this->assertSame([], $twice, "After {$step}, these hours are allocated by two live invoices.");
    }

    /**
     * Ordinary work dated from a successor's start is the successor's; before
     * an agreement took over from a predecessor it is the predecessor's; and
     * deferred work ends with the agreement's segment, its termination
     * included. A termination with no successor hands no ordinary work over.
     * Asked of charged invoices.
     */
    private function assertEachAgreementBillsItsOwnSegment(ClientCompany $company, string $step): void
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $reader = app(DeferredBacklogReader::class);
        $agreements = ClientAgreement::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)->get();
        foreach ($agreements as $agreement) {
            $handedOver = $reader->handedOverAfter((int) $workspace->id, $agreement)?->toDateString();
            $tookOver = $reader->tookOverOn((int) $workspace->id, $agreement)?->toDateString();
            $segmentEnd = $reader->segmentEnd($workspace, $agreement, CarbonImmutable::parse('2099-12-31'))->toDateString();
            $outside = DB::table('client_invoice_line_time_entries as allocation')
                ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
                ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
                ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
                ->where('invoice.workspace_id', $company->workspace_id)
                ->where('invoice.client_agreement_id', $agreement->id)
                // Charged invoices: a draft outside its segment is stale, which
                // the stale-draft check holds to a refusal at issue.
                ->whereNotIn('invoice.status', [InvoiceStatus::Void->value, InvoiceStatus::Draft->value])
                ->where(fn ($mode) => $mode->whereNull('entry.subcontractor_billing_mode')->orWhere('entry.subcontractor_billing_mode', 'retainer'))
                ->where(fn ($outside) => $outside
                    ->where(fn ($deferred) => $deferred->where('entry.is_deferred', true)->whereDate('entry.worked_on', '>', $segmentEnd))
                    ->when($handedOver !== null, fn ($query) => $query->orWhere(fn ($ordinary) => $ordinary->where('entry.is_deferred', false)->whereDate('entry.worked_on', '>', $handedOver)))
                    ->when($tookOver !== null, fn ($query) => $query->orWhere(fn ($ordinary) => $ordinary->where('entry.is_deferred', false)->whereDate('entry.worked_on', '<', $tookOver))))
                ->pluck('entry.id')->all();
            $this->assertSame([], $outside, "After {$step}, agreement {$agreement->id} bills work outside its segment (handed over after {$handedOver}, took over on {$tookOver}, deferred through {$segmentEnd}).");

            // Nor does it sell anything dated after that end: a retainer line
            // is dated the day its cycle opens, an item's line the day of its
            // incidence.
            $sold = DB::table('client_invoice_lines as line')
                ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
                ->where('invoice.workspace_id', $company->workspace_id)
                ->where('invoice.client_agreement_id', $agreement->id)
                ->where('invoice.status', '!=', InvoiceStatus::Void->value)
                ->whereIn('line.type', [InvoiceLineType::Retainer->value, InvoiceLineType::RecurringItem->value])
                ->where(fn ($query) => $query
                    ->whereDate('line.line_date', '>', $segmentEnd)
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('invoice.cycle_start')
                        ->whereDate('invoice.cycle_start', '>', $segmentEnd)))
                ->get(['line.type', 'line.line_date'])
                ->map(fn (object $line): string => $line->type.' '.Carbon::parse((string) $line->line_date)->toDateString())->all();
            $this->assertSame([], $sold, "After {$step}, agreement {$agreement->id} sells a cycle or incidence dated after its segment ends on {$segmentEnd}.");
        }
    }

    // ---- the history ----

    private function issueEveryDraft(ClientCompany $company, Workspace $workspace): void
    {
        $today = Carbon::now()->toDateString();
        foreach ($this->drafts($company) as $draft) {
            $current = $draft->fresh() ?? $draft;
            if ($current->issue_date !== null && $current->issue_date->toDateString() > $today) {
                continue;
            }
            try {
                app(InvoiceLifecycleService::class)->issue($current, $workspace);
            } catch (DomainException) {
                $rebuilt = app(ClientInvoicingService::class)->regenerateDraftInvoice($current);
                if ($rebuilt !== null) {
                    app(InvoiceLifecycleService::class)->issue($rebuilt->fresh() ?? $rebuilt, $workspace);
                }
            }
        }
    }

    /**
     * Each live invoice line charging the entry, or a fragment split from it,
     * as its agreement and unit price.
     *
     * @return list<array{int, int}>
     */
    private function chargesOf(ClientTimeEntry $entry): array
    {
        return array_values(array_unique(DB::table('client_invoice_line_time_entries as allocation')
            ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
            ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
            ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
            ->where('invoice.status', '!=', InvoiceStatus::Void->value)
            ->whereRaw('coalesce(entry.split_from_time_entry_id, entry.id) = ?', [$entry->id])
            ->orderBy('line.id')
            ->get(['invoice.client_agreement_id', 'line.unit_amount'])
            ->map(fn (object $row): array => [(int) $row->client_agreement_id, (int) $row->unit_amount])
            ->all(), SORT_REGULAR));
    }

    /** @return Collection<int, ClientInvoice> */
    private function drafts(ClientCompany $company): Collection
    {
        return ClientInvoice::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)
            ->where('status', InvoiceStatus::Draft->value)->orderBy('service_period_start')->orderBy('id')->get();
    }

    /** @return array<string, int> each invoice's total, by its kind and period */
    private function draftTotals(ClientCompany $company, ?string $kind = null): array
    {
        return ClientInvoice::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)
            ->when($kind !== null, fn ($query) => $query->where('invoice_kind', $kind))
            ->orderBy('service_period_start')->orderBy('invoice_kind')->get()
            ->mapWithKeys(fn (ClientInvoice $invoice): array => [$invoice->invoice_kind.' '.$invoice->service_period_start?->toDateString().'..'.$invoice->service_period_end?->toDateString() => (int) $invoice->total_amount])
            ->all();
    }

    /**
     * Each line's type, quantity, total and the time it allocates, by day and
     * minutes: regeneration recombines and re-splits fragments, so the same
     * allocation can come back under new ids.
     *
     * @return array<int, array{string, string, int, array<int, string>}>
     */
    private function shape(ClientInvoice $invoice): array
    {
        return $invoice->lines()->with('timeEntries:id,worked_on,minutes')->get()
            ->map(fn ($line): array => [(string) $line->type, (string) $line->quantity, (int) $line->total_amount, $line->timeEntries
                ->map(fn (ClientTimeEntry $entry): string => $entry->worked_on->toDateString().':'.(int) $entry->minutes)->sort()->values()->all()])
            ->sortBy(fn (array $shape): string => json_encode($shape, JSON_THROW_ON_ERROR))->values()->all();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function inSavepoint(callable $work): mixed
    {
        DB::beginTransaction();
        try {
            return $work();
        } finally {
            DB::rollBack();
        }
    }

    /** The client's dispute and the rebuild its commit runs. */
    private function dispute(Workspace $workspace, ClientTimeEntry $entry): void
    {
        ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereKey($entry->id)->update([
            'client_disputed_at' => now(), 'client_dispute_note' => 'Synthetic question',
            'client_dispute_settlement' => null, 'lock_version' => DB::raw('lock_version + 1'),
        ]);
        app(DisputedDraftRebuilder::class)->withdraw($workspace->id, [$entry->id], []);
    }

    private function settle(Workspace $workspace, ClientTimeEntry $entry, User $actor, DisputeSettlement $outcome): void
    {
        $fresh = $entry->fresh() ?? $entry;
        app(DisputeSettlements::class)->settleTime($workspace, $fresh->public_id, $actor, $outcome, 'Synthetic reply', AgentApiVersion::for($fresh));
    }

    private function period(ClientCompany $company, ClientAgreement $agreement, string $firstOfMonth): ClientInvoice
    {
        return app(ClientInvoicingService::class)->generateCadencePeriod($company, $agreement->fresh() ?? $agreement, Carbon::parse($firstOfMonth));
    }

    /** @return array{Workspace, ClientCompany, ClientProject, User} */
    private function world(string $name): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic invariants '.$name, 'slug' => 'synthetic-invariants-'.$name]);
        $owner = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic invariants client', 'slug' => 'synthetic-invariants-client-'.$name]);
        $project = ClientProject::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => 'Synthetic invariants project']);

        return [$workspace, $company, $project, $owner];
    }

    /** @param array<string, mixed> $terms */
    private function agreement(Workspace $workspace, ClientCompany $company, array $terms): ClientAgreement
    {
        $minutes = (int) ($terms['retainer_minutes'] ?? 0);

        return ClientAgreement::query()->create($terms + [
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic agreement', 'status' => 'active',
            'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000,
            'retainer_amount' => $minutes * 100, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
    }

    /**
     * An item with no expiry of its own, starting with the agreement.
     *
     * @param  array<string, mixed>  $terms
     */
    private function recurringItem(Workspace $workspace, ClientAgreement $agreement, string $description, array $terms): int
    {
        return (int) ClientAgreementRecurringItem::query()->create($terms + [
            'workspace_id' => $workspace->id, 'client_agreement_id' => $agreement->id, 'description' => $description,
            'amount' => 5000, 'currency' => 'USD', 'quantity' => '1', 'is_active' => true,
        ])->id;
    }

    private function hours(Workspace $workspace, ClientCompany $company, ClientProject $project, User $author, string $day, int $minutes): ClientTimeEntry
    {
        return ClientTimeEntry::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => $project->id,
            'user_id' => $author->id, 'worked_on' => $day, 'minutes' => $minutes, 'description' => 'Synthetic work',
            'client_visible_description' => 'Synthetic client text', 'is_visible_to_client' => true,
            'status' => 'approved', 'is_billable' => true, 'is_deferred' => false, 'billing_rate_amount' => 10000, 'currency' => 'USD',
        ])->refresh();
    }
}
