<?php

namespace Tests\Feature\Billing\Autopay;

use App\Exceptions\AutopayVersionConflict;
use App\Mail\AutopayEnabledNoticeMail;
use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientStripeCustomer;
use App\Models\User;
use App\Services\Billing\Autopay\AutopayConsentService;
use App\Services\Billing\Autopay\AutopayReader;
use App\Services\Billing\StripePaymentMethodService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\Autopay\AutopayActorRole;
use App\Support\Billing\Autopay\AutopayConsentAction;
use App\Support\Billing\Autopay\AutopayConsentMethod;
use App\Support\Billing\Autopay\AutopayConsentRequest;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsAutopay;
use Tests\TestCase;

final class AutopayConsentTest extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    private const string WORDING = 'I authorize Example Studio autopay for Northwind Example Co. autopay invoices on their due dates, up to $5,000.00 each, until I turn autopay off.';

    public function test_a_client_contact_turns_autopay_on_with_the_exact_wording_stored(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);
        $wording = $service->authorizationWording($workspace, $company, 500_000);

        $consent = $service->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Client, $contact, 500_000, wording: $wording));

        $this->assertSame('I authorize Example Studio autopay to charge Visa ending 4242 for Northwind Example Co. autopay invoices on their due dates, up to $5,000.00 each, until I turn autopay off.', $consent->wording);
        $this->assertSame([AutopayConsentAction::Enabled, AutopayActorRole::Client, AutopayConsentMethod::PortalCheckbox, 500_000, 'Dana Example', 'Visa ending 4242'],
            [$consent->action, $consent->actor_role, $consent->method, $consent->limit_amount, $consent->actor_name, $consent->payment_method_label]);
        $this->assertNull($consent->attestation);
        $company->refresh();
        $this->assertSame(500_000, $company->autopay_limit_amount);
        $this->assertNotNull($company->autopay_enabled_at);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'client.autopay_updated')->count());
    }

    public function test_the_wording_must_be_the_wording_that_was_shown(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();

        try {
            app(AutopayConsentService::class)->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Client, $contact, 500_000, wording: self::WORDING));
            $this->fail('Expected a refusal.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('wording changed', $refusal->getMessage());
        }
        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(0, ClientAutopayConsent::query()->count());
    }

    public function test_the_limit_in_the_wording_must_be_the_limit_asked_for(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);

        $this->expectException(DomainException::class);
        $service->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Client, $contact, 900_000, wording: $service->authorizationWording($workspace, $company, 500_000)));
    }

    public function test_autopay_needs_a_default_method_stripe_and_a_sane_limit(): void
    {
        [$workspace, , $company, $contact, $method] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);
        $ask = fn (int $limit): AutopayConsentRequest => new AutopayConsentRequest(AutopayActorRole::Client, $contact, $limit, wording: (string) $service->authorizationWording($workspace, $company, $limit));

        foreach ([0, -5, AutopayConsentService::MAX_LIMIT + 1] as $limit) {
            try {
                $service->turnOn($workspace, $company, $ask($limit));
                $this->fail("Limit {$limit} should be refused.");
            } catch (DomainException) {
                $this->assertNull($company->fresh()->autopay_enabled_at);
            }
        }
        $service->turnOn($workspace, $company, $ask(AutopayConsentService::MAX_LIMIT));
        $this->assertSame(AutopayConsentService::MAX_LIMIT, $company->fresh()->autopay_limit_amount);
        $service->turnOff($workspace, $company, AutopayActorRole::Client, $contact);

        config(['services.stripe.secret_key' => null]);
        try {
            $service->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 500_000, wording: 'x'));
            $this->fail('Stripe is not connected.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('Stripe is not connected', $refusal->getMessage());
        }
        config(['services.stripe.secret_key' => 'sk_test_synthetic_autopay']);

        $method->forceFill(['is_default' => false])->save();
        try {
            $service->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 500_000, wording: 'x'));
            $this->fail('There is no default method.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('default saved payment method', $refusal->getMessage());
        }
    }

    public function test_a_provider_turns_it_on_with_an_attestation_and_the_clients_contacts_are_told_after_commit(): void
    {
        Mail::fake();
        [$workspace, $owner, $company] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);

        $consent = DB::transaction(function () use ($service, $workspace, $company, $owner): ClientAutopayConsent {
            $consent = $service->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 400_000, attestation: 'Signed services agreement of Aug 28, clause 7.'));
            Mail::assertNothingSent();

            return $consent;
        });

        Mail::assertSent(AutopayEnabledNoticeMail::class, function (AutopayEnabledNoticeMail $mail): bool {
            return $mail->hasTo('billing@northwind.example.test') && $mail->hasTo($this->contactEmail())
                && str_contains($mail->portalUrl, '/portal/') && str_ends_with($mail->portalUrl, '/billing')
                && $mail->limit === '$4,000.00' && $mail->attestation === 'Signed services agreement of Aug 28, clause 7.';
        });
        $consent->refresh();
        $this->assertSame([AutopayActorRole::Provider, AutopayConsentMethod::ProviderAttestation, 'Signed services agreement of Aug 28, clause 7.', null, true],
            [$consent->actor_role, $consent->method, $consent->attestation, $consent->wording, $consent->notice_sent]);
    }

    private function contactEmail(): string
    {
        return (string) User::query()->where('name', 'Dana Example')->value('email');
    }

    public function test_a_provider_must_attest_and_must_have_someone_to_tell(): void
    {
        Mail::fake();
        [$workspace, $owner, $company] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);

        foreach (['', '   ', 'short', str_repeat('x', 1001)] as $attestation) {
            try {
                $service->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 400_000, attestation: $attestation));
                $this->fail('Attestation should be refused.');
            } catch (DomainException) {
                $this->assertNull($company->fresh()->autopay_enabled_at);
            }
        }

        $company->portalUsers()->detach();
        $company->forceFill(['billing_email' => null])->save();
        try {
            $service->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 400_000, attestation: 'Agreed by email on Aug 28.'));
            $this->fail('Nobody could be told.');
        } catch (DomainException $refusal) {
            $this->assertStringContainsString('no billing address or portal contact', $refusal->getMessage());
        }
        Mail::assertNothingSent();
        $this->assertSame(0, ClientAutopayConsent::query()->count());
    }

    public function test_a_client_turn_on_sends_no_notice_email(): void
    {
        Mail::fake();
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);

        Mail::assertNothingSent();
    }

    public function test_a_notice_that_cannot_be_sent_does_not_undo_the_change(): void
    {
        [$workspace, $owner, $company] = $this->autopayFixture();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Synthetic mail outage.'));

        $consent = app(AutopayConsentService::class)->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 400_000, attestation: 'Agreed by email on Aug 28.'));

        $this->assertNotNull($company->fresh()->autopay_enabled_at);
        $this->assertFalse($consent->fresh()->notice_sent);
    }

    public function test_changing_the_limit_is_a_new_record_and_the_same_limit_is_a_no_op(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact, 500_000);
        $enabledAt = $company->fresh()->autopay_enabled_at;
        $service = app(AutopayConsentService::class);

        $same = $service->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 500_000, wording: (string) $service->authorizationWording($workspace, $company, 500_000)));
        $this->assertSame(1, ClientAutopayConsent::query()->count());
        $this->assertSame(AutopayConsentAction::Enabled, $same->action);

        $changed = $service->turnOn($workspace, $company->fresh(), new AutopayConsentRequest(AutopayActorRole::Client, $contact, 800_000, wording: (string) $service->authorizationWording($workspace, $company, 800_000)));
        $this->assertSame([AutopayConsentAction::LimitChanged, 800_000], [$changed->action, $changed->limit_amount]);
        $this->assertSame(800_000, $company->fresh()->autopay_limit_amount);
        $this->assertEquals($enabledAt, $company->fresh()->autopay_enabled_at, 'A limit change does not move the day autopay began.');
        $this->assertSame(2, ClientAutopayConsent::query()->count());
    }

    public function test_either_side_turns_it_off_and_off_twice_records_once(): void
    {
        [$workspace, $owner, $company, $contact] = $this->autopayFixture();
        $service = app(AutopayConsentService::class);
        $this->turnAutopayOn($workspace, $company, $contact);

        $off = $service->turnOff($workspace, $company, AutopayActorRole::Provider, $owner);

        $this->assertSame([AutopayConsentAction::Disabled, AutopayActorRole::Provider, AutopayConsentMethod::Withdrawal, 'Sam Example'], [$off->action, $off->actor_role, $off->method, $off->actor_name]);
        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertNull($company->fresh()->autopay_limit_amount);
        $this->assertNull($service->turnOff($workspace, $company->fresh(), AutopayActorRole::Client, $contact));
        $this->assertSame(2, ClientAutopayConsent::query()->count());
    }

    public function test_the_record_is_append_only(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $consent = ClientAutopayConsent::query()->sole();

        foreach ([
            fn () => $consent->forceFill(['limit_amount' => 1])->save(),
            fn () => $consent->forceFill(['wording' => 'edited'])->save(),
            fn () => $consent->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The consent record accepted a change.');
            } catch (DomainException $refusal) {
                $this->assertSame('An autopay consent record is append-only.', $refusal->getMessage());
            }
        }
        $this->assertSame(500_000, $consent->fresh()->limit_amount);
    }

    public function test_a_stale_version_is_refused(): void
    {
        [$workspace, $owner, $company] = $this->autopayFixture();
        $stale = AgentApiVersion::for($company);
        $company->forceFill(['name' => 'Renamed Example Co.'])->save();

        $this->expectException(AutopayVersionConflict::class);
        app(AutopayConsentService::class)->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::Provider, $owner, 400_000, attestation: 'Agreed by email on Aug 28.'), $stale);
    }

    public function test_system_cannot_turn_autopay_on(): void
    {
        [$workspace, $owner, $company] = $this->autopayFixture();

        $this->expectException(DomainException::class);
        app(AutopayConsentService::class)->turnOn($workspace, $company, new AutopayConsentRequest(AutopayActorRole::System, $owner, 400_000, attestation: 'Not a thing SVC does.'));
    }

    public function test_detaching_the_default_method_turns_autopay_off_after_the_webhook_commits(): void
    {
        [$workspace, , $company, $contact, $method] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $service = app(StripePaymentMethodService::class);
        $service->attach(['id' => 'pm_synthetic_autopay', 'customer' => 'cus_synthetic_autopay', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2038]], 'evt_synthetic_attach', 100);

        DB::transaction(function () use ($service, $company): void {
            $service->detach('pm_synthetic_autopay', 'evt_synthetic_detach', 200);
            $this->assertNotNull(ClientCompany::query()->whereKey($company->id)->value('autopay_enabled_at'), 'Not before the transaction commits.');
        });

        $this->assertSoftDeleted('client_stripe_payment_methods', ['id' => $method->id]);
        $this->assertOff($company);
    }

    public function test_clearing_the_default_method_turns_autopay_off(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);

        DB::transaction(fn () => app(StripePaymentMethodService::class)->reconcileDefault('cus_synthetic_autopay', null, app(StripePaymentMethodService::class)->defaultBasis('cus_synthetic_autopay') ?? throw new \LogicException('Unknown customer.'), 'evt_synthetic_cleared'));

        $this->assertOff($company);
    }

    public function test_choosing_another_default_method_keeps_autopay_on_and_charges_the_new_default(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $customer = ClientStripeCustomer::query()->sole();
        $this->autopayMethod($workspace, $company, $customer, 'pm_synthetic_second', false, '1111');

        DB::transaction(fn () => app(StripePaymentMethodService::class)->reconcileDefault('cus_synthetic_autopay', 'pm_synthetic_second', app(StripePaymentMethodService::class)->defaultBasis('cus_synthetic_autopay') ?? throw new \LogicException('Unknown customer.'), 'evt_synthetic_second'));

        $this->assertNotNull($company->fresh()->autopay_enabled_at);
        $this->assertSame('pm_synthetic_second', app(AutopayReader::class)->defaultMethod($workspace->id, $company->id)?->stripe_payment_method_id);
    }

    private function assertOff(ClientCompany $company): void
    {
        $company->refresh();
        $this->assertNull($company->autopay_enabled_at);
        $off = ClientAutopayConsent::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame([AutopayConsentAction::Disabled, AutopayActorRole::System, AutopayConsentMethod::Automatic, 'Default method removed', null],
            [$off->action, $off->actor_role, $off->method, $off->reason, $off->actor_user_id]);
    }

    public function test_a_default_method_that_still_stands_keeps_autopay_on(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);

        $this->assertNull(app(AutopayConsentService::class)->defaultMethodRemoved($workspace, $company));
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
    }
}
