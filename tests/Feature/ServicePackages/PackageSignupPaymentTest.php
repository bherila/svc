<?php

namespace Tests\Feature\ServicePackages;

use App\Exceptions\ServicePackageRefused;
use App\Mail\ServicePackageNoticeMail;
use App\Models\ClientAgreement;
use App\Models\ClientAgreementRecurringItem;
use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\ServicePackage;
use App\Models\ServicePackageEnrollment;
use App\Models\ServicePackageVersion;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\WorkspaceActionQueue;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\ClientInvoicingService;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\Packages\PackageEnrollmentWorkflow;
use App\Services\Packages\ServicePackageWorkflow;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Packages\LapseOutcome;
use App\Support\Packages\PackageRefusal;
use App\Support\Packages\PackageTerms;
use App\Support\Packages\SignupCharges;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\BuildsServicePackages;
use Tests\Concerns\FakesStripeApi;
use Tests\Support\Requirement;
use Tests\TestCase;

/**
 * Payment at sign-up (docs/service-packages.md, slice 3b): the one sign-up
 * invoice and its lines, waiting for payment and autopay, the Stripe payment
 * that moves an enrollment on, and lapsing an unpaid one.
 *
 * Every day is fixed: the clock stands at 15 November 2026 in a UTC workspace,
 * so a monthly first period runs 15 to 30 November.
 */
final class PackageSignupPaymentTest extends TestCase
{
    use BuildsServicePackages;
    use FakesStripeApi;
    use RefreshDatabase;

    private const string HOSTING = 'Managed hosting';

    protected function setUp(): void
    {
        parent::setUp();
        config(['svc.service_packages.enabled' => true, 'services.stripe.webhook_secret' => 'whsec_synthetic_packages', 'services.stripe.publishable_key' => 'pk_test_synthetic_packages']);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        Mail::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC'));
        $this->connectStripe($this->stripeAnswers(...));
    }

    // ── ACC-3, EDGE-6: the one sign-up invoice, golden ──────────────────────

    #[Requirement('ACC-3')]
    public function test_a_setup_fee_alone_is_one_ad_hoc_invoice_and_never_a_recurring_item(): void
    {
        [, , $package, $company, $contact] = $this->world(['charge_setup_fee_at_signup' => true]);

        $enrollment = $this->accept($contact, $company, $package);

        $invoice = $this->signupInvoice($enrollment);
        $this->assertSame('ad_hoc', $invoice->invoice_kind);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame([['adjustment', 'Setup', 15000, null]], $this->lines($invoice));
        $this->assertSame([15000, 15000], [$invoice->total_amount, $invoice->balance_amount]);
        $this->assertSame(['2026-11-15', '2026-11-15'], [$invoice->issue_date?->toDateString(), $invoice->due_date?->toDateString()]);
        $this->assertTrue($invoice->is_visible_to_client);
        // Billed on the invoice, so not also a recurring item.
        $this->assertSame([self::HOSTING], $this->recurringItems($enrollment));
        // Nothing waits for it: the service starts, the invoice is outstanding.
        $this->assertSame('active', $enrollment->status);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    #[Requirement('ACC-3', 'EDGE-6')]
    public function test_the_first_month_of_hosting_is_the_engines_own_cycle_invoice_and_is_never_billed_again(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'components' => [$this->hosting()]]);
        $estimate = SignupCharges::estimate(PackageTerms::fromVersion($this->published($package)), CarbonImmutable::parse('2026-11-15'));

        $enrollment = $this->accept($contact, $company, $package);

        $invoice = $this->signupInvoice($enrollment);
        $this->assertSame('cadence_period', $invoice->invoice_kind);
        // The engine sells November, from the start: hosting's opening
        // incidence on the 15th, and an empty retainer line it always writes.
        $this->assertSame([
            ['retainer', 'Monthly Retainer (0:00 hours) - Nov 1, 2026 through Nov 30, 2026', 0, '2026-11-01'],
            ['recurring_item', self::HOSTING, 4500, '2026-11-15'],
        ], $this->lines($invoice));
        $this->assertSame(['2026-11-01', '2026-11-30'], [$invoice->cycle_start?->toDateString(), $invoice->cycle_end?->toDateString()]);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$invoice->service_period_start?->toDateString(), $invoice->service_period_end?->toDateString()]);
        $this->assertSame('2026-11-15', $invoice->due_date?->toDateString(), 'due the day it is issued, not overdue from the first');
        $this->assertSame(4500, $invoice->total_amount);
        $this->assertSame($estimate->total(), $invoice->total_amount, 'the page\'s estimate is the invoice');
        $this->assertSame('2026-11-15', ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->value('starts_on')?->toDateString());

        // The next regular cadence run bills December and skips November.
        $run = app(ClientInvoicingService::class)->generateAllInvoices($company->refresh());
        $this->assertSame(1, $run['summary']['generated_count']);
        $december = ClientInvoice::query()->whereKeyNot($invoice->id)->sole();
        $this->assertSame(['2026-12-01', '2026-12-31'], [$december->cycle_start?->toDateString(), $december->cycle_end?->toDateString()]);
        $this->assertSame([
            ['retainer', 'Monthly Retainer (0:00 hours) - Dec 1, 2026 through Dec 31, 2026', 0, '2026-12-01'],
            ['recurring_item', self::HOSTING, 4500, '2026-12-01'],
        ], $this->lines($december));
        $this->assertSame(1, ClientInvoice::query()->whereDate('cycle_start', '2026-11-01')->count());
        $this->assertSame(1, ClientInvoiceLine::query()->where('type', 'recurring_item')->whereDate('line_date', '<', '2026-12-01')->count());
        // Asked for November directly, the engine refuses to sell it twice.
        try {
            app(ClientInvoicingService::class)->generateCadencePeriod($company, ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole(), Carbon::parse('2026-11-15'));
            $this->fail('November was billed a second time.');
        } catch (RuntimeException $settled) {
            $this->assertStringContainsString('already exists', $settled->getMessage());
        }
        $this->assertSame(2, ClientInvoice::query()->where('workspace_id', $workspace->id)->count());
    }

    #[Requirement('ACC-3', 'EDGE-6')]
    public function test_the_first_period_and_the_setup_fee_are_one_invoice_and_the_fee_never_widens_the_period(): void
    {
        [, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'charge_setup_fee_at_signup' => true]);

        $invoice = $this->signupInvoice($this->accept($contact, $company, $package));

        $this->assertSame([
            ['retainer', 'Monthly Retainer (0:00 hours) - Nov 1, 2026 through Nov 30, 2026', 0, '2026-11-01'],
            ['recurring_item', self::HOSTING, 4500, '2026-11-15'],
            ['adjustment', 'Setup', 15000, null],
        ], $this->lines($invoice));
        $this->assertSame(19500, $invoice->total_amount);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$invoice->service_period_start?->toDateString(), $invoice->service_period_end?->toDateString()]);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function prorations(): iterable
    {
        // 16 of November's 30 days: 0.5333 of $600 and of 5 hours.
        yield 'prorated by hours' => ['prorate_hours', 31998, 'Monthly Retainer (2:40 hours) - Nov 1, 2026 through Nov 30, 2026'];
        yield 'the full first period' => ['full_period', 60000, 'Monthly Retainer (5:00 hours) - Nov 1, 2026 through Nov 30, 2026'];
    }

    #[DataProvider('prorations')]
    #[Requirement('ACC-3', 'EDGE-6')]
    public function test_a_retainers_first_period_follows_the_agreements_first_cycle_proration(string $proration, int $fee, string $description): void
    {
        [, , $package, $company, $contact] = $this->world([
            'charge_first_period' => true, 'invoice_issuing' => 'draft', 'components' => [$this->hosting()],
            'retainer_amount' => 60000, 'retainer_minutes' => 300, 'first_cycle_proration' => $proration,
        ]);
        $estimate = SignupCharges::estimate(PackageTerms::fromVersion($this->published($package)), CarbonImmutable::parse('2026-11-15'));

        $invoice = $this->signupInvoice($this->accept($contact, $company, $package));

        $this->assertSame([
            ['retainer', $description, $fee, '2026-11-01'],
            ['recurring_item', self::HOSTING, 4500, '2026-11-15'],
        ], $this->lines($invoice));
        $this->assertSame($fee + 4500, $invoice->total_amount);
        $this->assertSame($estimate->total(), $invoice->total_amount);
    }

    // ── ACC-4, ACC-5: waiting for payment and autopay ───────────────────────

    /**
     * The whole sign-up the mockup draws: sign, save a card, authorize
     * autopay at the package's limit in the existing words, pay through the
     * invoice's own Stripe flow, and start once the webhook confirms it - once,
     * however often Stripe replays it.
     */
    #[Requirement('ACC-4', 'ACC-5', 'X-5')]
    public function test_a_sign_up_waits_for_autopay_and_the_payment_then_starts_once_on_the_webhook(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world([
            'charge_first_period' => true, 'charge_setup_fee_at_signup' => true, 'pay_before_activation' => true,
            'require_autopay' => true, 'autopay_limit_amount' => 120000,
        ]);
        $enrollment = $this->accept($contact, $company, $package);
        $this->assertSame('awaiting_payment', $enrollment->status);
        $this->assertSame('draft', $this->agreement($enrollment)->status);
        $this->actingAs($contact);

        $review = $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertOk();
        $review->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.status', 'awaiting_payment')
            ->where('service.payment.state', 'due')
            ->where('service.payment.waiting_for', ['payment', 'autopay'])
            ->where('service.payment.lapses_on', '2026-11-29')
            ->where('checkout.due_now.total', 19500)
            ->where('checkout.due_now.balance', 19500)
            ->where('checkout.due_now.estimate', false)
            ->where('checkout.due_now.lines.0.kind', 'setup_fee')
            ->where('checkout.due_now.lines.1.kind', 'first_period')
            ->where('checkout.due_now.lines.1.period_start', '2026-11-01')
            ->where('checkout.pay_url', url("/portal/{$company->public_id}/invoices/{$enrollment->refresh()->signupInvoice?->public_id}"))
            ->where('checkout.payment_options.intent_href', "/workspaces/{$workspace->public_id}/invoices/{$this->signupInvoice($enrollment)->public_id}/stripe-payment-intent")
            ->where('checkout.card_setup.setup_url', "/portal/{$company->public_id}/payment-methods/setup")
            ->where('checkout.autopay.limit_amount', 120000)
            ->where('checkout.autopay.satisfied', false)
            // No card yet: nothing to name, so nothing to authorize.
            ->where('checkout.autopay.wording', null)
            ->where('checkout.autopay.enable_url', null));

        // A card saved through the existing SetupIntent flow, then autopay at
        // the package's limit through the existing endpoint, in the existing words.
        $this->saveCard($company);
        $wording = 'I authorize Synthetic Package Studio to charge Visa ending 4242 for Lakeside Synthetic Bakery invoices on their due dates, up to $1,200.00 each, until I turn autopay off.';
        $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('checkout.autopay.wording', $wording)
            ->where('checkout.autopay.enable_url', "/portal/{$company->public_id}/autopay")
            ->where('checkout.autopay.enable_fields', ['limit_amount' => 120000, 'wording' => $wording, 'service' => $enrollment->public_id]));
        $this->post("/portal/{$company->public_id}/autopay", ['limit_amount' => 120000, 'wording' => $wording, 'authorized' => true, 'service' => $enrollment->public_id])
            ->assertRedirect("/portal/{$company->public_id}/services/{$enrollment->public_id}");
        $consent = ClientAutopayConsent::query()->where('client_company_id', $company->id)->sole();
        $this->assertSame([AutopayActorRole::Client, 120000, $wording], [$consent->actor_role, $consent->limit_amount, $consent->wording]);
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status, 'still waiting for the payment');
        $this->assertSame(['payment'], $this->get("/portal/{$company->public_id}/services/{$enrollment->public_id}")->viewData('page')['props']['service']['payment']['waiting_for']);

        // The invoice's own payment flow: a PaymentIntent and a pending payment.
        $intent = app(StripePaymentIntentService::class)->create($this->signupInvoice($enrollment), $workspace, null, 'synthetic-signup-intent');
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status, 'a pending payment starts nothing');
        Mail::assertNotSent(ServicePackageNoticeMail::class);

        $succeeded = $this->paymentEvent('evt_synthetic_signup_paid', $intent['payment_intent_id'], 19500);
        $this->deliver($succeeded)->assertOk();
        $this->assertSame('active', $enrollment->refresh()->status);
        $this->assertSame('active', $this->agreement($enrollment)->status);
        $this->assertSame('paid', $this->signupInvoice($enrollment)->status);
        Mail::assertSent(ServicePackageNoticeMail::class, 1);

        // Stripe delivers it again: nothing changes and nobody is told twice.
        $this->deliver($succeeded)->assertOk();
        $this->deliver($this->paymentEvent('evt_synthetic_signup_paid_again', $intent['payment_intent_id'], 19500))->assertOk();
        $this->assertSame('active', $enrollment->refresh()->status);
        Mail::assertSent(ServicePackageNoticeMail::class, 1);
        $this->assertSame(1, ClientInvoicePayment::query()->where('status', 'succeeded')->count());
        $this->assertSame([], array_values(array_filter($this->packageStripeCalls, static fn (array $call): bool => str_starts_with($call['path'], '/v1/refunds'))));
    }

    /** Before acceptance the page shows the engine's estimate of what is due now, and only a company contact is given anything to use. */
    #[Requirement('ACC-3', 'ACC-4')]
    public function test_the_review_page_shows_what_is_due_now_and_finished_urls_only_to_a_company_contact(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world([
            'charge_first_period' => true, 'charge_setup_fee_at_signup' => true, 'require_autopay' => true, 'autopay_limit_amount' => 120000,
        ]);
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        $page = "/portal/{$company->public_id}/services/{$offer->public_id}";

        $this->actingAs($contact)->get($page)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('service.payment.state', 'not_invoiced')
            ->where('service.payment.signup_invoice', null)
            ->where('checkout.due_now', [
                'currency' => 'USD',
                'lines' => [
                    ['kind' => 'setup_fee', 'description' => 'Setup', 'amount' => 15000, 'period_start' => null, 'period_end' => null],
                    ['kind' => 'first_period', 'description' => 'Web hosting · Essentials', 'amount' => 4500, 'period_start' => '2026-11-15', 'period_end' => '2026-11-30'],
                ],
                'total' => 19500,
                'estimate' => true,
            ])
            ->where('checkout.pay_url', null)
            ->where('checkout.card_setup.confirm_url', "/portal/{$company->public_id}/payment-methods/confirm")
            ->where('checkout.autopay.limit_label', '$1,200.00')
            // Autopay is turned on once the offer is accepted.
            ->where('checkout.autopay.enable_url', null)
            ->where('accept_url', "/portal/{$company->public_id}/services/{$offer->public_id}/accept"));

        // Staff previewing the portal read the same figures and are offered nothing to use.
        $this->actingAs($owner)->get($page)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('checkout.due_now.total', 19500)
            ->where('checkout.pay_url', null)
            ->where('checkout.payment_options', null)
            ->where('checkout.card_setup', null)
            ->where('checkout.autopay.enable_url', null)
            ->where('accept_url', null));
        $this->assertSame(0, ClientInvoice::query()->where('workspace_id', $workspace->id)->count(), 'opening the page bills nothing');
    }

    /** X-5: a payment that rolls back starts nothing and tells nobody; the one that commits does. */
    #[Requirement('X-5', 'ACC-5')]
    public function test_a_rolled_back_payment_starts_nothing_and_sends_no_activation_email(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true]);
        $enrollment = $this->accept($contact, $company, $package);
        $this->assertSame('awaiting_payment', $enrollment->status);
        $invoice = $this->signupInvoice($enrollment);

        try {
            DB::transaction(function () use ($invoice, $workspace): void {
                app(InvoiceLifecycleService::class)->applyPayment($invoice, $this->received($invoice), $workspace);
                throw new RuntimeException('synthetic failure after the payment write');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status);
        $this->assertSame('issued', $invoice->refresh()->status);
        Mail::assertNotSent(ServicePackageNoticeMail::class);

        app(InvoiceLifecycleService::class)->applyPayment($invoice, $this->received($invoice), $workspace);
        $this->assertSame('active', $enrollment->refresh()->status);
        Mail::assertSent(ServicePackageNoticeMail::class, 1);
    }

    /** A reviewed package paid up front waits for an owner, who starts it on the day the paid period began. */
    #[Requirement('ACC-5')]
    public function test_a_paid_reviewed_sign_up_waits_for_review_and_keeps_the_start_it_was_billed_from(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true, 'activation_mode' => 'review']);
        $enrollment = $this->accept($contact, $company, $package);
        try {
            app(PackageEnrollmentWorkflow::class)->activate($owner, $workspace, $enrollment);
            $this->fail('An unpaid sign-up was activated.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::NotAwaitingReview->value, $refused->refusalCode());
        }

        $invoice = $this->signupInvoice($enrollment);
        app(InvoiceLifecycleService::class)->applyPayment($invoice, $this->received($invoice), $workspace);
        $this->assertSame('awaiting_review', $enrollment->refresh()->status);
        $this->assertNotNull(collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_review'));

        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'UTC'));
        app(PackageEnrollmentWorkflow::class)->activate($owner, $workspace, $enrollment);
        $agreement = $this->agreement($enrollment);
        $this->assertSame(['active', '2026-11-15'], [$agreement->status, $agreement->starts_on->toDateString()]);
    }

    /** ACC-4: a provider's attested turn-on is not the client's consent, and a different limit is not the package's. */
    #[Requirement('ACC-4')]
    public function test_only_the_clients_own_consent_at_exactly_the_packages_limit_meets_required_autopay(): void
    {
        [$workspace, $owner, $package, $company, $contact] = $this->world(['require_autopay' => true, 'autopay_limit_amount' => 120000, 'activation_mode' => 'automatic']);
        $this->saveCard($company);
        $consents = app(AutopayConsentService::class);
        $consents->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 120000, attestation: 'Synthetic: the client agreed on a call.'));
        $enrollment = $this->accept($contact, $company, $package);
        $this->assertSame('awaiting_payment', $enrollment->status, 'attested by the provider');

        $wording = (string) $consents->authorizationWording($workspace, $company, 150000);
        $consents->turnOn($workspace, $company->refresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 150000, wording: $wording));
        app(PackageEnrollmentWorkflow::class)->autopayChanged($workspace, $company);
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status, 'a limit other than the package\'s');

        $wording = (string) $consents->authorizationWording($workspace, $company, 120000);
        $consents->turnOn($workspace, $company->refresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 120000, wording: $wording));
        app(PackageEnrollmentWorkflow::class)->autopayChanged($workspace, $company);
        $this->assertSame('active', $enrollment->refresh()->status);
    }

    // ── ACC-6: lapsing ──────────────────────────────────────────────────────

    #[Requirement('ACC-6')]
    public function test_an_unpaid_sign_up_lapses_on_its_date_its_invoice_voided_and_its_agreement_left_a_draft(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true]);
        $unpaid = $this->accept($contact, $company, $package);
        $neverAccepted = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $this->publishedPackage($workspace, $this->ownerOf($workspace), ['link_name' => 'second-hosting']));

        $this->travelTo(CarbonImmutable::parse('2026-11-28 23:00:00', 'UTC'));
        $this->assertSame([], $this->lapseRun()['outcomes'], 'not before the lapse date');
        $this->assertSame('awaiting_payment', $unpaid->refresh()->status);

        $this->travelTo(CarbonImmutable::parse('2026-11-29 00:30:00', 'UTC'));
        $preview = $this->lapseRun(apply: false);
        $this->assertSame(['lapsed', 'lapsed'], array_column($preview['outcomes'], 'outcome'));
        $this->assertSame('awaiting_payment', $unpaid->refresh()->status, 'a preview keeps nothing');

        $run = $this->lapseRun();
        $this->assertEqualsCanonicalizing([$unpaid->public_id, $neverAccepted->public_id], array_column($run['outcomes'], 'enrollment_id'));
        foreach ([$unpaid, $neverAccepted] as $lapsed) {
            $lapsed->refresh();
            $this->assertSame('lapsed', $lapsed->status);
            $this->assertNotNull($lapsed->lapsed_at);
            $this->assertSame('draft', $this->agreement($lapsed)->status);
        }
        $invoice = $this->signupInvoice($unpaid);
        $this->assertSame(['void', 'The sign-up lapsed unpaid.'], [$invoice->status, $invoice->void_reason]);
        Mail::assertNotSent(ServicePackageNoticeMail::class);

        // Idempotent: the second run finds nothing open.
        $this->assertSame([], $this->lapseRun()['outcomes']);
        // A lapsed offer cannot be accepted.
        try {
            app(PackageEnrollmentWorkflow::class)->accept($contact, $company, $neverAccepted, ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true]);
            $this->fail('A lapsed offer was accepted.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::OfferNotOpen->value, $refused->refusalCode());
        }
    }

    #[Requirement('ACC-6', 'X-1')]
    public function test_the_lapse_command_does_nothing_while_the_feature_is_off_and_is_scheduled_only_while_it_is_on(): void
    {
        [, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true]);
        $unpaid = $this->accept($contact, $company, $package);
        $this->travelTo(CarbonImmutable::parse('2026-12-15 10:00:00', 'UTC'));
        config(['svc.service_packages.enabled' => false]);

        $this->assertSame([], $this->lapseRun()['outcomes']);
        $this->assertSame('awaiting_payment', $unpaid->refresh()->status);
        $event = collect(app(Schedule::class)->events())->first(static fn ($event): bool => str_contains((string) $event->command, 'svc:packages:lapse-enrollments --apply'));
        $this->assertNotNull($event);
        $this->assertFalse($event->filtersPass($this->app));
        config(['svc.service_packages.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    // ── EDGE-1: payment against lapse ───────────────────────────────────────

    /** Paid before the lapse ran: it moves on, and is never lapsed. */
    #[Requirement('EDGE-1', 'ACC-6')]
    public function test_a_payment_that_lands_before_the_lapse_run_wins_and_the_lapse_moves_a_stranded_one_on(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true]);
        $enrollment = $this->accept($contact, $company, $package);
        $invoice = $this->signupInvoice($enrollment);
        // The payment commits, but its after-commit step never ran (as if the
        // request died between the two): the lapse finds it paid.
        DB::table('client_invoices')->where('id', $invoice->id)->update(['status' => 'paid', 'paid_amount' => $invoice->total_amount, 'balance_amount' => 0]);

        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertSame(LapseOutcome::Continued, app(PackageEnrollmentWorkflow::class)->lapse($workspace, $enrollment));
        $this->assertSame('active', $enrollment->refresh()->status);
        $this->assertSame('paid', $invoice->refresh()->status);
    }

    /** A payment still in flight at the lapse date holds the lapse: the invoice is never voided under it. */
    #[Requirement('EDGE-1', 'ACC-6')]
    public function test_a_payment_in_flight_holds_the_lapse_and_its_success_then_starts_the_service(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true, 'activation_mode' => 'automatic']);
        $enrollment = $this->accept($contact, $company, $package);
        $intent = app(StripePaymentIntentService::class)->create($this->signupInvoice($enrollment), $workspace, null, 'synthetic-late-intent');

        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertSame([LapseOutcome::Held->value], array_column($this->lapseRun()['outcomes'], 'outcome'));
        $this->assertSame(['awaiting_payment', 'issued'], [$enrollment->refresh()->status, $this->signupInvoice($enrollment)->status]);

        $late = $this->deliver($this->paymentEvent('evt_synthetic_late_paid', $intent['payment_intent_id'], $this->signupInvoice($enrollment)->total_amount));
        $this->assertSame(200, $late->getStatusCode(), (string) $late->getContent());
        $this->assertSame('active', $enrollment->refresh()->status);
        $this->assertSame([], $this->lapseRun()['outcomes']);
    }

    /**
     * EDGE-1: Stripe reports a payment as succeeded after the lapse voided the
     * invoice. The existing path refuses money on a void invoice and records
     * the event for review; nothing is refunded or activated, and Needs you
     * says so.
     */
    #[Requirement('EDGE-1')]
    public function test_money_after_the_lapse_is_neither_refunded_nor_activated_and_appears_in_needs_you(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true, 'activation_mode' => 'automatic']);
        $enrollment = $this->accept($contact, $company, $package);
        $intent = app(StripePaymentIntentService::class)->create($this->signupInvoice($enrollment), $workspace, null, 'synthetic-retried-intent');
        // The first attempt fails, so nothing is in flight when the lapse runs.
        $total = $this->signupInvoice($enrollment)->total_amount;
        $this->deliver($this->paymentEvent('evt_synthetic_attempt_failed', $intent['payment_intent_id'], $total, 'payment_intent.payment_failed'))->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        $this->assertSame([LapseOutcome::Lapsed->value], array_column($this->lapseRun()['outcomes'], 'outcome'));
        $this->assertSame('void', $this->signupInvoice($enrollment)->status);

        // The client retries the same intent, and Stripe says it succeeded.
        $this->deliver($this->paymentEvent('evt_synthetic_after_lapse', $intent['payment_intent_id'], $total, created: 1_800_000_000))->assertStatus(409);
        // The existing refusal of money on a void invoice, recorded for review.
        $this->assertDatabaseHas('client_stripe_events', ['stripe_event_id' => 'evt_synthetic_after_lapse', 'status' => 'failed', 'error_summary' => 'A payment succeeded against a void invoice; refund it or un-void the invoice before recording it.']);

        $this->assertSame('lapsed', $enrollment->refresh()->status);
        $this->assertSame('draft', $this->agreement($enrollment)->status);
        $this->assertSame('void', $this->signupInvoice($enrollment)->status);
        Mail::assertNotSent(ServicePackageNoticeMail::class);
        $this->assertSame([], array_values(array_filter($this->packageStripeCalls, static fn (array $call): bool => str_starts_with($call['path'], '/v1/refunds'))));
        $row = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_paid_after_lapse');
        $this->assertNotNull($row);
        $this->assertSame('Payment received after Lakeside Synthetic Bakery\'s sign-up lapsed', $row['title']);
        $this->assertSame("/workspaces/{$workspace->public_id}/clients/{$company->public_id}/invoices/{$this->signupInvoice($enrollment)->public_id}", $row['href']);
        // An owner or admin's queue only.
        $member = $this->workspaceMember($workspace, 'member');
        $this->assertNull(collect(app(WorkspaceActionQueue::class)->forMember($workspace, $member))->firstWhere('kind', 'enrollment_paid_after_lapse'));
    }

    // ── EDGE-2: autopay required but off ────────────────────────────────────

    #[Requirement('EDGE-2')]
    public function test_required_autopay_turned_off_is_flagged_in_needs_you_and_the_last_card_warns_before_removal(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['require_autopay' => true, 'autopay_limit_amount' => 120000, 'activation_mode' => 'automatic']);
        $method = $this->saveCard($company);
        $consents = app(AutopayConsentService::class);
        $consents->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Client, $contact, 120000, wording: (string) $consents->authorizationWording($workspace, $company, 120000)));
        $enrollment = $this->accept($contact, $company, $package);
        $this->assertSame('active', $enrollment->status);
        $kinds = fn (): array => array_column(app(WorkspaceActionQueue::class)->forManager($workspace), 'kind');
        $this->assertNotContains('enrollment_autopay_off', $kinds());

        // The portal warns before the card autopay charges is removed.
        $this->actingAs($contact);
        $this->get("/portal/{$company->public_id}/billing")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('payment_methods.0.id', $method->public_id)
            ->where('payment_methods.0.remove_warning', 'Web hosting · Essentials requires autopay. Removing this payment method turns autopay off, and Synthetic Package Studio will follow up with you about how to pay.'));

        // The client turns autopay off: allowed, nothing suspended, flagged.
        $consents->turnOff($workspace, $company->refresh(), AutopayActorRole::Client, $contact);
        $this->assertContains('enrollment_autopay_off', $kinds());
        $this->assertSame('active', $enrollment->refresh()->status);
        $this->assertSame('active', $this->agreement($enrollment)->status);
        $row = collect(app(WorkspaceActionQueue::class)->forManager($workspace))->firstWhere('kind', 'enrollment_autopay_off');
        $this->assertSame(['Web hosting · Essentials: autopay required but off', 'Lakeside Synthetic Bakery'], [$row['title'], $row['client']]);

        // A company with no package requiring autopay gets no warning.
        $other = $this->company($workspace, 'Hillside Synthetic Florist');
        $otherContact = $this->contact($other);
        $this->saveCard($other, 'pm_synthetic_hillside');
        $this->actingAs($otherContact);
        $this->get("/portal/{$other->public_id}/billing")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('payment_methods.0.remove_warning', null));
    }

    // ── EDGE-5: a currency Stripe cannot charge ─────────────────────────────

    #[Requirement('EDGE-5', 'PKG-4')]
    public function test_publishing_refuses_a_currency_the_stripe_account_cannot_charge(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $this->connectStripe($this->stripeAnswers(...), ['usd']);
        $workflow = app(ServicePackageWorkflow::class);
        $paid = $workflow->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'euro-hosting', 'currency' => 'EUR', 'charge_first_period' => true]));
        try {
            $workflow->publish($owner, $workspace, $paid);
            $this->fail('A package Stripe cannot charge was published.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::CurrencyNotChargeable->value, $refused->refusalCode());
        }
        $this->assertSame('draft', $paid->refresh()->status);

        // A package that takes no money asks Stripe nothing.
        $this->packageStripeCalls = [];
        $free = $workflow->publish($owner, $workspace, $workflow->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'euro-free', 'currency' => 'EUR'])));
        $this->assertSame('published', $free->status);
        $this->assertSame([], $this->packageStripeCalls);

        // A currency it can charge publishes, after asking Stripe once.
        $usd = $workflow->publish($owner, $workspace, $workflow->create($owner, $workspace, $this->hostingPackageInput(['link_name' => 'usd-hosting', 'charge_first_period' => true])));
        $this->assertSame('published', $usd->status);
        $this->assertSame(['/v1/account', '/v1/country_specs/US'], array_column($this->packageStripeCalls, 'path'));
    }

    #[Requirement('EDGE-5')]
    public function test_publishing_a_paid_package_is_refused_when_stripe_cannot_be_asked(): void
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $package = app(ServicePackageWorkflow::class)->create($owner, $workspace, $this->hostingPackageInput(['charge_first_period' => true]));

        // Stripe is down: not one question is answered.
        $this->fakeStripeApi(static fn (): array => [['error' => ['type' => 'api_error', 'message' => 'Synthetic outage.']], 500]);

        try {
            app(ServicePackageWorkflow::class)->publish($owner, $workspace, $package);
            $this->fail('A paid package was published without knowing Stripe could charge it.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::StripeCurrenciesUnknown->value, $refused->refusalCode());
        }
        $this->assertSame('draft', $package->refresh()->status);
    }

    // ── X-2: Square is never written ────────────────────────────────────────

    #[Requirement('X-2')]
    public function test_an_agreement_square_collects_gets_no_sign_up_invoice_and_the_acceptance_is_refused(): void
    {
        [, , $package, $company, $contact] = $this->world(['charge_first_period' => true]);
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);
        DB::table('client_agreements')->where('id', $offer->client_agreement_id)->update(['square_series_key' => 'synthetic-square-series']);

        try {
            app(PackageEnrollmentWorkflow::class)->accept($contact, $company, $offer, ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true]);
            $this->fail('A sign-up invoice was issued for an agreement Square collects.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::CollectedInSquare->value, $refused->refusalCode());
        }
        $this->assertSame(['offered', null], [$offer->refresh()->status, $offer->accepted_at]);
        $this->assertSame(0, ClientInvoice::query()->count());
        $this->assertSame('draft', $this->agreement($offer)->status);
        // Nothing here asked anything of Square.
        $this->assertSame([], array_values(array_filter($this->packageStripeCalls, static fn (array $call): bool => str_contains($call['path'], 'square'))));
    }

    /** The engine refusing the first cycle rolls the whole acceptance back with a code. */
    #[Requirement('ACC-3')]
    public function test_a_first_period_the_engine_refuses_rolls_the_acceptance_back(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true]);
        // Another of the company's invoices already covers October's work period.
        ClientInvoice::query()->create([
            'workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYN-0001', 'invoice_kind' => 'cadence_period',
            'status' => 'issued', 'currency' => 'USD', 'subtotal_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'balance_amount' => 100,
            'service_period_start' => '2026-10-01', 'service_period_end' => '2026-10-31', 'hours_billed_at_rate' => 0,
        ]);
        $offer = app(PackageEnrollmentWorkflow::class)->addFromPortal($contact, $company, $package);

        try {
            app(PackageEnrollmentWorkflow::class)->accept($contact, $company, $offer, ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true]);
            $this->fail('An overlapping first period was invoiced.');
        } catch (ServicePackageRefused $refused) {
            $this->assertSame(PackageRefusal::FirstPeriodUnbillable->value, $refused->refusalCode());
        }
        $this->assertSame(['offered', null, null], [$offer->refresh()->status, $offer->accepted_at, $offer->signup_invoice_id]);
        $this->assertSame(1, ClientInvoice::query()->count());
    }

    // ── Isolation ───────────────────────────────────────────────────────────

    /** A paid invoice of another workspace, with the same id, moves nothing here: every read names the workspace. */
    public function test_another_workspaces_payment_moves_no_enrollment_here(): void
    {
        [$workspace, , $package, $company, $contact] = $this->world(['charge_first_period' => true, 'pay_before_activation' => true]);
        $enrollment = $this->accept($contact, $company, $package);
        [$other] = $this->packageWorkspace('Synthetic Studio Two');
        $this->assertSame($workspace->id, $this->signupInvoice($enrollment)->workspace_id);

        app(PackageEnrollmentWorkflow::class)->signupInvoicePaid($other->id, (int) $enrollment->signup_invoice_id);
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status);
        app(PackageEnrollmentWorkflow::class)->autopayChanged($other, $company);
        $this->assertSame('awaiting_payment', $enrollment->refresh()->status);
        $this->assertSame(LapseOutcome::NotDue, app(PackageEnrollmentWorkflow::class)->lapse($workspace, $enrollment));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $terms  the package's terms
     * @return array{0: Workspace, 1: User, 2: ServicePackage, 3: ClientCompany, 4: User}
     */
    private function world(array $terms = []): array
    {
        [$workspace, $owner] = $this->packageWorkspace();
        $workspace->forceFill(['timezone' => 'UTC'])->save();
        $package = $this->publishedPackage($workspace, $owner, ['invoice_issuing' => 'draft', 'activation_mode' => 'automatic', ...$terms]);
        $company = $this->company($workspace, 'Lakeside Synthetic Bakery');

        return [$workspace->refresh(), $owner, $package, $company, $this->contact($company)];
    }

    private function company(Workspace $workspace, string $name): ClientCompany
    {
        return ClientCompany::query()->create([
            'workspace_id' => $workspace->id, 'name' => $name, 'slug' => Str::slug($name).'-'.Str::uuid(),
            'billing_email' => 'billing@'.Str::slug($name).'.example.test',
        ]);
    }

    private function contact(ClientCompany $company): User
    {
        $user = User::factory()->create(['name' => 'Morgan Synthetic']);
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'member', 'access_scope' => 'company']);

        return $user;
    }

    private function ownerOf(Workspace $workspace): User
    {
        return User::query()->whereKey($workspace->memberships()->where('role', 'owner')->value('user_id'))->sole();
    }

    /** @return array{kind: string, description: string, cadence: string, amount: int} */
    private function hosting(): array
    {
        return ['kind' => 'recurring', 'description' => self::HOSTING, 'cadence' => 'monthly', 'amount' => 4500];
    }

    private function published(ServicePackage $package): ServicePackageVersion
    {
        return ServicePackageVersion::query()->whereKey($package->published_version_id)->with('components')->sole();
    }

    private function accept(User $contact, ClientCompany $company, ServicePackage $package): ServicePackageEnrollment
    {
        $workflow = app(PackageEnrollmentWorkflow::class);

        return $workflow->accept($contact, $company, $workflow->addFromPortal($contact, $company, $package), ['signer_name' => 'Morgan Synthetic', 'accepted_terms' => true])->refresh();
    }

    private function signupInvoice(ServicePackageEnrollment $enrollment): ClientInvoice
    {
        return ClientInvoice::query()->whereKey($enrollment->refresh()->signup_invoice_id)->sole();
    }

    private function agreement(ServicePackageEnrollment $enrollment): ClientAgreement
    {
        return ClientAgreement::query()->whereKey($enrollment->client_agreement_id)->sole();
    }

    /** @return list<array{string, string, int, string|null}> type, description, total and date of each line, in order */
    private function lines(ClientInvoice $invoice): array
    {
        return ClientInvoiceLine::query()->where('client_invoice_id', $invoice->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (ClientInvoiceLine $line): array => [(string) $line->type, (string) $line->description, (int) $line->total_amount, $line->line_date?->toDateString()])
            ->values()->all();
    }

    /** @return list<string> */
    private function recurringItems(ServicePackageEnrollment $enrollment): array
    {
        return ClientAgreementRecurringItem::query()->where('client_agreement_id', $enrollment->client_agreement_id)->orderBy('sort_order')->pluck('description')->all();
    }

    /** A default Visa ending 4242 saved for the company, as the SetupIntent confirmation records it. */
    private function saveCard(ClientCompany $company, string $providerId = 'pm_synthetic_lakeside'): ClientStripePaymentMethod
    {
        $customer = ClientStripeCustomer::query()->firstOrCreate(
            ['workspace_id' => $company->workspace_id, 'client_company_id' => $company->id],
            ['stripe_customer_id' => 'cus_synthetic_'.substr(md5((string) $company->id), 0, 8)],
        );

        return ClientStripePaymentMethod::query()->create([
            'workspace_id' => $company->workspace_id, 'client_company_id' => $company->id, 'client_stripe_customer_id' => $customer->id,
            'stripe_payment_method_id' => $providerId, 'type' => 'card', 'brand' => 'visa', 'last4' => '4242',
            'exp_month' => 8, 'exp_year' => 2038, 'is_default' => true,
        ]);
    }

    /** @return array<string, mixed> money received in full for an invoice */
    private function received(ClientInvoice $invoice): array
    {
        return ['amount' => $invoice->balance_amount, 'currency' => $invoice->currency, 'method' => 'stripe', 'status' => InvoicePaymentStatus::Succeeded->value, 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_direct_'.$invoice->id];
    }

    /**
     * Stripe as the sign-up flow meets it: a PaymentIntent for each invoice,
     * and no charges yet when the webhook asks for the settlement.
     *
     * @param  array<string, mixed>  $params
     * @return array{0: array<string, mixed>, 1?: int}
     */
    private function stripeAnswers(string $method, string $path, array $params): array
    {
        return match (true) {
            $path === '/v1/payment_intents' && strtolower($method) === 'post' => [[
                'id' => 'pi_synthetic_'.substr(hash('sha256', json_encode($params, JSON_THROW_ON_ERROR)), 0, 12),
                'object' => 'payment_intent', 'status' => 'requires_payment_method', 'amount' => $params['amount'] ?? 0,
                'client_secret' => 'pi_synthetic_secret_do_not_return',
            ]],
            $path === '/v1/charges' => [['object' => 'list', 'url' => '/v1/charges', 'has_more' => false, 'data' => []]],
            default => [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe has no answer for '.$path]], 500],
        };
    }

    private function paymentEvent(string $eventId, string $intentId, int $amount, string $type = 'payment_intent.succeeded', int $created = 1_790_000_000): string
    {
        return json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => $type, 'created' => $created,
            'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'amount' => $amount, 'amount_received' => $type === 'payment_intent.succeeded' ? $amount : 0, 'currency' => 'usd']],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return TestResponse<Response> */
    private function deliver(string $payload): TestResponse
    {
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_synthetic_packages'),
        ], $payload);
    }

    /** @return array{dry_run: bool, executed: bool, note: string|null, outcomes: list<array{workspace_id: string, enrollment_id: string, outcome: string}>} */
    private function lapseRun(bool $apply = true): array
    {
        Artisan::call('svc:packages:lapse-enrollments', [...($apply ? ['--apply' => true] : []), '--format' => 'json']);

        return json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    }
}
