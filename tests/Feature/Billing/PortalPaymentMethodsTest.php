<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAutopayConsent;
use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientCompanyMembership;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\StripePaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsAutopay;
use Tests\TestCase;

/**
 * A client contact adds, defaults and removes the company's saved payment
 * methods from the portal (#642). Stripe is answered in process; nothing here
 * reaches the network.
 */
final class PortalPaymentMethodsTest extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> SetupIntents Stripe will report, by id */
    private array $intents = [];

    /** @var array<string, array<string, mixed>> PaymentMethods Stripe will report, by id */
    private array $stripeMethods = [];

    private bool $detachFails = false;

    private bool $detachAlreadyGone = false;

    private bool $defaultFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.publishable_key' => 'pk_test_synthetic_portal_methods']);
        $this->stripeAnswers();
    }

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    private function stripeAnswers(): void
    {
        $this->fakeAutopayStripe(function (string $method, string $path, array $params): array {
            $method = strtolower($method);
            $id = basename($path);

            return match (true) {
                $path === '/v1/customers' && $method === 'post' => [['id' => 'cus_synthetic_created', 'object' => 'customer']],
                $path === '/v1/setup_intents' && $method === 'post' => [['id' => 'seti_synthetic_1', 'object' => 'setup_intent', 'client_secret' => 'seti_synthetic_1_secret_fixture', 'usage' => $params['usage'] ?? null]],
                str_starts_with($path, '/v1/setup_intents/') && $method === 'get' => isset($this->intents[$id])
                    ? [$this->intents[$id]]
                    : [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such setupintent']], 404],
                str_ends_with($path, '/detach') && $method === 'post' => $this->detachAnswer(),
                str_starts_with($path, '/v1/payment_methods/') && $method === 'get' => isset($this->stripeMethods[$id])
                    ? [$this->stripeMethods[$id]]
                    : [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such PaymentMethod']], 404],
                str_starts_with($path, '/v1/customers/') && $method === 'post' => $this->defaultFails
                    ? [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe failure.']], 500]
                    : [['id' => $id, 'object' => 'customer']],
                default => [['error' => ['type' => 'invalid_request_error', 'message' => 'Unhandled synthetic path '.$path]], 404],
            };
        });
    }

    /** @return array{0: array<string, mixed>, 1?: int} */
    private function detachAnswer(): array
    {
        if ($this->detachAlreadyGone) {
            return [['error' => ['type' => 'invalid_request_error', 'message' => 'The payment method you provided is not attached to a customer so detachment is impossible.']], 400];
        }

        return $this->detachFails
            ? [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe failure.']], 500]
            : [['id' => 'pm_detached', 'object' => 'payment_method']];
    }

    private function customerOf(ClientCompany $company): ClientStripeCustomer
    {
        return ClientStripeCustomer::query()->where('client_company_id', $company->id)->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function succeededIntent(string $intentId, string $customer, string $methodId, array $extra = []): void
    {
        $this->intents[$intentId] = ['id' => $intentId, 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => $customer, 'payment_method' => $methodId, ...$extra];
        $this->stripeMethods[$methodId] = [
            'id' => $methodId, 'object' => 'payment_method', 'type' => 'card', 'customer' => $customer, 'created' => 1_900_000_000,
            'card' => ['brand' => 'mastercard', 'last4' => '5454', 'exp_month' => 9, 'exp_year' => 2039],
        ];
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>, idempotency: string|null}> */
    private function calls(string $path, string $method = 'post'): array
    {
        return array_values(array_filter($this->autopayStripeCalls, static fn (array $call): bool => $call['path'] === $path && strtolower($call['method']) === $method));
    }

    public function test_the_page_hands_a_contact_finished_urls_and_staff_and_scoped_contacts_none(): void
    {
        [$workspace, $owner, $company, $contact, $method] = $this->autopayFixture();
        $scoped = User::factory()->create();
        $company->portalUsers()->attach($scoped->id, ['workspace_id' => $workspace->id]);
        ClientCompanyMembership::query()->where('user_id', $scoped->id)->update(['access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        $second = $this->autopayMethod($workspace, $company, $this->customerOf($company), 'pm_synthetic_second', false, '1111');

        $this->actingAs($contact)->get(route('portal.billing', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_method_setup', [
                    'setup_href' => route('portal.payment-methods.setup', $company, absolute: false),
                    'confirm_href' => route('portal.payment-methods.confirm', $company, absolute: false),
                    'publishable_key' => 'pk_test_synthetic_portal_methods',
                ])
                ->where('payment_methods_notice', null)
                ->where('payment_methods.0.default_href', null)
                ->where('payment_methods.0.remove_href', route('portal.payment-methods.destroy', [$company, $method->public_id], absolute: false))
                ->where('payment_methods.1.default_href', route('portal.payment-methods.default', [$company, $second->public_id], absolute: false))
                ->where('payment_methods.1.remove_href', route('portal.payment-methods.destroy', [$company, $second->public_id], absolute: false)));

        foreach ([$owner, $scoped] as $viewer) {
            $this->actingAs($viewer)->get(route('portal.billing', $company))->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('payment_method_setup', null)
                    ->where('payment_methods.1.default_href', null)
                    ->where('payment_methods.1.remove_href', null)
                    ->where('payment_methods_notice', fn (string $notice): bool => str_contains($notice, 'contact with access to the whole company')));
        }
    }

    public function test_without_a_stripe_connection_nothing_can_be_added_or_changed(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        config(['services.stripe.secret_key' => null]);

        $this->actingAs($contact)->get(route('portal.billing', $company))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment_method_setup', null)
                ->where('payment_methods.0.remove_href', null)
                ->where('payment_methods_notice', $workspace->name.' is not set up to take payments right now, so payment methods cannot be added or changed.'));
        $this->actingAs($contact)->postJson(route('portal.payment-methods.setup', $company))->assertForbidden();
        $this->assertSame([], $this->autopayStripeCalls);
    }

    public function test_without_a_publishable_key_a_method_can_be_removed_but_not_added(): void
    {
        [, , $company, $contact, $method] = $this->autopayFixture();
        config(['services.stripe.publishable_key' => null]);

        $this->actingAs($contact)->get(route('portal.billing', $company))
            ->assertInertia(fn (Assert $page) => $page->where('payment_method_setup', null)
                ->where('payment_methods.0.remove_href', route('portal.payment-methods.destroy', [$company, $method->public_id], absolute: false))
                ->where('payment_methods_notice', fn (string $notice): bool => str_contains($notice, 'cannot take new payment methods')));
        $this->actingAs($contact)->postJson(route('portal.payment-methods.setup', $company))->assertForbidden();
        $this->actingAs($contact)->post(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_1'])->assertForbidden();
    }

    public function test_setup_creates_the_stripe_customer_once_and_returns_only_the_client_secret(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        ClientStripeCustomer::query()->where('client_company_id', $company->id)->delete();
        $this->assertSame(0, ClientStripeCustomer::query()->count());

        $first = $this->actingAs($contact)->withHeader('Idempotency-Key', 'dialog-1')->postJson(route('portal.payment-methods.setup', $company))
            ->assertCreated()->assertExactJson(['client_secret' => 'seti_synthetic_1_secret_fixture']);
        $this->actingAs($contact)->withHeader('Idempotency-Key', 'dialog-1')->postJson(route('portal.payment-methods.setup', $company))->assertCreated();

        $customer = ClientStripeCustomer::query()->sole();
        $this->assertSame(['cus_synthetic_created', $workspace->id, $company->id], [$customer->stripe_customer_id, $customer->workspace_id, $customer->client_company_id]);
        $this->assertCount(1, $this->calls('/v1/customers'), 'The customer is created at Stripe once.');
        $this->assertSame('svc-customer-'.$workspace->public_id.'-'.$company->public_id, $this->calls('/v1/customers')[0]['idempotency']);
        $intents = $this->calls('/v1/setup_intents');
        $this->assertSame('off_session', $intents[0]['params']['usage']);
        $this->assertSame('cus_synthetic_created', $intents[0]['params']['customer']);
        $this->assertSame(['card', 'us_bank_account'], $intents[0]['params']['payment_method_types']);
        $this->assertSame($intents[0]['idempotency'], $intents[1]['idempotency'], 'A retried request is handed the same SetupIntent.');
        $this->assertStringNotContainsString('sk_test', $first->getContent());
    }

    public function test_a_stripe_failure_creating_the_intent_is_a_refusal_with_no_provider_detail(): void
    {
        [, , $company, $contact] = $this->autopayFixture();
        $this->fakeAutopayStripe(static fn (): array => [['error' => ['type' => 'api_error', 'message' => 'secret provider detail']], 500]);

        $response = $this->actingAs($contact)->postJson(route('portal.payment-methods.setup', $company))->assertStatus(422);
        $this->assertStringNotContainsString('secret provider detail', $response->getContent());
    }

    public function test_a_succeeded_setup_records_the_method_and_the_first_becomes_the_default(): void
    {
        [$workspace, , $company, $contact, $existing] = $this->autopayFixture();
        $existing->delete();
        $this->succeededIntent('seti_synthetic_ok', 'cus_synthetic_autopay', 'pm_synthetic_new');

        $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_ok'])
            ->assertOk()->assertJson(['outcome' => 'saved', 'message' => 'Payment method saved.']);

        $saved = ClientStripePaymentMethod::query()->sole();
        $this->assertSame(['pm_synthetic_new', 'mastercard', '5454', true, $workspace->id, $company->id], [$saved->stripe_payment_method_id, $saved->brand, $saved->last4, $saved->is_default, $saved->workspace_id, $saved->client_company_id]);
        $default = $this->calls('/v1/customers/cus_synthetic_autopay');
        $this->assertCount(1, $default);
        $this->assertSame(['default_payment_method' => 'pm_synthetic_new'], $default[0]['params']['invoice_settings']);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.added')->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());
    }

    public function test_a_second_method_is_saved_without_taking_the_default(): void
    {
        [, , $company, $contact, $existing] = $this->autopayFixture();
        $this->succeededIntent('seti_synthetic_ok', 'cus_synthetic_autopay', 'pm_synthetic_second');

        $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_ok'])->assertOk();

        $this->assertSame(2, ClientStripePaymentMethod::query()->count());
        $this->assertSame([$existing->id], ClientStripePaymentMethod::query()->where('is_default', true)->pluck('id')->all());
        $this->assertSame([], $this->calls('/v1/customers/cus_synthetic_autopay'));
    }

    public function test_confirming_twice_and_then_the_webhook_records_the_method_once(): void
    {
        [, , $company, $contact, $existing] = $this->autopayFixture();
        $existing->delete();
        $this->succeededIntent('seti_synthetic_ok', 'cus_synthetic_autopay', 'pm_synthetic_new');

        $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_ok'])->assertOk();
        $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_ok'])->assertOk();
        // Stripe's own events for the same change arrive afterwards.
        app(StripePaymentMethodService::class)->attach($this->stripeMethods['pm_synthetic_new'], 'evt_synthetic_attached', 1_900_000_005);
        app(StripePaymentMethodService::class)->changeDefault('cus_synthetic_autopay', 'pm_synthetic_new', 'evt_synthetic_customer', 1_900_000_006);

        $this->assertSame(1, ClientStripePaymentMethod::query()->count());
        $this->assertSame(1, ClientStripePaymentMethod::query()->where('is_default', true)->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.added')->count());
        $this->assertCount(1, $this->calls('/v1/customers/cus_synthetic_autopay'), 'The default is set at Stripe once.');
    }

    public function test_a_setup_that_is_not_finished_or_failed_saves_nothing(): void
    {
        [, , $company, $contact] = $this->autopayFixture();
        foreach (['processing' => 'pending', 'requires_action' => 'pending', 'requires_payment_method' => 'failed', 'canceled' => 'failed', 'something_new' => 'pending'] as $status => $outcome) {
            $this->intents['seti_synthetic_'.$status] = ['id' => 'seti_synthetic_'.$status, 'object' => 'setup_intent', 'status' => $status, 'customer' => 'cus_synthetic_autopay', 'payment_method' => 'pm_synthetic_x'];
            $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_'.$status])
                ->assertOk()->assertJson(['outcome' => $outcome]);
        }
        $this->assertSame(1, ClientStripePaymentMethod::query()->count());
    }

    public function test_a_succeeded_intent_with_no_method_is_pending(): void
    {
        [, , $company, $contact] = $this->autopayFixture();
        $this->intents['seti_synthetic_bare'] = ['id' => 'seti_synthetic_bare', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => 'cus_synthetic_autopay'];

        $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_bare'])
            ->assertOk()->assertJson(['outcome' => 'pending']);
        $this->assertSame(1, ClientStripePaymentMethod::query()->count());
    }

    public function test_another_companys_setup_intent_is_refused_and_records_nothing(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture('mine');
        $other = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Other Example Co.', 'slug' => 'other-'.uniqid()]);
        ClientStripeCustomer::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $other->id, 'stripe_customer_id' => 'cus_synthetic_other']);
        $this->succeededIntent('seti_synthetic_theirs', 'cus_synthetic_other', 'pm_synthetic_theirs');
        // The method really belongs to the other customer, whatever the intent claims.
        $this->succeededIntent('seti_synthetic_forged', 'cus_synthetic_mine', 'pm_synthetic_theirs');
        $this->stripeMethods['pm_synthetic_theirs']['customer'] = 'cus_synthetic_other';

        foreach (['seti_synthetic_theirs', 'seti_synthetic_forged', 'seti_synthetic_unknown'] as $intent) {
            $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => $intent])->assertStatus(422);
        }
        $this->assertSame(0, ClientStripePaymentMethod::query()->where('stripe_payment_method_id', 'pm_synthetic_theirs')->count());
        $this->assertSame(1, ClientStripePaymentMethod::query()->count());
    }

    public function test_confirm_names_a_stripe_setup_intent(): void
    {
        [, , $company, $contact] = $this->autopayFixture();
        foreach ([null, '', 'pi_synthetic_1', 'seti_bad id', ['x']] as $bad) {
            $this->actingAs($contact)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => $bad])->assertStatus(422)->assertJsonValidationErrors('setup_intent');
        }
        $this->assertSame([], $this->autopayStripeCalls);
    }

    public function test_make_default_changes_stripe_then_leaves_exactly_one_default(): void
    {
        [$workspace, , $company, $contact, $first] = $this->autopayFixture();
        $second = $this->autopayMethod($workspace, $company, $this->customerOf($company), 'pm_synthetic_second', false, '1111');

        $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, $second->public_id]))->assertRedirect(route('portal.billing', $company));

        $this->assertSame([$second->id], ClientStripePaymentMethod::query()->where('is_default', true)->pluck('id')->all());
        $this->assertFalse($first->fresh()->is_default);
        $call = $this->calls('/v1/customers/cus_synthetic_autopay');
        $this->assertCount(1, $call);
        $this->assertSame(['default_payment_method' => 'pm_synthetic_second'], $call[0]['params']['invoice_settings']);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->where('actor_user_id', $contact->id)->count());

        // Stripe's own event for it, and a repeat of the click, change nothing more.
        app(StripePaymentMethodService::class)->changeDefault('cus_synthetic_autopay', 'pm_synthetic_second', 'evt_synthetic_default', 1_900_000_001);
        $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, $second->public_id]))->assertRedirect();
        $this->assertSame(1, ClientStripePaymentMethod::query()->where('is_default', true)->count());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());
    }

    public function test_changing_the_default_keeps_autopay_on(): void
    {
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $second = $this->autopayMethod($workspace, $company, $this->customerOf($company), 'pm_synthetic_second', false, '1111');
        $this->turnAutopayOn($workspace, $company, $contact);

        $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, $second->public_id]))->assertRedirect();

        $this->assertNotNull($company->fresh()->autopay_enabled_at);
    }

    public function test_a_stripe_failure_making_the_default_leaves_the_record_alone(): void
    {
        [$workspace, , $company, $contact, $first] = $this->autopayFixture();
        $second = $this->autopayMethod($workspace, $company, $this->customerOf($company), 'pm_synthetic_second', false, '1111');
        $this->defaultFails = true;

        $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, $second->public_id]))->assertSessionHasErrors('payment_method');

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse($second->fresh()->is_default);
    }

    public function test_removing_a_method_detaches_it_at_stripe_and_forgets_it_here(): void
    {
        [$workspace, , $company, $contact, $default] = $this->autopayFixture();
        $second = $this->autopayMethod($workspace, $company, $this->customerOf($company), 'pm_synthetic_second', false, '1111');

        $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, $second->public_id]))->assertRedirect(route('portal.billing', $company));

        $this->assertCount(1, $this->calls('/v1/payment_methods/pm_synthetic_second/detach'));
        $this->assertTrue($second->fresh()->trashed());
        $this->assertTrue($default->fresh()->is_default);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.removed')->where('actor_user_id', $contact->id)->count());
        // A late attach event for it does not bring it back.
        app(StripePaymentMethodService::class)->attach(['id' => 'pm_synthetic_second', 'customer' => 'cus_synthetic_autopay', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111']], 'evt_synthetic_late', 1);
        $this->assertSame(1, ClientStripePaymentMethod::query()->count());
    }

    public function test_removing_the_default_turns_autopay_off_after_the_removal_commits(): void
    {
        [$workspace, , $company, $contact, $default] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);

        $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, $default->public_id]))->assertRedirect();

        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(0, ClientStripePaymentMethod::query()->where('is_default', true)->count());
        $off = ClientAutopayConsent::query()->latest('id')->firstOrFail();
        $this->assertSame(['disabled', 'system', 'Default method removed'], [$off->action->value, $off->actor_role->value, $off->reason]);
    }

    public function test_a_stripe_failure_detaching_keeps_the_method_and_autopay(): void
    {
        [$workspace, , $company, $contact, $default] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->detachFails = true;

        $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, $default->public_id]))->assertSessionHasErrors('payment_method');

        $this->assertTrue($default->fresh()->is_default);
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
    }

    public function test_a_method_stripe_says_is_already_detached_is_removed_here(): void
    {
        [, , $company, $contact, $default] = $this->autopayFixture();
        $this->detachAlreadyGone = true;

        $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, $default->public_id]))->assertRedirect();

        $this->assertTrue($default->fresh()->trashed());
    }

    public function test_no_other_company_or_workspace_method_can_be_defaulted_or_removed(): void
    {
        [$workspace, , $company, $contact, $mine] = $this->autopayFixture('mine');
        $sibling = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Sibling Example Co.', 'slug' => 'sibling-'.uniqid()]);
        $siblingCustomer = ClientStripeCustomer::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $sibling->id, 'stripe_customer_id' => 'cus_synthetic_sibling']);
        $siblingMethod = $this->autopayMethod($workspace, $sibling, $siblingCustomer, 'pm_synthetic_sibling', false, '7777');
        [, , $foreign, , $foreignMethod] = $this->autopayFixture('foreign');
        $before = $this->autopayStripeCalls;

        foreach ([$siblingMethod, $foreignMethod] as $theirs) {
            $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, $theirs->public_id]))->assertNotFound();
            $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, $theirs->public_id]))->assertNotFound();
        }
        // Nor through the other company's own URL: this contact has no standing there.
        $this->assertContains($this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$foreign, $foreignMethod->public_id]))->getStatusCode(), [403, 404]);
        $this->assertContains($this->actingAs($contact)->postJson(route('portal.payment-methods.setup', $foreign))->getStatusCode(), [403, 404]);

        $this->assertSame($before, $this->autopayStripeCalls, 'Stripe was never called.');
        $this->assertNull($siblingMethod->fresh()->deleted_at);
        $this->assertNull($foreignMethod->fresh()->deleted_at);
        $this->assertTrue($mine->fresh()->is_default);
        $this->assertFalse($siblingMethod->fresh()->is_default);
    }

    public function test_staff_scoped_contacts_strangers_and_guests_are_turned_away_from_every_route(): void
    {
        [$workspace, $owner, $company, , $method] = $this->autopayFixture();
        $scoped = User::factory()->create();
        $company->portalUsers()->attach($scoped->id, ['workspace_id' => $workspace->id]);
        ClientCompanyMembership::query()->where('user_id', $scoped->id)->update(['access_scope' => ClientCompanyMembership::SCOPE_PROJECTS]);
        $stranger = User::factory()->create();

        foreach ([$owner, $scoped] as $viewer) {
            $this->actingAs($viewer)->postJson(route('portal.payment-methods.setup', $company))->assertForbidden();
            $this->actingAs($viewer)->postJson(route('portal.payment-methods.confirm', $company), ['setup_intent' => 'seti_synthetic_1'])->assertForbidden();
            $this->actingAs($viewer)->post(route('portal.payment-methods.default', [$company, $method->public_id]))->assertForbidden();
            $this->actingAs($viewer)->delete(route('portal.payment-methods.destroy', [$company, $method->public_id]))->assertForbidden();
        }
        foreach ([
            $this->actingAs($stranger)->postJson(route('portal.payment-methods.setup', $company)),
            $this->actingAs($stranger)->delete(route('portal.payment-methods.destroy', [$company, $method->public_id])),
        ] as $response) {
            $this->assertContains($response->getStatusCode(), [403, 404]);
        }
        auth()->logout();
        $this->postJson(route('portal.payment-methods.setup', $company))->assertUnauthorized();

        $this->assertSame([], $this->autopayStripeCalls);
        $this->assertTrue($method->fresh()->is_default);
    }

    public function test_a_method_of_another_workspace_with_the_same_public_id_shape_is_not_found(): void
    {
        [, , $company, $contact] = $this->autopayFixture();

        $this->actingAs($contact)->post(route('portal.payment-methods.default', [$company, 'not-a-real-public-id']))->assertNotFound();
        $this->actingAs($contact)->delete(route('portal.payment-methods.destroy', [$company, (string) Str::uuid()]))->assertNotFound();
        $this->assertInstanceOf(Workspace::class, $company->workspace);
    }
}
