<?php

namespace Tests\Feature\ServicePackages;

use App\Exceptions\PackageMigrationRefused;
use App\Mail\ServicePackageNoticeMail;
use App\Models\AgreementCancellationRequest;
use App\Models\AgreementScheduledTermination;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageMigration;
use App\Models\ServicePackageMigrationTarget;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Engagement\AgreementCancellations;
use App\Services\Packages\ClientServiceReadService;
use App\Services\Packages\PackageEnrollmentReadService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\PackageMigrationPlanner;
use App\Services\Packages\PackageMigrationReadService;
use App\Services\Packages\PackageMigrationWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Packages\Migration\MigrationRefusal;
use App\Support\Packages\Migration\MigrationTermChanges;
use App\Support\Packages\PackageTerms;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsServicePackages;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Moving subscribers to a new package version, from the domain down
 * (docs/service-packages.md, slice 6: MIG-1 to MIG-5): the dates a move may
 * take effect, scheduling it with its notices, carrying it out on the date
 * (a new agreement term from the effective date, the old one ending the day
 * before), skipping and holding, autopay never raised, and cancelling it.
 *
 * The clock starts at 9 November 2026, 12:00 UTC, in a UTC workspace. The
 * package is a monthly hosting service at $45.00, 30 days' notice,
 * self-serve cancellation, issued automatically; version 2 charges $50.00.
 */
final class PackageMigrationTest extends TestCase
{
    use BuildsServicePackages;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true]);
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
    }

    // ── MIG-1: who, when, from what to what ─────────────────────────────────

    #[Requirement('MIG-1')]
    public function test_the_earliest_date_is_the_period_start_after_the_notice_and_every_later_one_is_offered(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');

        $preview = $this->preview($owner, $workspace, $package, $from);

        // Nov 9 + 30 days is Dec 9; December closes Dec 31, so Jan 1 is the first start.
        $this->assertSame('2027-01-01', $preview['effective_on']);
        $this->assertSame(['2027-01-01', '2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01'], array_column($preview['effective_dates'], 'date'));
        $this->assertSame([1, 2], [$preview['from_version']['number'], $preview['to_version']['number']]);
        $subscriber = $preview['subscribers'][0];
        $this->assertSame([$enrollment->public_id, '2027-01-01', null], [$subscriber['enrollment_id'], $subscriber['earliest_effective_on'], $subscriber['refusal']]);
        $this->assertSame(['amount' => 5000, 'currency' => 'USD', 'label' => '$50.00'], $preview['new_price']);
        $price = array_column($preview['changes'], null, 'term')['cycle_amount'];
        $this->assertSame(['$45.00 a month', '$50.00 a month'], [$price['from'], $price['to']]);

        // Asked for December, the subscriber is refused: inside its notice.
        $december = $this->preview($owner, $workspace, $package, $from, '2026-12-01');
        $this->assertSame(MigrationRefusal::DateNotOffered->value, $december['subscribers'][0]['refusal']['code']);
    }

    #[Requirement('MIG-1')]
    public function test_a_quarterly_agreement_moves_at_one_of_its_own_period_starts(): void
    {
        [$workspace, $owner, $package, $from] = $this->world([
            'billing_cadence' => 'quarterly',
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'quarterly', 'amount' => 12000]],
        ], ['components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'quarterly', 'amount' => 13500]]]);
        $this->subscriber($workspace, $package, 'Lakeside');

        $preview = $this->preview($owner, $workspace, $package, $from);

        // Quarters from Nov 9: Dec 9 falls in Nov 9 - Feb 8, so Feb 9 is the first start.
        $this->assertSame(['2027-02-09', '2027-05-09', '2027-08-09'], array_slice($preview['subscribers'][0]['effective_dates'], 0, 3));
        $this->expectRefusal(MigrationRefusal::DateNotOffered, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-02-01'));
    }

    #[Requirement('MIG-1')]
    public function test_a_period_already_invoiced_is_never_moved_under_its_invoice(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['notice_days' => 0]);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->assertSame('2026-12-01', $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['earliest_effective_on']);

        // December sold and issued by hand already: the move starts after it.
        $this->invoice($enrollment, '2026-12-01', 'issued');
        $this->assertSame('2027-01-01', $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['earliest_effective_on']);
        // A draft does not hold it back: it is discarded on the date.
        $this->assertSame('2027-01-01', $this->preview($owner, $workspace, $package, $from)['effective_on']);
    }

    #[Requirement('MIG-1')]
    public function test_scheduling_refuses_what_the_rules_refuse_and_writes_nothing(): void
    {
        [$workspace, $owner, $package, $from, $to] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');

        $this->expectRefusal(MigrationRefusal::DateNotOffered, fn () => $this->schedule($owner, $workspace, $package, $from, '2026-12-01'));
        $this->expectRefusal(MigrationRefusal::NotNewer, fn () => $this->schedule($owner, $workspace, $package, $to, '2027-01-01'));
        $this->expectRefusal(MigrationRefusal::NoSubscribers, fn () => app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', [], null));
        $this->expectRefusal(MigrationRefusal::NotASubscriber, fn () => app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', [(string) Str::uuid()], null));
        $this->assertStatus(409, fn () => app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', [$enrollment->public_id], null, str_repeat('0', 64)));
        $member = $this->workspaceMember($workspace, 'member');
        $this->assertStatus(404, fn () => $this->schedule($member, $workspace, $package, $from, '2027-01-01'));
        $this->assertSame(0, ServicePackageMigration::query()->count());
        Mail::assertNothingSent();

        $this->schedule($owner, $workspace, $package, $from, '2027-01-01', expectedVersion: AgentApiVersion::for($package->refresh()));
        // Once in a scheduled move, a subscriber is in no other.
        $this->expectRefusal(MigrationRefusal::AlreadyScheduled, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-02-01'));
        $this->assertSame(1, ServicePackageMigration::query()->count());
    }

    // ── MIG-2: the client is told ───────────────────────────────────────────

    #[Requirement('MIG-2')]
    public function test_scheduling_emails_each_client_and_shows_the_portal_notice_with_the_old_and_new_terms(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->subscriber($workspace, $package, 'Riverside');

        $migration = app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01',
            $this->publicIds(), 'Our hosting provider raised its prices.');

        Mail::assertSent(ServicePackageNoticeMail::class, 2);
        $lakeside = $this->contactOf(ClientCompany::query()->findOrFail($enrollment->client_company_id));
        Mail::assertSent(ServicePackageNoticeMail::class, function (ServicePackageNoticeMail $mail) use ($lakeside): bool {
            return $mail->hasTo($lakeside->email)
                && $mail->subjectLine === 'Web hosting · Essentials: new terms from Jan 1, 2027'
                && in_array('Price: $45.00 a month → $50.00 a month', $mail->paragraphs, true)
                && in_array('Synthetic Package Studio\'s note: Our hosting provider raised its prices.', $mail->paragraphs, true);
        });
        $this->assertSame(['scheduled', '2027-01-01', 2], [$migration->status, $migration->effective_on->toDateString(), ServicePackageMigrationTarget::query()->count()]);
        $this->assertSame(2, ClientCompanyActivity::query()->where('action', 'agreement.move_scheduled')->count());

        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $service = app(ClientServiceReadService::class)->forPortal($this->contactOf($company), $company)['services'][0];
        $this->assertSame('2027-01-01', $service['move']['effective_on']);
        $this->assertSame(2, $service['move']['version_number']);
        $this->assertSame('Our hosting provider raised its prices.', $service['move']['message']);
        $this->assertSame('$50.00 a month', array_column($service['move']['changes'], 'to', 'term')['cycle_amount']);
        $this->assertSame(5000, $service['move']['terms']['cycle_amount']);
        // The operator's view says the same.
        $move = app(PackageEnrollmentReadService::class)->get($owner, $workspace, $enrollment->public_id)['move'];
        $this->assertSame(['scheduled', '2027-01-01', 2, null], [$move['status'], $move['effective_on'], $move['to_version']['number'], $move['outcome']]);
    }

    #[Requirement('MIG-2')]
    public function test_a_rolled_back_schedule_sends_no_email(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $this->subscriber($workspace, $package, 'Lakeside');

        try {
            DB::transaction(function () use ($owner, $workspace, $package, $from): void {
                $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        Mail::assertNothingSent();
        $this->assertSame(0, ServicePackageMigration::query()->count());
    }

    // ── MIG-3: on the date ──────────────────────────────────────────────────

    #[Requirement('MIG-3')]
    public function test_on_the_date_the_agreement_ends_the_day_before_and_a_new_term_carries_the_new_version(): void
    {
        [$workspace, $owner, $package, $from, $to] = $this->world(['minimum_term_months' => 12]);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');

        $this->travelTo(CarbonImmutable::parse('2026-12-31 23:00:00', 'UTC'));
        $this->assertSame([], $this->moveRun()['outcomes'], 'not before its date');
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));

        $old->refresh();
        $enrollment->refresh();
        $successor = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->assertSame(['terminated', '2026-12-31'], [$old->status, $old->ends_on?->toDateString()]);
        $this->assertNotNull($old->terminated_at);
        $this->assertNotSame($old->id, $successor->id);
        $this->assertSame(['active', '2027-01-01', $old->client_project_id, 30, 'self_serve'], [$successor->status, $successor->starts_on->toDateString(), $successor->client_project_id, $successor->notice_days, $successor->cancellation_mode]);
        // Twelve months from Nov 9, 2026 run to Nov 8, 2027: from Jan 1, eleven months cover it. Never a new twelve.
        $this->assertSame(11, $successor->minimum_term_months);
        $this->assertSame([5000], ClientAgreementRecurringItem::query()->where('client_agreement_id', $successor->id)->pluck('amount')->all());
        $this->assertSame([4500], ClientAgreementRecurringItem::query()->where('client_agreement_id', $old->id)->pluck('amount')->all());
        // The service now carries version 2; what was signed is never changed.
        $this->assertSame([$to->id, $from->id], [$enrollment->current_version_id, $enrollment->accepted_version_id]);
        $target = ServicePackageMigrationTarget::query()->sole();
        $this->assertSame(['applied', $successor->id, null], [$target->status, $target->successor_agreement_id, $target->outcome_code]);
        $this->assertSame('completed', $migration->refresh()->status);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'agreement.moved')->count());

        // Applying again changes nothing.
        $this->assertSame([], $this->moveRun()['outcomes']);
        $this->assertSame(2, ClientAgreement::query()->where('client_project_id', $old->client_project_id)->count());
    }

    #[Requirement('MIG-3')]
    public function test_automatic_invoices_bill_each_period_once_on_the_terms_in_force_for_it(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->issueRun();
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->issueRun();

        // On Jan 1, invoicing that runs before the move waits for it.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:10:00', 'UTC'));
        $this->assertSame(['waits_for_move'], array_column($this->issueRun()['outcomes'], 'outcome'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame(['issued'], array_column($this->issueRun()['outcomes'], 'outcome'));
        $this->assertSame([], $this->issueRun()['outcomes']);

        $invoices = ClientInvoice::query()->where('workspace_id', $workspace->id)->orderBy('cycle_start')->get();
        $this->assertSame([['2026-11-01', 4500], ['2026-12-01', 4500], ['2027-01-01', 5000]],
            $invoices->map(fn (ClientInvoice $invoice): array => [$invoice->cycle_start?->toDateString(), (int) $invoice->total_amount])->all());
        $this->assertSame($enrollment->refresh()->client_agreement_id, $invoices->last()?->client_agreement_id);
    }

    #[Requirement('MIG-3')]
    public function test_a_setup_fee_billed_with_the_first_cycle_is_never_charged_again_on_a_move(): void
    {
        $withSetupFee = ['charge_setup_fee_at_signup' => false, 'components' => [
            ['kind' => 'setup_fee', 'description' => 'Onboarding setup', 'amount' => 20000],
            ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5000],
        ]];
        [$workspace, $owner, $package, $from] = $this->world([], $withSetupFee);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->issueRun();
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->issueRun();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame(['issued'], array_column($this->issueRun()['outcomes'], 'outcome'));

        // The subscriber set up long ago: the successor carries only what recurs.
        $successor = $enrollment->refresh()->client_agreement_id;
        $this->assertSame(['Managed hosting'], ClientAgreementRecurringItem::query()->where('client_agreement_id', $successor)->pluck('description')->all());
        $january = ClientInvoice::query()->where('client_agreement_id', $successor)->sole();
        $this->assertSame([['Managed hosting', 5000]], $january->lines->where('total_amount', '>', 0)->map(fn ($line): array => [$line->description, $line->total_amount])->values()->all());
        $this->assertNotContains('Onboarding setup', $january->lines->pluck('description')->all());
        $this->assertSame(5000, (int) $january->total_amount);
        $this->assertArrayNotHasKey('setup_fee', array_column(MigrationTermChanges::forClient(
            PackageTerms::fromVersion(ServicePackageVersion::query()->findOrFail($from->id)), PackageTerms::fromVersion(ServicePackageVersion::query()->findOrFail($package->refresh()->published_version_id)),
        ), null, 'term'));

        // A new client signing up to the same version still pays it, with its first cycle.
        $newcomer = $this->subscriber($workspace, $package, 'Newcomer', onPublished: true);
        $this->assertContains('Onboarding setup', ClientAgreementRecurringItem::query()->where('client_agreement_id', $newcomer->client_agreement_id)->pluck('description')->all());
    }

    #[Requirement('MIG-3')]
    public function test_a_client_who_cancels_before_the_date_is_skipped_and_the_skip_recorded(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $agreement, '2026-12-31', null);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        Artisan::call('svc:agreements:terminate-scheduled', ['--apply' => true]);
        $this->assertSame(['skipped'], array_column($this->moveRun()['outcomes'], 'outcome'));

        $target = ServicePackageMigrationTarget::query()->sole();
        $this->assertSame(['skipped', 'agreement_ended'], [$target->status, $target->outcome_code]);
        $this->assertSame('completed', $migration->refresh()->status);
        $this->assertSame([$agreement->id, null], [$enrollment->refresh()->client_agreement_id, $enrollment->current_version_id]);
        $this->assertSame(1, ClientAgreement::query()->where('workspace_id', $workspace->id)->count());
    }

    #[Requirement('MIG-3')]
    public function test_an_end_scheduled_after_the_date_moves_with_the_service(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $agreement, '2027-02-28', null);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $successor = ClientAgreement::query()->findOrFail($enrollment->refresh()->client_agreement_id);
        $this->assertSame('2027-02-28', $successor->ends_on?->toDateString());
        $end = AgreementScheduledTermination::query()->sole();
        $this->assertSame([$successor->id, 'scheduled'], [$end->client_agreement_id, $end->status]);

        $this->travelTo(CarbonImmutable::parse('2027-02-28 06:00:00', 'UTC'));
        Artisan::call('svc:agreements:terminate-scheduled', ['--apply' => true]);
        $this->assertSame('terminated', $successor->refresh()->status);
    }

    #[Requirement('MIG-3')]
    public function test_a_period_invoiced_after_scheduling_holds_the_move_until_an_owner_settles_it(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $january = $this->invoice($enrollment, '2027-01-01', 'issued');

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $target = ServicePackageMigrationTarget::query()->sole();
        $this->assertSame(['scheduled', 'period_invoiced'], [$target->status, $target->outcome_code]);
        $this->assertSame('active', ClientAgreement::query()->findOrFail($enrollment->client_agreement_id)->status, 'nothing applied');
        $rows = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_held');
        $this->assertCount(1, $rows);
        // Held again: the record is not rewritten every hour.
        $version = $target->lock_version;
        $this->moveRun();
        $this->assertSame($version, $target->refresh()->lock_version);

        $january->forceFill(['status' => 'void'])->save();
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame([], collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_held')->all());
    }

    #[Requirement('MIG-3')]
    public function test_a_draft_for_a_period_after_the_date_is_discarded_and_billed_on_the_new_terms(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['invoice_issuing' => 'draft']);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->travelTo(CarbonImmutable::parse('2026-12-20 12:00:00', 'UTC'));
        $draft = app(ClientInvoicingService::class)->generateCadencePeriod($company, $old, Carbon::parse('2027-01-01'));
        $this->assertSame(4500, (int) $draft->total_amount);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame('void', $draft->refresh()->status);
        $successor = ClientAgreement::query()->findOrFail($enrollment->refresh()->client_agreement_id);
        $this->assertSame(5000, (int) app(ClientInvoicingService::class)->generateCadencePeriod($company, $successor, Carbon::parse('2027-01-01'))->total_amount);
    }

    #[Requirement('MIG-3')]
    public function test_a_client_asking_to_leave_before_the_date_holds_the_move_until_answered(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['cancellation_mode' => 'request']);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $request = app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $agreement, '2026-12-31', null);
        $this->assertInstanceOf(AgreementCancellationRequest::class, $request);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame('cancellation_pending', ServicePackageMigrationTarget::query()->sole()->outcome_code);

        app(AgreementCancellations::class)->decline($owner, $workspace, $request->refresh(), 'Synthetic note');
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
    }

    #[Requirement('MIG-3')]
    public function test_the_command_is_scheduled_hourly_and_does_nothing_while_the_feature_is_off(): void
    {
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'svc:packages:apply-migrations --apply'));
        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);

        [$workspace, $owner, $package, $from] = $this->world();
        $this->subscriber($workspace, $package, 'Lakeside');
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        config(['svc.service_packages.enabled' => false]);
        $this->assertSame([], $this->moveRun()['outcomes']);
        $this->assertSame('scheduled', ServicePackageMigrationTarget::query()->sole()->status);

        // Without --apply, nothing is kept.
        config(['svc.service_packages.enabled' => true]);
        $this->assertSame(['applied'], array_column($this->moveRun(apply: false)['outcomes'], 'outcome'));
        $this->assertSame('scheduled', ServicePackageMigrationTarget::query()->sole()->status);
    }

    #[Requirement('MIG-3')]
    public function test_every_due_subscriber_is_reached_whatever_the_page_size(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        foreach (['Lakeside', 'Riverside', 'Hillside'] as $name) {
            $this->subscriber($workspace, $package, $name);
        }
        app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package, $from->public_id, '2027-01-01', $this->publicIds(), null);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied', 'applied', 'applied'], array_column($this->moveRun(['--page' => 1])['outcomes'], 'outcome'));
    }

    // ── MIG-4: autopay is never raised ──────────────────────────────────────

    #[Requirement('MIG-4')]
    public function test_an_autopay_limit_below_the_new_price_is_flagged_and_never_raised(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $company->forceFill(['autopay_enabled_at' => now(), 'autopay_limit_amount' => 4800])->save();

        $subscriber = $this->preview($owner, $workspace, $package, $from)['subscribers'][0];
        $this->assertSame([4800, true], [$subscriber['autopay_limit_amount'], $subscriber['autopay_below_price']]);

        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => str_contains(implode(' ', $mail->paragraphs), 'Your autopay limit is $48.00, below the new price of $50.00'));
        $rows = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_autopay')->values();
        $this->assertSame([5000, '2027-01-01'], [$rows[0]['amount'], $rows[0]['date']]);
        $this->assertTrue(app(ClientServiceReadService::class)->forPortal($this->contactOf($company), $company)['services'][0]['move']['autopay_below_price']);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->moveRun();
        $this->assertSame(4800, $company->refresh()->autopay_limit_amount);
    }

    // ── MIG-5: cancelling before the date ───────────────────────────────────

    #[Requirement('MIG-5')]
    public function test_a_move_can_be_cancelled_before_its_date_which_tells_each_client_again(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $this->subscriber($workspace, $package, 'Lakeside');
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        Mail::fake();

        $cancelled = app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration, AgentApiVersion::for($migration->refresh()));

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('cancelled', ServicePackageMigrationTarget::query()->sole()->status);
        Mail::assertSent(ServicePackageNoticeMail::class, fn (ServicePackageNoticeMail $mail): bool => $mail->subjectLine === 'Web hosting · Essentials: the change of terms is cancelled');
        $this->expectRefusal(MigrationRefusal::NotScheduled, fn () => app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration));

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame([], $this->moveRun()['outcomes']);
        // The subscriber may be moved again.
        $this->assertNull(app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package, $from->public_id, null)['subscribers'][0]['refusal']);
    }

    #[Requirement('MIG-5')]
    public function test_from_its_date_cancelling_stops_only_the_subscribers_still_waiting(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $moved = $this->subscriber($workspace, $package, 'Lakeside');
        $waiting = $this->subscriber($workspace, $package, 'Riverside');
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $this->invoice($waiting, '2027-01-01', 'issued');

        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['applied', 'held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        Mail::fake();
        $cancelled = app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration);

        $this->assertSame('completed', $cancelled->status, 'the one moved stays moved');
        $this->assertSame(['applied', 'cancelled'], ServicePackageMigrationTarget::query()->orderBy('id')->pluck('status')->all());
        $this->assertNotNull($moved->refresh()->current_version_id);
        $this->assertNull($waiting->refresh()->current_version_id);
        Mail::assertSent(ServicePackageNoticeMail::class, 1);
        $this->assertSame([], collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_held')->all());
    }

    #[Requirement('MIG-1')]
    public function test_an_agreement_billing_by_usage_is_refused(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['invoice_issuing' => 'draft', 'hourly_rate_amount' => 12000],
            ['hourly_rate_amount' => 15000]);
        $this->subscriber($workspace, $package, 'Lakeside');

        $this->assertSame(MigrationRefusal::BillsByUsage->value, $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['refusal']['code']);
        $this->expectRefusal(MigrationRefusal::BillsByUsage, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));
    }

    #[Requirement('MIG-1')]
    public function test_a_move_that_changes_the_billing_cadence_is_refused(): void
    {
        // Monthly to quarterly on Jan 1 with Nov and Dec issued: the successor's
        // first quarter would be placed on Oct-Dec, already sold, and never invoiced.
        foreach ([['monthly', 'quarterly'], ['monthly', 'semi_annual'], ['monthly', 'annual'], ['quarterly', 'monthly'], ['annual', 'quarterly']] as [$was, $becomes]) {
            [$workspace, $owner, $package, $from] = $this->world(
                ['billing_cadence' => $was, 'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => $was, 'amount' => 4500]]],
                ['billing_cadence' => $becomes, 'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => $becomes, 'amount' => 5000]]],
            );
            $this->subscriber($workspace, $package, 'Lakeside '.$becomes);

            $preview = $this->preview($owner, $workspace, $package, $from);
            $this->assertSame(MigrationRefusal::ChangesCadence->value, $preview['refusal']['code'] ?? null, "{$was} to {$becomes}");
            $this->assertSame([[], null], [$preview['subscribers'], $preview['effective_on']]);
            $this->expectRefusal(MigrationRefusal::ChangesCadence, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));
            $this->assertSame(0, ServicePackageMigration::query()->where('workspace_id', $workspace->id)->count());
        }
    }

    #[Requirement('MIG-1', 'MIG-3')]
    public function test_an_agreement_whose_own_cadence_differs_from_the_new_version_is_refused_and_held(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $edited = $this->subscriber($workspace, $package, 'Edited');
        // An operator moved this one agreement to quarterly billing by hand.
        ClientAgreement::query()->whereKey($edited->client_agreement_id)->update(['billing_cadence' => 'quarterly']);

        $this->assertSame(MigrationRefusal::ChangesCadence->value, $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['refusal']['code'] ?? null);
        $this->expectRefusal(MigrationRefusal::ChangesCadence, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));

        // Changed after scheduling, the move waits for an owner rather than leave a period unbilled.
        ClientAgreement::query()->whereKey($edited->client_agreement_id)->update(['billing_cadence' => 'monthly']);
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        ClientAgreement::query()->whereKey($edited->client_agreement_id)->update(['billing_cadence' => 'quarterly']);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $target = ServicePackageMigrationTarget::query()->sole();
        $this->assertSame(['scheduled', 'cadence_changed', null], [$target->status, $target->outcome_code, $target->successor_agreement_id]);
        $this->assertSame(['active', 1], [ClientAgreement::query()->findOrFail($edited->client_agreement_id)->status, ClientAgreement::query()->where('workspace_id', $workspace->id)->count()]);
    }

    #[Requirement('MIG-1', 'MIG-3', 'INV-3')]
    public function test_an_agreement_square_collects_is_refused_and_one_linked_after_scheduling_is_held_untouched(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $square = ['square_series_key' => '000901', 'square_location_id' => 'SYNTEST-LOCATION-1'];
        ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->update($square);

        $this->assertSame(MigrationRefusal::SquareCollected->value, $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['refusal']['code'] ?? null);
        $this->expectRefusal(MigrationRefusal::SquareCollected, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));
        $this->assertSame(0, ServicePackageMigration::query()->count());

        // Linked to Square after the move was scheduled: held, and nothing about the agreement changes.
        ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->update(['square_series_key' => null, 'square_location_id' => null]);
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->update($square);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $target = ServicePackageMigrationTarget::query()->sole();
        $recorded = $target->outcome_recorded_at;
        $this->assertSame(['scheduled', 'square_collected'], [$target->status, $target->outcome_code]);
        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->assertSame(['active', null, '000901'], [$agreement->status, $agreement->ends_on?->toDateString(), $agreement->square_series_key]);
        $this->assertSame(1, ClientAgreement::query()->where('workspace_id', $workspace->id)->count());
        // Every hourly run asks again and records nothing new.
        $this->travelTo(CarbonImmutable::parse('2027-01-01 01:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertEquals($recorded, $target->refresh()->outcome_recorded_at);
    }

    #[Requirement('MIG-3', 'INV-1')]
    public function test_a_move_still_held_when_its_first_period_ends_waits_for_an_owner_and_every_period_is_billed(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['cancellation_mode' => 'request']);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->issueRun();
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $request = app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $old, '2026-12-31', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->issueRun();

        // All January the move waits for the request, and so does January's invoice.
        foreach (['2027-01-01 00:30:00', '2027-01-31 23:30:00'] as $at) {
            $this->travelTo(CarbonImmutable::parse($at, 'UTC'));
            $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
            $this->assertSame([['2027-01-01', 'waits_for_move']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        }

        // Declined on Feb 1: January has ended, so the move is not backdated
        // into it; the current terms bill January, late, and February.
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:30:00', 'UTC'));
        app(AgreementCancellations::class)->decline($owner, $workspace, $request->refresh(), null);
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $target = ServicePackageMigrationTarget::query()->sole();
        $this->assertSame(['scheduled', 'effective_date_passed', null], [$target->status, $target->outcome_code, $target->successor_agreement_id]);
        $this->assertSame([['2027-01-01', 'issued'], ['2027-02-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame([$old->id, 'active', 1], [$enrollment->refresh()->client_agreement_id, $old->refresh()->status, ClientAgreement::query()->where('workspace_id', $workspace->id)->count()]);
        $this->assertSame([['2026-11-01', 4500], ['2026-12-01', 4500], ['2027-01-01', 4500], ['2027-02-01', 4500]], ClientInvoice::query()->where('workspace_id', $workspace->id)->orderBy('cycle_start')->get()
            ->map(fn (ClientInvoice $invoice): array => [$invoice->cycle_start?->toDateString(), (int) $invoice->total_amount])->all());
        $this->assertCount(1, collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_held'));

        // The owner's way out: cancel it and schedule another.
        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, ServicePackageMigration::query()->sole());
        $this->assertSame('2027-04-01', $this->preview($owner, $workspace, $package, $from)['effective_on']);
    }

    #[Requirement('MIG-3', 'MIG-5', 'INV-1')]
    public function test_a_period_that_waited_for_a_move_stays_owed_when_the_move_is_cancelled_after_it(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['cancellation_mode' => 'request']);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->issueRun();
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $request = app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $old, '2026-12-31', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->issueRun();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame([['2027-01-01', 'waits_for_move']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));

        // Feb 1, before any run: the owner follows the advice and cancels the move.
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:05:00', 'UTC'));
        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration->refresh());
        $this->assertSame(['cancelled', 'cancelled'], [$migration->refresh()->status, ServicePackageMigrationTarget::query()->sole()->status]);
        app(AgreementCancellations::class)->decline($owner, $workspace, $request->refresh(), null);

        // January waited for the move; it is still owed, on the terms in force for it.
        $this->assertSame([['2027-01-01', 'issued'], ['2027-02-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->assertSame([], $this->issueRun()['outcomes']);
        $this->assertSame([['2026-11-01', 4500], ['2026-12-01', 4500], ['2027-01-01', 4500], ['2027-02-01', 4500]], ClientInvoice::query()->where('workspace_id', $workspace->id)->orderBy('cycle_start')->get()
            ->map(fn (ClientInvoice $invoice): array => [$invoice->cycle_start?->toDateString(), (int) $invoice->total_amount])->all());

        // Rescheduled: the new move takes effect after what is invoiced, and January stays billed once.
        $next = app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package->refresh(), $from->public_id, '2027-04-01', [$enrollment->public_id], null);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 00:30:00', 'UTC'));
        $this->assertSame([['2027-03-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->travelTo(CarbonImmutable::parse('2027-04-01 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame([['2027-04-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->assertSame('completed', $next->refresh()->status);
        $this->assertSame([4500, 4500, 4500, 4500, 4500, 5000], ClientInvoice::query()->where('workspace_id', $workspace->id)->orderBy('cycle_start')->pluck('total_amount')->map(fn (mixed $amount): int => (int) $amount)->all());
    }

    #[Requirement('MIG-3', 'MIG-5', 'INV-1')]
    public function test_a_replacement_move_waits_while_a_deferred_period_is_still_unbilled(): void
    {
        [$workspace, $owner, $package, $from] = $this->world(['cancellation_mode' => 'request']);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
        $old = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $this->issueRun();
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $request = app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $old, '2026-12-31', null);
        $this->travelTo(CarbonImmutable::parse('2026-12-01 00:30:00', 'UTC'));
        $this->issueRun();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->issueRun();

        // Feb 1: cancelled, and January's late invoice is refused - an ended
        // company-wide agreement covered December's work dates.
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:05:00', 'UTC'));
        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration->refresh());
        app(AgreementCancellations::class)->decline($owner, $workspace, $request->refresh(), null);
        $companyWide = ClientAgreement::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_project_id' => null, 'title' => 'Synthetic retired retainer',
            'status' => 'terminated', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-15',
        ]);
        $this->assertSame([['2027-01-01', 'refused', 'company_wide_agreement'], ['2027-02-01', 'issued', null]],
            array_map(fn (array $row): array => [$row['period_start'], $row['outcome'], $row['refusal']], $this->issueRun(refusing: true)['outcomes']));
        $next = app(PackageMigrationWorkflow::class)->schedule($owner, $workspace, $package->refresh(), $from->public_id, '2027-04-01', [$enrollment->public_id], null);
        $this->travelTo(CarbonImmutable::parse('2027-03-01 00:30:00', 'UTC'));
        $this->assertSame([['2027-01-01', 'refused'], ['2027-03-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun(refusing: true)['outcomes']));

        // Apr 1: the replacement would end the agreement that owes January. It waits.
        $this->travelTo(CarbonImmutable::parse('2027-04-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $target = ServicePackageMigrationTarget::query()->where('service_package_migration_id', $next->id)->sole();
        $this->assertSame(['scheduled', 'owed_period_unbilled'], [$target->status, $target->outcome_code]);
        $this->assertStringContainsString('2027-01-01', (string) $target->outcome_detail);
        $this->assertSame($old->id, $enrollment->refresh()->client_agreement_id);
        $this->assertCount(1, collect(app(WorkspaceActionQueue::class)->forManager($workspace))->where('kind', 'package_move_held'));

        // Resolved: January is invoiced on the terms in force for it, then the move applies.
        $companyWide->delete();
        $this->assertSame([['2027-01-01', 'issued'], ['2027-04-01', 'waits_for_move']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame([['2027-04-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        $this->assertSame([['2026-11-01', 4500], ['2026-12-01', 4500], ['2027-01-01', 4500], ['2027-02-01', 4500], ['2027-03-01', 4500], ['2027-04-01', 5000]],
            ClientInvoice::query()->where('workspace_id', $workspace->id)->where('status', '!=', 'void')->orderBy('cycle_start')->get()
                ->map(fn (ClientInvoice $invoice): array => [$invoice->cycle_start?->toDateString(), (int) $invoice->total_amount])->all());
    }

    #[Requirement('MIG-3', 'MIG-5')]
    public function test_a_move_cancelled_before_its_date_owes_nothing_late(): void
    {
        // Cancelled before the date, nothing ever waited: January is the
        // ordinary current period on Jan 1, and an owner who voided it is not
        // overruled by a late bill.
        [$workspace, $owner, $package, $from] = $this->world();
        $this->subscriber($workspace, $package, 'Lakeside');
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        app(PackageMigrationWorkflow::class)->cancel($owner, $workspace, $migration);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame([['2027-01-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
        ClientInvoice::query()->where('workspace_id', $workspace->id)->whereDate('cycle_start', '2027-01-01')->update(['status' => 'void']);
        $this->travelTo(CarbonImmutable::parse('2027-02-01 00:30:00', 'UTC'));
        $this->assertSame([['2027-02-01', 'issued']], array_map(fn (array $row): array => [$row['period_start'], $row['outcome']], $this->issueRun()['outcomes']));
    }

    #[Requirement('MIG-3')]
    public function test_a_continuing_charge_keeps_its_schedule_across_a_move(): void
    {
        // A quarterly service started Nov 9: its $120 charges fall on Nov 9,
        // Feb 1, May 1. Moved on Feb 9, the next charge is still May 1 - never
        // a fresh one on Feb 9 as well.
        $quarterly = ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'quarterly', 'amount' => 12000];
        [$workspace, $owner, $package, $from] = $this->world(['billing_cadence' => 'quarterly', 'components' => [$quarterly]],
            ['billing_cadence' => 'quarterly', 'summary' => 'Revised synthetic summary', 'components' => [$quarterly]]);
        $enrollment = $this->subscriber($workspace, $package, 'Lakeside');
        $this->issueRun();
        $this->schedule($owner, $workspace, $package, $from, '2027-02-09');
        $this->travelTo(CarbonImmutable::parse('2027-02-09 00:30:00', 'UTC'));
        $this->assertSame(['applied'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame(['issued'], array_column($this->issueRun()['outcomes'], 'outcome'));

        $successor = $enrollment->refresh()->client_agreement_id;
        $first = ClientInvoice::query()->where('client_agreement_id', $successor)->sole();
        $this->assertSame([['2027-05-01', 12000]], $first->lines->where('total_amount', '>', 0)
            ->map(fn ($line): array => [$line->line_date?->toDateString(), $line->total_amount])->values()->all());
        $item = ClientAgreementRecurringItem::query()->where('client_agreement_id', $successor)->sole();
        $this->assertSame(['2026-11-09', 1, 11], [$item->start_date?->toDateString(), $item->anchor_day, $item->anchor_month]);
    }

    #[Requirement('MIG-1', 'MIG-3')]
    public function test_recurring_charges_off_one_schedule_are_refused_and_held(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Edited');
        $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
        $addOn = fn (): ClientAgreementRecurringItem => $agreement->recurringItems()->create([
            'workspace_id' => $workspace->id, 'description' => 'Synthetic add-on', 'cadence' => 'monthly', 'amount' => 1000, 'quantity' => '1', 'anchor_day' => 15,
            'currency' => 'USD', 'is_taxable' => false, 'is_active' => true, 'sort_order' => 5,
        ]);
        // An item charging on the 15th beside one on the 1st: no one schedule to continue.
        $added = $addOn();
        $this->assertSame(MigrationRefusal::AmbiguousRecurrence->value, $this->preview($owner, $workspace, $package, $from)['subscribers'][0]['refusal']['code'] ?? null);
        $this->expectRefusal(MigrationRefusal::AmbiguousRecurrence, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));

        // Added after scheduling, it holds the move on its date.
        $added->delete();
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        $addOn();
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame('ambiguous_recurrence', ServicePackageMigrationTarget::query()->sole()->outcome_code);
        $this->assertSame('active', $agreement->refresh()->status);
    }

    #[Requirement('MIG-1', 'MIG-3')]
    public function test_a_move_where_a_recurring_charge_bills_off_the_service_cycle_is_refused(): void
    {
        $hosting = ['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500];
        $licence = ['kind' => 'recurring', 'description' => 'Annual licence', 'cadence' => 'annual', 'amount' => 12000];
        $once = ['kind' => 'recurring', 'description' => 'Site audit', 'cadence' => 'one_time', 'amount' => 9000];
        // An annual licence on a monthly service: November was $165, December $45,
        // and a successor would have charged the licence again in January.
        foreach ([
            'on both versions' => [[$hosting, $licence], [[...$hosting, 'amount' => 5000], $licence]],
            'only on the current version' => [[$hosting, $licence], [[...$hosting, 'amount' => 5000]]],
            'only on the new version' => [[$hosting], [[...$hosting, 'amount' => 5000], $licence]],
            'a one-time charge' => [[$hosting, $once], [[...$hosting, 'amount' => 5000], $once]],
        ] as $case => [$v1, $v2]) {
            [$workspace, $owner, $package, $from] = $this->world(['components' => $v1], ['components' => $v2]);
            $this->subscriber($workspace, $package, 'Lakeside '.$case);
            $this->assertSame(MigrationRefusal::OffCycleComponent->value, $this->preview($owner, $workspace, $package, $from)['refusal']['code'] ?? null, $case);
            $this->expectRefusal(MigrationRefusal::OffCycleComponent, fn () => $this->schedule($owner, $workspace, $package, $from, '2027-01-01'));
            $this->assertSame(0, ServicePackageMigration::query()->where('workspace_id', $workspace->id)->count());
        }

        // An annual charge added to the agreement after scheduling holds the move on its date.
        [$workspace, $owner, $package, $from] = $this->world();
        $enrollment = $this->subscriber($workspace, $package, 'Edited');
        $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        ClientAgreement::query()->findOrFail($enrollment->client_agreement_id)->recurringItems()->create([
            'workspace_id' => $workspace->id, 'description' => 'Annual licence', 'cadence' => 'annual', 'amount' => 12000, 'quantity' => '1',
            'currency' => 'USD', 'is_taxable' => false, 'is_active' => true, 'sort_order' => 5,
        ]);
        $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
        $this->assertSame(['held'], array_column($this->moveRun()['outcomes'], 'outcome'));
        $this->assertSame(['scheduled', 'off_cycle_component'], [ServicePackageMigrationTarget::query()->sole()->status, ServicePackageMigrationTarget::query()->sole()->outcome_code]);
        $this->assertSame('active', ClientAgreement::query()->findOrFail($enrollment->client_agreement_id)->status);
    }

    // ── Isolation and concurrency ───────────────────────────────────────────

    #[Requirement('MIG-1', 'MIG-5')]
    public function test_another_workspaces_moves_and_subscribers_are_out_of_reach(): void
    {
        [$workspace, $owner, $package, $from] = $this->world();
        $this->subscriber($workspace, $package, 'Lakeside');
        $migration = $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
        [$other, $stranger] = $this->packageWorkspace('Synthetic Other Studio');

        $this->assertStatus(404, fn () => app(PackageMigrationReadService::class)->get($stranger, $other, $migration->public_id));
        $this->assertStatus(404, fn () => app(PackageMigrationWorkflow::class)->cancel($stranger, $other, $migration));
        $this->assertSame([], app(PackageMigrationReadService::class)->list($stranger, $other)['data']);
        $this->assertStatus(404, fn () => app(PackageMigrationPlanner::class)->preview($stranger, $other, $package, $from->public_id, null));
        $this->assertStatus(404, fn () => app(PackageMigrationReadService::class)->get($this->packageNonManager($workspace, 'portal'), $workspace, $migration->public_id));
        $this->assertSame('scheduled', $migration->refresh()->status);
    }

    #[Requirement('MIG-3')]
    public function test_a_cancellation_racing_the_move_settles_one_way(): void
    {
        foreach (['move first', 'cancellation first'] as $race) {
            [$workspace, $owner, $package, $from] = $this->world();
            $enrollment = $this->subscriber($workspace, $package, 'Lakeside '.$race);
            $company = ClientCompany::query()->findOrFail($enrollment->client_company_id);
            $agreement = ClientAgreement::query()->findOrFail($enrollment->client_agreement_id);
            $this->schedule($owner, $workspace, $package, $from, '2027-01-01');
            $this->travelTo(CarbonImmutable::parse('2026-12-31 12:00:00', 'UTC'));
            $cancel = fn () => app(AgreementCancellations::class)->cancelByContact($this->contactOf($company), $company, $agreement, '2027-01-31', null);
            if ($race === 'cancellation first') {
                $cancel();
            }
            $this->travelTo(CarbonImmutable::parse('2027-01-01 00:30:00', 'UTC'));
            $this->moveRun();
            if ($race === 'move first') {
                // The old agreement has ended: its cancellation is refused, the new one's is the client's to make.
                $this->assertStatus(422, $cancel);
            }
            $this->assertSame(1, ClientAgreement::query()->where('workspace_id', $workspace->id)->where('status', 'active')->count(), $race);
            $this->assertSame(1, ServicePackageMigrationTarget::query()->where('workspace_id', $workspace->id)->where('status', 'applied')->count(), $race);
            $this->assertLessThanOrEqual(1, AgreementScheduledTermination::query()->where('status', 'scheduled')->whereIn('client_agreement_id', ClientAgreement::query()->where('workspace_id', $workspace->id)->pluck('id'))->count());
            $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00:00', 'UTC'));
        }
    }

    // ── the world ───────────────────────────────────────────────────────────

    /**
     * A workspace, its owner and a package published as version 1 with these
     * terms, then as version 2 with `$next` (by default $50.00 hosting).
     *
     * @param  array<string, mixed>  $terms
     * @param  array<string, mixed>  $next
     * @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ServicePackageVersion, 4: ServicePackageVersion}
     */
    private function world(array $terms = [], array $next = []): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, [
            'invoice_issuing' => 'automatic', 'activation_mode' => 'automatic',
            'components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 4500]],
            ...$terms,
        ]);

        return [$workspace->refresh(), $owner, $package->refresh(), ServicePackageVersion::query()->findOrFail($package->published_version_id), $this->publishNext($workspace, $owner, $package, $next)];
    }

    /** @param array<string, mixed> $next */
    private function publishNext(Workspace $workspace, User $owner, ServicePackage $package, array $next): ServicePackageVersion
    {
        $packages = app(ServicePackageWorkflow::class);
        $packages->update($owner, $workspace, $package->refresh(), $next + ['components' => [['kind' => 'recurring', 'description' => 'Managed hosting', 'cadence' => 'monthly', 'amount' => 5000]]]);
        $published = $packages->publish($owner, $workspace, $package->refresh());

        return ServicePackageVersion::query()->findOrFail($published->published_version_id);
    }

    /** A client on the package's first version (or, `$onPublished`, its published one), accepted and active from today. */
    private function subscriber(Workspace $workspace, ServicePackage $package, string $name, bool $onPublished = false): ServicePackageEnrollment
    {
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => $name.' Synthetic Bakery', 'slug' => Str::slug($name).'-'.Str::uuid(),
            'billing_email' => 'billing@'.Str::slug($name).'.example.com',
        ]);
        $contact = User::factory()->create(['name' => 'Morgan '.$name]);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $contact->id, 'role' => 'member', 'access_scope' => 'company']);
        // Offered on the version current now, as a client who signed up before version 2.
        $current = $package->refresh()->published_version_id;
        $first = ServicePackageVersion::query()->where('service_package_id', $package->id)->orderBy('number')->firstOrFail();
        $package->forceFill(['published_version_id' => $onPublished ? $current : $first->id])->save();
        $workflow = app(PackageEnrollmentWorkflow::class);
        $enrollment = $workflow->accept($contact, $company, $offer = $workflow->addFromPortal($contact, $company, $package), $this->signatureFor($offer, 'Morgan '.$name))->refresh();
        $package->forceFill(['published_version_id' => $current])->save();
        $this->assertSame('active', $enrollment->status);
        // What the client heard on starting is not what these tests listen for.
        Mail::fake();

        return $enrollment;
    }

    /** @return list<string> the active services' public ids, in the workspace or anywhere */
    private function publicIds(?Workspace $workspace = null): array
    {
        return array_values(array_map(static fn (mixed $id): string => (string) $id, ServicePackageEnrollment::query()
            ->when($workspace !== null, fn ($query) => $query->where('workspace_id', $workspace?->id))
            ->where('status', 'active')->orderBy('id')->pluck('public_id')->all()));
    }

    private function contactOf(ClientCompany $company): User
    {
        return User::query()->whereIn('id', ClientCompanyMembership::query()->where('client_company_id', $company->id)->select('user_id'))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function preview(User $owner, Workspace $workspace, ServicePackage $package, ServicePackageVersion $from, ?string $date = null): array
    {
        return app(PackageMigrationPlanner::class)->preview($owner, $workspace, $package->refresh(), $from->public_id, $date);
    }

    private function schedule(User $actor, Workspace $workspace, ServicePackage $package, ServicePackageVersion $from, string $date, ?string $expectedVersion = null): ServicePackageMigration
    {
        return app(PackageMigrationWorkflow::class)->schedule($actor, $workspace, $package->refresh(), $from->public_id, $date,
            $this->publicIds($workspace), null, $expectedVersion);
    }

    private function invoice(ServicePackageEnrollment $enrollment, string $cycleStart, string $status): ClientInvoice
    {
        return ClientInvoice::query()->create([
            'workspace_id' => $enrollment->workspace_id, 'client_company_id' => $enrollment->client_company_id, 'client_agreement_id' => $enrollment->client_agreement_id,
            'invoice_number' => 'SYN-'.Str::upper(Str::random(6)), 'invoice_kind' => 'cadence_period', 'status' => $status, 'currency' => 'USD',
            'subtotal_amount' => 4500, 'tax_amount' => 0, 'total_amount' => 4500, 'balance_amount' => 4500,
            'service_period_start' => $cycleStart, 'service_period_end' => CarbonImmutable::parse($cycleStart)->endOfMonth()->toDateString(),
            'cycle_start' => $cycleStart, 'cycle_end' => CarbonImmutable::parse($cycleStart)->endOfMonth()->toDateString(),
            'issue_date' => $cycleStart, 'issued_at' => $status === 'draft' ? null : now(), 'hours_billed_at_rate' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{outcomes: list<array{outcome: string}>}
     */
    private function moveRun(array $options = [], bool $apply = true): array
    {
        $exit = Artisan::call('svc:packages:apply-migrations', [...($apply ? ['--apply' => true] : []), '--format' => 'json', ...$options]);
        $output = trim(Artisan::output());
        $this->assertSame(0, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array{outcomes: list<array{outcome: string, period_start: string, refusal: string|null}>} */
    private function issueRun(bool $refusing = false): array
    {
        $exit = Artisan::call('svc:packages:issue-invoices', ['--apply' => true, '--format' => 'json']);
        $output = trim(Artisan::output());
        $this->assertSame($refusing ? 1 : 0, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    private function expectRefusal(MigrationRefusal $refusal, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected the {$refusal->value} refusal.");
        } catch (PackageMigrationRefused $refused) {
            $this->assertSame($refusal, $refused->refusal);
        }
    }

    private function assertStatus(int $status, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected a {$status}.");
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->getStatusCode());
        } catch (ModelNotFoundException) {
            $this->assertSame(404, $status);
        } catch (\DomainException) {
            $this->assertSame(422, $status);
        }
    }
}
