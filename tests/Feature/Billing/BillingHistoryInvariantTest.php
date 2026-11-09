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
use App\Services\Packages\PackageMigrationWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Approval\DisputeSettlement;
use App\Support\Billing\InvoiceLineType;
use App\Support\Billing\InvoiceStatus;
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
        $this->agreement($workspace, $company, [
            'starts_on' => '2026-05-16', 'billing_cadence' => 'quarterly', 'bill_overage_interim' => false,
            'retainer_minutes' => 0, 'retainer_amount' => 0,
        ]);
        foreach (['2026-02-10', '2026-05-01', '2026-06-10', '2026-08-03'] as $day) {
            ClientTimeEntry::query()->whereKey($this->hours($workspace, $company, $project, $owner, $day, 120)->id)->update(['is_deferred' => true]);
        }
        $this->hours($workspace, $company, $project, $owner, '2026-03-03', 60);

        app(ClientInvoicingService::class)->generateAllInvoices($company);
        $this->assertInvariants($company, 'generated in bulk');
        $this->issueEveryDraft($company, $workspace);
        $this->assertInvariants($company, 'everything issued');

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

    /**
     * What the move does, done by hand: the agreement ended the day before
     * with its recurring items, the draft for the first new period discarded,
     * and a new agreement from the new version started on the date.
     */
    private function renewByHand(Workspace $workspace, ClientCompany $company, ClientProject $project, ClientAgreement $old, ServicePackage $package, string $effective): void
    {
        $version = ServicePackageVersion::query()->with('components')->findOrFail($package->refresh()->published_version_id);
        $agreements = app(AgreementWorkflow::class);
        $agreements->terminate($old, CarbonImmutable::parse($effective)->subDay()->toDateString());
        ClientAgreementRecurringItem::query()->where('client_agreement_id', $old->id)->update(['expires_on' => CarbonImmutable::parse($effective)->subDay()->toDateString()]);
        foreach (ClientInvoice::query()->where('client_agreement_id', $old->id)->where('status', InvoiceStatus::Draft->value)->whereDate('cycle_start', '>=', $effective)->orderByDesc('cycle_start')->get() as $draft) {
            app(InvoiceLifecycleService::class)->discardDraft($draft, $workspace, 'Synthetic renewal');
        }
        $renewal = app(PackageAgreementTerms::class)->createDraft($workspace, $company, $project, $version, CarbonImmutable::parse($effective), continuing: true);
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

    private function assertEachAgreementBillsItsOwnSegment(ClientCompany $company, string $step): void
    {
        $workspace = Workspace::query()->findOrFail($company->workspace_id);
        $agreements = ClientAgreement::query()->where('workspace_id', $company->workspace_id)->where('client_company_id', $company->id)->get();
        foreach ($agreements as $agreement) {
            $end = app(DeferredBacklogReader::class)->segmentEnd($workspace, $agreement, CarbonImmutable::parse('2099-12-31'))->toDateString();
            $late = DB::table('client_invoice_line_time_entries as allocation')
                ->join('client_invoice_lines as line', 'line.id', '=', 'allocation.client_invoice_line_id')
                ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
                ->join('client_time_entries as entry', 'entry.id', '=', 'allocation.client_time_entry_id')
                ->where('invoice.workspace_id', $company->workspace_id)
                ->where('invoice.client_agreement_id', $agreement->id)
                ->where('invoice.status', '!=', InvoiceStatus::Void->value)
                ->whereDate('entry.worked_on', '>', $end)
                ->pluck('entry.id')->all();
            $this->assertSame([], $late, "After {$step}, agreement {$agreement->id} bills work dated after its segment ends on {$end}.");

            // Nor does it sell anything dated after that end: a retainer line
            // is dated the day its cycle opens, an item's line the day of its
            // incidence.
            $sold = DB::table('client_invoice_lines as line')
                ->join('client_invoices as invoice', 'invoice.id', '=', 'line.client_invoice_id')
                ->where('invoice.workspace_id', $company->workspace_id)
                ->where('invoice.client_agreement_id', $agreement->id)
                ->where('invoice.status', '!=', InvoiceStatus::Void->value)
                ->whereIn('line.type', [InvoiceLineType::Retainer->value, InvoiceLineType::RecurringItem->value])
                ->whereDate('line.line_date', '>', $end)
                ->get(['line.type', 'line.line_date'])
                ->map(fn (object $line): string => $line->type.' '.Carbon::parse((string) $line->line_date)->toDateString())->all();
            $this->assertSame([], $sold, "After {$step}, agreement {$agreement->id} sells a cycle or incidence dated after its segment ends on {$end}.");
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
