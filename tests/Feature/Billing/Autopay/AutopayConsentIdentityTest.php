<?php

namespace Tests\Feature\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopayReader;
use App\Services\Billing\Autopay\AutopayResolutionService;
use App\Support\Auth\ProviderIdentityLiveness;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use App\Support\Billing\Autopay\AutopayInvoiceState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\Concerns\BuildsAutopay;
use Tests\Concerns\FakesProviderIdentity;
use Tests\TestCase;

/**
 * Autopay on consent whose giver has gone (docs/provider-identity.md, gap 2). Under provider
 * identity enforcement, an invoice whose autopay consent was given by a client contact the
 * provider now reports as ended is held for review without calling Stripe, and the run
 * reports it for a workspace manager to re-confirm consent. An unavailable provider leaves
 * the invoice for the next run. A manager's attested consent, an unbound contact, other
 * clients, and every client while enforcement is off, are charged as before.
 */
#[CoversClass(AutopayChargeService::class)]
#[CoversClass(ProviderIdentityLiveness::class)]
final class AutopayConsentIdentityTest extends TestCase
{
    use BuildsAutopay;
    use FakesProviderIdentity;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    public function test_consent_of_a_contact_whose_identity_ended_is_held_for_review_without_a_charge_and_the_manager_is_told(): void
    {
        $this->fakeProviderIdentity();
        [$workspace, $company, $invoice] = $this->dueInvoiceOnContactConsent('autopay-contact-ended');
        $this->disableProviderIdentity('autopay-contact-ended');
        $this->fakeAutopayStripe();
        $service = app(AutopayChargeService::class);

        $outcomes = $service->run(50, true);

        $this->assertCount(1, $outcomes);
        $this->assertSame(AutopayChargeResult::HeldForConsent, $outcomes[0]->result);
        $this->assertSame([], $this->autopayStripeCalls, 'Stripe is never called for a held charge.');
        $this->assertSame(1, $this->providerStatusRequests);
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame(
            [AutopayAttemptState::Claimed, null, AutopayChargeService::CONSENT_HOLD_CODE, 375_000],
            [$attempt->state, $attempt->payment_intent_id, $attempt->failure_code, $attempt->amount],
        );
        $this->assertTrue(AutopayChargeService::isConsentHold($attempt));
        $this->assertNotNull($attempt->consent_public_id);
        $this->assertSame(375_000, $invoice->fresh()->balance_amount);
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.autopay_held')->sole();
        $this->assertSame(AutopayChargeService::CONSENT_HOLD_CODE, $activity->payload['code']);
        $this->assertSame($attempt->consent_public_id, $activity->payload['consent_id']);

        // The invoice reads as held for review, and can be closed out at once: nothing is in flight.
        $context = app(AutopayReader::class)->context($workspace, $invoice->fresh(), $company->fresh());
        $this->assertSame(AutopayInvoiceState::NeedsReview, app(AutopayReader::class)->state($context));
        $this->assertTrue(app(AutopayResolutionService::class)->resolvable($attempt));
        $this->assertSame([$attempt->id], array_map(static fn (InvoiceAutopayAttempt $row): int => $row->id, $service->needingReview()));

        // The run reports it to the workspace manager with the held attempts, every run, and never charges it.
        Artisan::call('svc:billing:charge-autopay', ['--apply' => true, '--format' => 'json']);
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $report['outcomes']);
        $this->assertSame($attempt->public_id, $report['needs_review'][0]['attempt_id']);
        $this->assertSame(AutopayChargeService::CONSENT_HOLD_CODE, $report['needs_review'][0]['reason']);
        Artisan::call('svc:billing:charge-autopay');
        $this->assertStringContainsString('Held for consent: autopay attempt '.$attempt->public_id, Artisan::output());
        $this->assertSame([], $this->autopayStripeCalls);
        $this->assertSame(1, InvoiceAutopayAttempt::query()->count());
    }

    public function test_recording_new_consent_releases_the_hold_and_the_next_run_charges_on_it(): void
    {
        $this->fakeProviderIdentity();
        [$workspace, $company] = $this->dueInvoiceOnContactConsent('autopay-contact-reconfirmed');
        $this->disableProviderIdentity('autopay-contact-reconfirmed');
        $this->fakeAutopayStripe();
        $service = app(AutopayChargeService::class);
        $this->assertSame(AutopayChargeResult::HeldForConsent, $service->run(50, true)[0]->result);

        // A workspace manager re-confirms with the client and records it, with the client's attestation.
        $manager = User::query()->where('name', 'Sam Example')->firstOrFail();
        app(AutopayConsentService::class)->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(
            AutopayActorRole::Provider, $manager, 450_000, attestation: 'Client re-confirmed autopay by phone on Dec 1.',
        ));

        $outcomes = $service->run(50, true);

        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame(AutopayAttemptState::Submitted, InvoiceAutopayAttempt::query()->sole()->state);
    }

    public function test_an_active_consenting_contact_is_charged_as_before_after_asking_the_provider(): void
    {
        $this->fakeProviderIdentity();
        $this->dueInvoiceOnContactConsent('autopay-contact-active');
        $this->providerIdentity('autopay-contact-active', 3);
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame(1, $this->providerStatusRequests);
        $this->assertNull(InvoiceAutopayAttempt::query()->sole()->failure_code);
    }

    public function test_an_unavailable_provider_charges_nothing_spends_no_attempt_and_the_next_run_charges(): void
    {
        $this->fakeProviderIdentity();
        $this->dueInvoiceOnContactConsent('autopay-contact-outage');
        $this->providerIdentity('autopay-contact-outage', 3);
        $this->takeProviderOffline();
        $this->fakeAutopayStripe();

        Artisan::call('svc:billing:charge-autopay', ['--apply' => true, '--format' => 'json']);
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $report['consent_check_unavailable']);
        $this->assertSame(AutopayChargeResult::ConsentCheckUnavailable->value, $report['outcomes'][0]['result']);
        $this->assertSame([], $report['needs_review']);
        $this->assertSame([], $this->autopayStripeCalls);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
        Artisan::call('svc:billing:charge-autopay', ['--apply' => true]);
        $this->assertStringContainsString('Consent check unavailable: 1 invoice(s)', Artisan::output());
        $this->assertSame([], $this->autopayStripeCalls);

        // The provider answers again: the next run charges.
        $this->takeProviderOffline(false);
        $this->assertSame(AutopayChargeResult::Submitted, app(AutopayChargeService::class)->run(50, true)[0]->result);
        $this->assertCount(1, $this->autopayCharges());
    }

    public function test_consent_a_manager_recorded_with_the_clients_attestation_is_charged_without_asking(): void
    {
        $this->fakeProviderIdentity();
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company] = $this->autopayFixture();
        $manager = $this->boundUser('autopay-manager-ended');
        $workspace->memberships()->create(['user_id' => $manager->id, 'role' => 'admin']);
        app(AutopayConsentService::class)->turnOn($workspace, $company, new AutopayConsentRequest(
            AutopayActorRole::Provider, $manager, 500_000, attestation: 'Signed services agreement of Aug 28, clause 7.',
        ));
        $this->autopayInvoice($company);
        $this->travelTo('2030-12-01 15:00:00');
        // Even the manager's own identity ending does not touch the client's attested agreement.
        $this->disableProviderIdentity('autopay-manager-ended');
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_an_unbound_consenting_contact_is_never_checked(): void
    {
        $this->fakeProviderIdentity();
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->autopayInvoice($company);
        $this->travelTo('2030-12-01 15:00:00');
        $this->fakeAutopayStripe();

        $this->assertSame(AutopayChargeResult::Submitted, app(AutopayChargeService::class)->run(50, true)[0]->result);
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_nothing_changes_while_enforcement_is_off(): void
    {
        $this->fakeProviderIdentity(enforced: false);
        $this->dueInvoiceOnContactConsent('autopay-contact-unenforced');
        $this->disableProviderIdentity('autopay-contact-unenforced');
        $this->fakeAutopayStripe();

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame(0, $this->providerStatusRequests);
    }

    public function test_only_the_client_whose_consenting_contact_ended_is_held_and_the_provider_is_asked_once_per_consent(): void
    {
        $this->fakeProviderIdentity();
        [, $company, $invoice] = $this->dueInvoiceOnContactConsent('autopay-contact-gone', 'held');
        $second = $this->autopayInvoice($company, 'INV-0143', balance: 125_000);
        [, , $otherInvoice] = $this->dueInvoiceOnContactConsent('autopay-contact-stays', 'charged');
        $this->disableProviderIdentity('autopay-contact-gone');
        $this->providerIdentity('autopay-contact-stays', 2);
        $this->fakeAutopayStripe();

        $outcomes = [];
        foreach (app(AutopayChargeService::class)->run(50, true) as $outcome) {
            $outcomes[$outcome->invoiceId] = $outcome->result;
        }

        $this->assertSame([
            $invoice->public_id => AutopayChargeResult::HeldForConsent,
            $second->public_id => AutopayChargeResult::HeldForConsent,
            $otherInvoice->public_id => AutopayChargeResult::Submitted,
        ], $outcomes);
        $charges = $this->autopayCharges();
        $this->assertCount(1, $charges);
        $this->assertSame($otherInvoice->public_id, $charges[0]['params']['metadata']['invoice_public_id']);
        $this->assertSame(2, $this->providerStatusRequests);
    }

    /**
     * A due invoice of a client whose autopay consent a provider-bound portal contact gave.
     *
     * @return array{0: Workspace, 1: ClientCompany, 2: ClientInvoice}
     */
    private function dueInvoiceOnContactConsent(string $subject, string $slug = 'autopay'): array
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company] = $this->autopayFixture($slug);
        $contact = $this->boundUser($subject);
        $company->portalUsers()->attach($contact->id, ['workspace_id' => $workspace->id]);
        $this->providerIdentity($subject, 1);
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->autopayInvoice($company);
        // 10:00 on the due date in New York.
        $this->travelTo('2030-12-01 15:00:00');

        return [$workspace, $company->refresh(), $invoice];
    }
}
