<?php

namespace Tests\Feature\ServicePackages;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Packages\ClientServiceReadService;
use App\Services\Packages\PackageEnrollmentReadService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageRecurringInvoices;
use App\Support\Packages\AutoInvoiceResult;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Recurring invoices of package agreements (docs/service-packages.md, slice 4:
 * INV-1, INV-2, INV-3): `svc:packages:issue-invoices` invoicing and issuing
 * each due period of an automatic package through the ordinary cadence guard,
 * engine and lifecycle; drafts only for a draft-mode package; nothing for one
 * Square collects; and a refusal an owner sees.
 *
 * The clock starts at 15 November 2026 in a UTC workspace. Every package
 * charges nothing at sign-up, so the first period is the command's to bill.
 */
final class PackageRecurringInvoicesTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    private const string HOSTING = 'Managed hosting';

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC'));
    }

    // ── INV-1: invoiced and issued, through the ordinary path ──────────────

    /** Monthly: the first period on the day the service starts, the next on the 1st, each once. */
    #[Requirement('INV-1')]
    public function test_a_monthly_automatic_package_has_each_period_invoiced_and_issued_once(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $company->forceFill(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => 0])->save();
        $enrollment = $this->accept($contact, $company, $package);
        $this->assertSame('active', $enrollment->status);

        $run = $this->issueRun();
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'issued']], $this->brief($run));
        $november = $this->invoiceFor($enrollment, '2026-11-01');
        $this->assertSame(['issued', 'cadence_period', 4500, 4500], [$november->status, $november->invoice_kind, $november->total_amount, $november->balance_amount]);
        $this->assertSame([['retainer', 0], ['recurring_item', 4500]], $this->lines($november));
        // Dated the first of the month it sells, as every cadence invoice is;
        // issued on the 15th, it is due that day, not overdue on arrival.
        $this->assertSame(['2026-11-01', '2026-11-15'], [$november->issue_date?->toDateString(), $november->due_date?->toDateString()]);
        // Automatic delivery follows the company's own setting.
        $this->assertSame('scheduled', $november->automatic_delivery_status);
        $this->assertSame(['issued', '2026-11-01', $november->id], [$enrollment->refresh()->auto_invoice_outcome, $enrollment->auto_invoice_period_start?->toDateString(), $enrollment->auto_invoice_id]);

        // Again the same hour, and again the next day: nothing more.
        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00', 'UTC'));
        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());

        // December, on the 1st, due the 1st.
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->assertSame([['period_start' => '2026-12-01', 'outcome' => 'issued']], $this->brief($this->issueRun()));
        $december = $this->invoiceFor($enrollment, '2026-12-01');
        $this->assertSame(['issued', 4500, '2026-12-01', '2026-12-01'], [$december->status, $december->total_amount, $december->issue_date?->toDateString(), $december->due_date?->toDateString()]);
        $this->assertSame([['retainer', 0], ['recurring_item', 4500]], $this->lines($december));
        $this->assertSame(2, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, ClientInvoiceLine::query()->where('type', 'recurring_item')->whereDate('line_date', '<', '2026-12-01')->count(), 'November\'s hosting is billed once');
    }

    /** Non-monthly: a quarterly package's cycles are anchored on its start, and each is billed once. */
    #[Requirement('INV-1')]
    public function test_a_quarterly_automatic_package_has_each_cycle_invoiced_and_issued_once(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world([
            'billing_cadence' => 'quarterly',
            'components' => [['kind' => 'recurring', 'description' => self::HOSTING, 'cadence' => 'quarterly', 'amount' => 12000]],
        ]);
        $enrollment = $this->accept($contact, $company, $package);

        $this->assertSame([['period_start' => '2026-11-15', 'outcome' => 'issued']], $this->brief($this->issueRun()));
        $first = $this->invoiceFor($enrollment, '2026-11-15');
        // The engine's quarterly item is anchored on the 1st of its start
        // month, so a first quarter anchored mid-month also holds its next
        // incidence (1 February): the engine's own rule, recorded in slice
        // 3b and not changed here.
        $this->assertSame(['issued', 24000, '2026-11-15', '2027-02-14'], [$first->status, $first->total_amount, $first->cycle_start?->toDateString(), $first->cycle_end?->toDateString()]);
        $this->assertSame([['recurring_item', 12000], ['recurring_item', 12000]], $this->lines($first));
        $this->assertSame([], $this->issueRun()['outcomes']);

        $this->travelTo(CarbonImmutable::parse('2027-01-20 10:00:00', 'UTC'));
        $this->assertSame([], $this->issueRun()['outcomes'], 'mid-cycle, nothing is due');

        $this->travelTo(CarbonImmutable::parse('2027-02-15 10:00:00', 'UTC'));
        $this->assertSame([['period_start' => '2027-02-15', 'outcome' => 'issued']], $this->brief($this->issueRun()));
        $second = $this->invoiceFor($enrollment, '2027-02-15');
        $this->assertSame(['issued', 12000, '2027-05-14'], [$second->status, $second->total_amount, $second->cycle_end?->toDateString()]);
        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->assertSame(2, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    /**
     * A run that read the period as due before another invoiced it - a
     * concurrent run, or an owner - finds the claim under the agreement's lock
     * and invoices nothing: the period is billed once whichever wins.
     */
    #[Requirement('INV-1')]
    public function test_a_run_that_read_the_period_as_due_before_another_invoiced_it_invoices_nothing(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        $recurring = app(PackageRecurringInvoices::class);

        // Both runs read the period as due before either has invoiced it.
        $first = $recurring->due($workspace, $recurring->automaticEnrollments($workspace));
        $second = $recurring->due($workspace, $recurring->automaticEnrollments($workspace));
        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $this->assertSame('issued', $recurring->invoice($workspace, ...$first[0])->label());
        $lost = $recurring->invoice($workspace, ...$second[0]);
        $this->assertSame(AutoInvoiceResult::ALREADY_INVOICED, $lost->label());
        $this->assertNull($lost->outcome, 'losing the race is not something to record');

        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame('issued', $enrollment->refresh()->auto_invoice_outcome);
    }

    /** Generation and issue commit together: an issue that fails leaves no claimed, unissued draft behind. */
    #[Requirement('INV-1')]
    public function test_an_issue_that_fails_rolls_the_generated_invoice_back_and_is_retried(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        // Automatic delivery switched on with no delay is refused at issue.
        DB::table('client_companies')->where('id', $company->id)->update(['automatic_invoice_email_enabled' => true, 'automatic_invoice_email_delay_days' => null]);

        $run = $this->issueRun(expectFailure: true);
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'refused']], $this->brief($run));
        $this->assertSame('issue_refused', $run['outcomes'][0]['refusal']);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count(), 'nothing half-made');
        $this->assertSame(0, ClientInvoiceLine::query()->count());

        DB::table('client_companies')->where('id', $company->id)->update(['automatic_invoice_email_delay_days' => 1]);
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'issued']], $this->brief($this->issueRun()));
        $this->assertSame('issued', $this->invoiceFor($enrollment, '2026-11-01')->status);
    }

    /** A preview writes nothing, and says what an applied run would do. */
    #[Requirement('INV-1')]
    public function test_a_preview_reports_the_period_and_keeps_nothing(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);

        $preview = $this->issueRun(apply: false);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'issued']], $this->brief($preview));
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNull($enrollment->refresh()->auto_invoice_outcome);
    }

    /**
     * A client's second package is its own project and agreement, billed on
     * the same days as the first: each is invoiced, neither blocks the other
     * (the overlap guard compares invoices whose work can overlap).
     */
    #[Requirement('INV-1')]
    public function test_two_automatic_packages_of_one_client_are_each_invoiced(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $care = $this->publishedPackage($workspace, $owner, $this->terms([
            'link_name' => 'care-plan', 'name' => 'Care plan',
            'components' => [['kind' => 'recurring', 'description' => 'Care plan', 'cadence' => 'monthly', 'amount' => 9000]],
        ]));
        $hosting = $this->accept($contact, $company, $package);
        $plan = $this->accept($contact, $company, $care);
        $this->assertNotSame($hosting->client_project_id, $plan->client_project_id);

        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'issued'], ['period_start' => '2026-11-01', 'outcome' => 'issued']], $this->brief($this->issueRun()));
        $this->assertSame(4500, $this->invoiceFor($hosting, '2026-11-01')->total_amount);
        $this->assertSame(9000, $this->invoiceFor($plan, '2026-11-01')->total_amount);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertCount(2, $this->issueRun()['outcomes']);
        $this->assertSame(4, ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', 'issued')->count());
    }

    /**
     * The engine refusing a period - here an invoice of the client's that
     * names no agreement already covers the work period, history nobody can
     * attribute - is rolled back, recorded, shown in Needs you, retried, and
     * does not stop another client's period. Once the cause is gone, the run
     * invoices it and the row goes.
     */
    #[Requirement('INV-1')]
    public function test_a_refused_period_reaches_an_owner_does_not_stop_others_and_is_retried(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $blocking = $this->issuedCadenceInvoice($workspace, $company, null, 'SYN-0001', '2026-10-01', '2026-10-31', '2026-11-01');
        $refusedEnrollment = $this->accept($contact, $company, $package);
        $other = $this->company($workspace, 'Harbor Synthetic Florist');
        $otherEnrollment = $this->accept($this->contact($other), $other, $package);

        $run = $this->issueRun(expectFailure: true);
        $outcomes = collect($run['outcomes'])->keyBy('enrollment_id');
        $this->assertSame('refused', $outcomes[$refusedEnrollment->public_id]['outcome']);
        $this->assertSame('period_unbillable', $outcomes[$refusedEnrollment->public_id]['refusal']);
        $this->assertSame('issued', $outcomes[$otherEnrollment->public_id]['outcome'], 'one refusal stops nobody else');
        $this->assertSame(0, ClientInvoice::query()->where('client_agreement_id', $refusedEnrollment->client_agreement_id)->count());

        $refusedEnrollment->refresh();
        $this->assertSame(['refused', '2026-11-01', 'period_unbillable', null], [$refusedEnrollment->auto_invoice_outcome, $refusedEnrollment->auto_invoice_period_start?->toDateString(), $refusedEnrollment->auto_invoice_refusal, $refusedEnrollment->auto_invoice_id]);
        $this->assertStringContainsString('SYN-0001', (string) $refusedEnrollment->auto_invoice_detail);
        $row = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused');
        $this->assertNotNull($row);
        $this->assertSame(['Web hosting · Essentials: invoice not issued automatically', 'Lakeside Synthetic Bakery', '2026-11-01'], [$row['title'], $row['client'], $row['date']]);
        $this->assertSame(route('clients.agreement', [$workspace, $company, ClientAgreement::query()->findOrFail($refusedEnrollment->client_agreement_id)], absolute: false), $row['href']);

        // Retried an hour later: the same refusal, recorded once.
        $version = $refusedEnrollment->lock_version;
        $recordedAt = $refusedEnrollment->auto_invoice_recorded_at;
        $this->travel(1)->hours();
        $this->assertSame(['refused'], array_column($this->issueRun(expectFailure: true)['outcomes'], 'outcome'));
        $this->assertSame([$version, $recordedAt?->toIso8601String()], [$refusedEnrollment->refresh()->lock_version, $refusedEnrollment->auto_invoice_recorded_at?->toIso8601String()]);

        // The owner voids the blocking invoice; the next run issues November.
        app(InvoiceLifecycleService::class)->void($blocking, $workspace, 'Synthetic: billed in error');
        $this->assertSame(['issued'], array_column($this->issueRun()['outcomes'], 'outcome'));
        $this->assertSame('issued', $this->invoiceFor($refusedEnrollment, '2026-11-01')->status);
        $this->assertSame(['issued', null, null], [$refusedEnrollment->refresh()->auto_invoice_outcome, $refusedEnrollment->auto_invoice_refusal, $refusedEnrollment->auto_invoice_detail]);
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused'));
    }

    /**
     * A client's company-wide agreement on a cadence reaches the package's
     * work too, so the two cannot bill the same dates. Automation never takes
     * them from it: the package's period is refused for the owner, whichever
     * would have been written first, and the company-wide agreement still
     * bills its own period.
     */
    #[Requirement('INV-1')]
    public function test_a_company_wide_cadence_agreement_keeps_its_dates_and_the_owner_is_told(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $retainer = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic company-wide retainer',
            'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD',
            'hourly_rate_amount' => 10000, 'retainer_amount' => 50000, 'retainer_minutes' => 300, 'rollover_months' => 0, 'catch_up_threshold_minutes' => 0,
        ]);
        $enrollment = $this->accept($contact, $company, $package);

        $run = $this->issueRun(expectFailure: true);
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'refused']], $this->brief($run));
        $this->assertSame('company_wide_agreement', $run['outcomes'][0]['refusal']);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNotNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused'));

        // The company-wide agreement's November is still its to bill.
        $november = app(ClientInvoicingService::class)->generateCadencePeriod($company, $retainer, Carbon::parse('2026-11-01'));
        $this->assertSame(['draft', 50000], [$november->status, $november->total_amount]);
        $this->issueRun(expectFailure: true);
        $this->assertSame(0, ClientInvoice::query()->where('client_agreement_id', $enrollment->client_agreement_id)->count());

        // Ended on 30 November, it still has November's work to reconcile, so
        // December (which reconciles November) stays its; January does not.
        $retainer->forceFill(['status' => 'terminated', 'ends_on' => '2026-11-30'])->save();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertSame('company_wide_agreement', $this->issueRun(expectFailure: true)['outcomes'][0]['refusal']);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 10:00:00', 'UTC'));
        $this->assertSame([['period_start' => '2027-01-01', 'outcome' => 'issued']], $this->brief($this->issueRun()));
    }

    /** A refused period an owner then invoices by hand is marked covered, and leaves Needs you. */
    #[Requirement('INV-1')]
    public function test_a_refused_period_invoiced_by_hand_is_marked_covered(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        DB::table('client_agreements')->where('id', $enrollment->client_agreement_id)->update(['square_series_key' => '000901']);
        $this->issueRun(expectFailure: true);
        $this->assertSame('refused', $enrollment->refresh()->auto_invoice_outcome);

        // Invoiced another way for the same period.
        $this->issuedCadenceInvoice($workspace, $company, ClientAgreement::query()->findOrFail($enrollment->client_agreement_id), 'SYN-HAND-1', '2026-10-01', '2026-10-31', '2026-11-01');
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'covered']], $this->brief($this->issueRun()));
        $this->assertSame('covered', $enrollment->refresh()->auto_invoice_outcome);
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused'));
        $this->assertSame([], $this->issueRun()['outcomes'], 'and nothing more to do');
    }

    // ── INV-2: drafts only ──────────────────────────────────────────────────

    /**
     * A draft-mode package's period is an ordinary cadence period: the
     * existing scheduled drafts create its draft, and nothing issues it. An
     * automatic one is left to the issuer, as a scheduled agreement is left
     * to its schedule.
     */
    #[Requirement('INV-2', 'INV-1')]
    public function test_a_draft_mode_package_gets_its_draft_from_the_cadence_drafts_and_nothing_issues_it(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['invoice_issuing' => 'draft']);
        $automatic = $this->publishedPackage($workspace, $owner, $this->terms(['link_name' => 'auto-hosting']));
        $drafted = $this->accept($contact, $company, $package);
        $issued = $this->accept($contact, $company, $automatic);

        $this->assertSame([$issued->public_id], array_column($this->issueRun()['outcomes'], 'enrollment_id'), 'the issuer leaves the draft-mode package alone');
        $this->assertSame(0, ClientInvoice::query()->where('client_agreement_id', $drafted->client_agreement_id)->count());

        Artisan::call('svc:billing:generate-cadence-drafts', ['--apply' => true, '--format' => 'json']);
        $drafts = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $agreements = ClientAgreement::query()->whereIn('id', [$drafted->client_agreement_id, $issued->client_agreement_id])->pluck('public_id', 'id');
        $this->assertSame([$agreements[$drafted->client_agreement_id]], array_column($drafts['generated'], 'agreement_id'), 'the automatic package is not drafted');
        $draft = $this->invoiceFor($drafted, '2026-11-01');
        $this->assertSame(['draft', 4500], [$draft->status, $draft->total_amount]);

        // The issuer, run again, and in December, still issues none of it.
        $this->issueRun();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertSame([$issued->public_id], array_column($this->issueRun()['outcomes'], 'enrollment_id'));
        $this->assertSame('draft', $draft->refresh()->status);
        $this->assertSame(0, ClientInvoice::query()->where('client_agreement_id', $drafted->client_agreement_id)->where('status', '!=', 'draft')->count());
        $this->assertNull($drafted->refresh()->auto_invoice_outcome);
    }

    /** While the feature is off, the drafts command treats an automatic package like any agreement: drafts, never issued. */
    #[Requirement('INV-2', 'X-1')]
    public function test_with_the_feature_off_an_automatic_package_is_only_drafted(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        config(['svc.service_packages.enabled' => false]);

        $this->assertSame([], $this->issueRun()['outcomes']);
        Artisan::call('svc:billing:generate-cadence-drafts', ['--apply' => true, '--format' => 'json']);
        $this->assertSame('draft', $this->invoiceFor($enrollment, '2026-11-01')->status);
        $this->assertSame(1, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    // ── INV-3: Square collects ──────────────────────────────────────────────

    #[Requirement('INV-3', 'X-2')]
    public function test_nothing_is_invoiced_for_an_agreement_square_collects_and_the_owner_is_told(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        DB::table('client_agreements')->where('id', $enrollment->client_agreement_id)->update(['square_series_key' => '000901']);

        $run = $this->issueRun(expectFailure: true);
        $this->assertSame([['period_start' => '2026-11-01', 'outcome' => 'refused']], $this->brief($run));
        $this->assertSame('collected_in_square', $run['outcomes'][0]['refusal']);
        // Not issued, and not even drafted: nothing exists to issue.
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame('collected_in_square', $enrollment->refresh()->auto_invoice_refusal);
        $this->assertNotNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused'));

        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->issueRun(expectFailure: true);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    // ── X-1: the feature switch ─────────────────────────────────────────────

    #[Requirement('INV-1', 'X-1')]
    public function test_the_command_does_nothing_while_the_feature_is_off_and_is_scheduled_only_while_it_is_on(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $this->accept($contact, $company, $package);
        config(['svc.service_packages.enabled' => false]);

        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'svc:packages:issue-invoices --apply'));
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));
        config(['svc.service_packages.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    /** Every read and write names the workspace: another's enrollment, refusal or run reaches nothing here. */
    #[Requirement('INV-1')]
    public function test_each_workspace_is_invoiced_and_read_on_its_own(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world();
        $mine = $this->accept($contact, $company, $package);
        [$other, $otherOwner] = $this->packageWorkspace('Synthetic Studio Two');
        $other->forceFill(['timezone' => 'UTC'])->save();
        $otherPackage = $this->publishedPackage($other, $otherOwner, $this->terms());
        $otherCompany = $this->company($other, 'Harbor Synthetic Florist');
        $theirs = $this->accept($this->contact($otherCompany), $otherCompany, $otherPackage);
        DB::table('client_agreements')->where('id', $theirs->client_agreement_id)->update(['square_series_key' => '000901']);

        $recurring = app(PackageRecurringInvoices::class);
        $this->assertSame([$mine->client_agreement_id], array_keys($recurring->automaticEnrollments($workspace)));
        $this->assertSame([$theirs->client_agreement_id], array_keys($recurring->automaticEnrollments($other)));

        // One workspace at a time, by its public id.
        $run = $this->issueRun(['--workspace' => $workspace->public_id]);
        $this->assertSame([$mine->public_id], array_column($run['outcomes'], 'enrollment_id'));
        $this->assertNull($theirs->refresh()->auto_invoice_outcome);

        // Another workspace's enrollment, agreement and period, asked here, do nothing.
        [[$enrollment, $agreement, $cycle]] = $recurring->due($other, $recurring->automaticEnrollments($other));
        $this->assertSame(AutoInvoiceResult::NOT_DUE, $recurring->invoice($workspace, $enrollment, $agreement, $cycle)->label());
        $this->assertNull($theirs->refresh()->auto_invoice_outcome);
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $other->id)->count());

        // Their refusal shows in their Needs you, never in ours.
        $this->issueRun(['--workspace' => $other->public_id], expectFailure: true);
        $this->assertSame('refused', $theirs->refresh()->auto_invoice_outcome);
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_auto_invoice_refused'));
        $this->assertNotNull(collect(app(WorkspaceActionQueue::class)->forManager($other))->firstWhere('kind', 'enrollment_auto_invoice_refused'));
        // Our record names only our invoice.
        $this->assertSame($workspace->id, ClientInvoice::query()->findOrFail($mine->refresh()->auto_invoice_id)->workspace_id);
    }

    // ── Read state for agents ───────────────────────────────────────────────

    /** enrollments.* says how invoices are made, when the next period begins and what the last run did; client_services.* only when. */
    #[Requirement('INV-1', 'INV-2')]
    public function test_the_enrollment_reads_say_how_invoices_are_made_and_what_the_last_run_did(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world();
        $enrollment = $this->accept($contact, $company, $package);
        $reads = app(PackageEnrollmentReadService::class);

        $before = $reads->get($owner, $workspace, $enrollment->public_id)['invoicing'];
        $this->assertSame(['issuing' => 'automatic', 'next_period_start' => '2026-12-01', 'last_automatic' => null], $before);

        $this->issueRun();
        $invoice = $this->invoiceFor($enrollment, '2026-11-01');
        $after = $reads->get($owner, $workspace, $enrollment->public_id)['invoicing']['last_automatic'];
        $this->assertSame('issued', $after['outcome']);
        $this->assertSame('2026-11-01', $after['period_start']);
        $this->assertNull($after['refusal']);
        $this->assertSame([$invoice->public_id, $invoice->invoice_number, 'issued'], [$after['invoice']['id'], $after['invoice']['number'], $after['invoice']['status']]);
        $this->assertStringEndsWith("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$invoice->public_id}", $after['invoice']['url']);

        DB::table('client_agreements')->where('id', $enrollment->client_agreement_id)->update(['square_series_key' => '000901']);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->issueRun(expectFailure: true);
        $refused = $reads->get($owner, $workspace, $enrollment->public_id)['invoicing'];
        $this->assertSame('2027-01-01', $refused['next_period_start']);
        $this->assertSame(['refused', '2026-12-01', null], [$refused['last_automatic']['outcome'], $refused['last_automatic']['period_start'], $refused['last_automatic']['invoice']]);
        $this->assertSame(['code' => 'collected_in_square', 'message' => 'Square collects this agreement, so SVC does not invoice it.', 'detail' => null], $refused['last_automatic']['refusal']);

        // The client reads when the next period begins, never the operator's setting.
        $service = app(ClientServiceReadService::class)->get($contact, $workspace, $enrollment->public_id);
        $this->assertSame('2027-01-01', $service['next_period_start']);
        $this->assertArrayNotHasKey('invoicing', $service);
        $this->assertArrayNotHasKey('invoice_issuing', $service['terms']);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $terms
     * @return array<string, mixed>
     */
    private function terms(array $terms = []): array
    {
        return [
            'invoice_issuing' => 'automatic', 'activation_mode' => 'automatic',
            'components' => [['kind' => 'recurring', 'description' => self::HOSTING, 'cadence' => 'monthly', 'amount' => 4500]],
            ...$terms,
        ];
    }

    /**
     * @param  array<string, mixed>  $terms  the package's terms
     * @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ClientCompany, 4: User}
     */
    private function world(array $terms = []): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, $this->terms($terms));
        $company = $this->company($workspace, 'Lakeside Synthetic Bakery');

        return [$workspace->refresh(), $owner, $package, $company, $this->contact($company)];
    }

    private function company(Workspace $workspace, string $name): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::uuid(),
            'billing_email' => 'billing@'.Str::slug($name).'.example.com',
        ]);
    }

    private function contact(ClientCompany $company): User
    {
        $user = User::factory()->create(['name' => 'Morgan Synthetic']);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => 'company']);

        return $user;
    }

    private function accept(User $contact, ClientCompany $company, ServicePackage $package): ServicePackageEnrollment
    {
        $workflow = app(PackageEnrollmentWorkflow::class);

        return $workflow->accept($contact, $company, $offer = $workflow->addFromPortal($contact, $company, $package), $this->signatureFor($offer))->refresh();
    }

    private function invoiceFor(ServicePackageEnrollment $enrollment, string $cycleStart): ClientInvoice
    {
        return ClientInvoice::query()->where('client_agreement_id', $enrollment->client_agreement_id)->whereDate('cycle_start', $cycleStart)->sole();
    }

    private function issuedCadenceInvoice(Workspace $workspace, ClientCompany $company, ?ClientAgreement $agreement, string $number, string $from, string $to, string $cycleStart): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement?->id, 'invoice_number' => $number,
            'invoice_kind' => 'cadence_period', 'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'balance_amount' => 100,
            'service_period_start' => $from, 'service_period_end' => $to, 'cycle_start' => $cycleStart, 'cycle_end' => CarbonImmutable::parse($cycleStart)->endOfMonth()->toDateString(),
            'issue_date' => $cycleStart, 'issued_at' => now(), 'hours_billed_at_rate' => 0,
        ]);
    }

    /** @return list<array{string, int}> type and total of each line, in order */
    private function lines(ClientInvoice $invoice): array
    {
        return ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (ClientInvoiceLine $line): array => [(string) $line->type, (int) $line->total_amount])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{dry_run: bool, executed: bool, note: string|null, outcomes: list<array{workspace_id: string, enrollment_id: string, period_start: string, outcome: string, refusal: string|null, invoice_number: string|null}>, not_reached: int}
     */
    private function issueRun(array $options = [], bool $apply = true, bool $expectFailure = false): array
    {
        $exit = Artisan::call('svc:packages:issue-invoices', [...($apply ? ['--apply' => true] : []), '--format' => 'json', ...$options]);
        $output = trim(Artisan::output());
        $this->assertSame($expectFailure ? 1 : 0, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{outcomes: list<array{period_start: string, outcome: string}>}  $run
     * @return list<array{period_start: string, outcome: string}>
     */
    private function brief(array $run): array
    {
        return array_map(static fn (array $row): array => ['period_start' => $row['period_start'], 'outcome' => $row['outcome']], $run['outcomes']);
    }
}
