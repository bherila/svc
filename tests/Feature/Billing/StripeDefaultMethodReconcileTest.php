<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\StripePaymentMethodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAutopay;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * `customer.updated` is a signal to reconcile, not an ordered stream: Stripe
 * does not promise delivery order and its event time has one-second precision.
 * SVC reads the customer's current default from Stripe and takes that as its
 * own, so any events for the customer, delivered in any order and whatever
 * their timestamps, leave SVC where Stripe is.
 *
 * Stripe is faked in process and every key and id is synthetic.
 */
final class StripeDefaultMethodReconcileTest extends TestCase
{
    use BuildsAutopay;
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string ENV_KEY = 'sk_test_synthetic_reconcile';

    private const string KEY_A = 'rk_live_synthetic_workspace_a_a1b2';

    private const string KEY_B = 'rk_live_synthetic_workspace_b_c3d4';

    /** @var array<string, string|int|null> Stripe's current default method for each customer; an int is an HTTP status to answer with instead */
    private array $stripe = [];

    /** Runs while Stripe is being read for a customer. */
    private ?\Closure $whileReading = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.stripe.secret_key' => self::ENV_KEY,
            'services.stripe.webhook_secret' => 'whsec_synthetic',
        ]);
        $this->fakeStripeKeys($this->stripeAnswer());
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    /** @return array<string, array{0: bool}> */
    public static function deliveryOrders(): array
    {
        return ['in order' => [false], 'reversed' => [true]];
    }

    #[DataProvider('deliveryOrders')]
    public function test_a_metadata_update_in_the_same_second_as_the_default_change_does_not_lose_the_default_or_stop_autopay(bool $reversed): void
    {
        [$workspace, $company, $contact, $old, $new] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $snapshotWithoutDefault = $this->customerUpdated('evt_meta', 'cus_synthetic_scene', null, 100);
        $newDefault = $this->customerUpdated('evt_default', 'cus_synthetic_scene', 'pm_synthetic_new', 100);

        foreach ($reversed ? [$newDefault, $snapshotWithoutDefault] : [$snapshotWithoutDefault, $newDefault] as $payload) {
            $this->hook($payload)->assertOk();
        }

        $this->assertDefault($new);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertNotNull($company->fresh()->autopay_enabled_at, 'Stripe never lacked a default, so autopay stays on.');
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());
    }

    #[DataProvider('deliveryOrders')]
    public function test_two_defaults_in_the_same_second_converge_on_the_one_stripe_holds_now(bool $reversed): void
    {
        [$workspace, $company, $contact, $old, $new] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        // The customer moved from the old method to the new one, both within second 100.
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $toOld = $this->customerUpdated('evt_old', 'cus_synthetic_scene', 'pm_synthetic_old', 100);
        $toNew = $this->customerUpdated('evt_new', 'cus_synthetic_scene', 'pm_synthetic_new', 100);

        foreach ($reversed ? [$toNew, $toOld] : [$toOld, $toNew] as $payload) {
            $this->hook($payload)->assertOk();
        }

        $this->assertDefault($new);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());
    }

    #[DataProvider('deliveryOrders')]
    public function test_a_customer_with_no_default_gains_one_in_the_same_second_as_an_unrelated_update(bool $reversed): void
    {
        [, $company, , $old, $new] = $this->scene();
        $old->forceFill(['is_default' => false])->save();
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $metadata = $this->customerUpdated('evt_meta', 'cus_synthetic_scene', null, 100);
        $gained = $this->customerUpdated('evt_gained', 'cus_synthetic_scene', 'pm_synthetic_new', 100);

        foreach ($reversed ? [$gained, $metadata] : [$metadata, $gained] as $payload) {
            $this->hook($payload)->assertOk();
        }

        $this->assertDefault($new);
        $this->assertNull($company->fresh()->autopay_enabled_at);
    }

    public function test_an_unrelated_update_before_the_default_change_leaves_the_default_and_the_change_still_lands(): void
    {
        [$workspace, $company, $contact, $old, $new] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_old';

        $this->hook($this->customerUpdated('evt_meta', 'cus_synthetic_scene', null, 100))->assertOk();
        $this->assertDefault($old);
        $this->assertNotNull($company->fresh()->autopay_enabled_at, 'A snapshot without a default is not a removal.');

        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $this->hook($this->customerUpdated('evt_change', 'cus_synthetic_scene', 'pm_synthetic_new', 100))->assertOk();
        $this->assertDefault($new);

        // The metadata update's twin, delivered late, finds Stripe where it is.
        $this->hook($this->customerUpdated('evt_meta_late', 'cus_synthetic_scene', null, 99))->assertOk();
        $this->assertDefault($new);
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
    }

    public function test_a_default_that_stripe_no_longer_holds_turns_autopay_off(): void
    {
        [$workspace, $company, $contact, $old] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->stripe['cus_synthetic_scene'] = null;

        // The event's own snapshot still names the old method; Stripe's current state decides.
        $this->hook($this->customerUpdated('evt_cleared', 'cus_synthetic_scene', 'pm_synthetic_old', 100))->assertOk();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertNull($company->fresh()->autopay_enabled_at);
        $this->assertSame('Default method removed', DB::table('client_autopay_consents')->orderByDesc('id')->value('reason'));
    }

    public function test_a_deleted_customer_has_no_default(): void
    {
        [$workspace, $company, $contact, $old] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->stripe['cus_synthetic_scene'] = 410;

        $this->hook($this->customerUpdated('evt_deleted', 'cus_synthetic_scene', 'pm_synthetic_old', 100))->assertOk();

        $this->assertFalse($old->fresh()->is_default);
        $this->assertNull($company->fresh()->autopay_enabled_at);
    }

    public function test_a_duplicate_event_is_acknowledged_without_reading_stripe_again(): void
    {
        [, , , , $new] = $this->scene();
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $payload = $this->customerUpdated('evt_once', 'cus_synthetic_scene', 'pm_synthetic_new', 100);

        $this->hook($payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->hook($payload)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertCount(1, $this->customerReads());
        $this->assertDefault($new);
        $this->assertSame('processed', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_once')->value('status'));
    }

    public function test_an_event_id_reused_with_other_contents_is_still_refused(): void
    {
        $this->scene();
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $this->hook($this->customerUpdated('evt_same_id', 'cus_synthetic_scene', 'pm_synthetic_new', 100))->assertOk();

        $this->hook($this->customerUpdated('evt_same_id', 'cus_synthetic_scene', 'pm_synthetic_old', 100))->assertStatus(409);
    }

    public function test_a_customer_that_cannot_be_read_leaves_the_event_retryable_and_changes_nothing(): void
    {
        [$workspace, $company, $contact, $old, $new] = $this->scene();
        $this->turnAutopayOn($workspace, $company, $contact);
        $payload = $this->customerUpdated('evt_retry', 'cus_synthetic_scene', 'pm_synthetic_new', 100);

        $this->stripe['cus_synthetic_scene'] = 500;
        $this->hook($payload)->assertStatus(503);
        $this->assertSame('received', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_retry')->value('status'));
        $this->assertDefault($old);
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'payment_method.default_changed')->count());

        // Stripe delivers it again once the read works.
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $this->hook($payload)->assertOk()->assertJsonPath('duplicate', false);
        $this->assertDefault($new);
        $this->assertSame('processed', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_retry')->value('status'));
    }

    public function test_a_customer_stripe_answers_for_wrongly_is_retried_and_not_applied(): void
    {
        [, , , $old] = $this->scene();
        $this->stripe['cus_synthetic_scene'] = 'malformed';
        $this->hook($this->customerUpdated('evt_malformed', 'cus_synthetic_scene', 'pm_synthetic_new', 100))->assertStatus(503);

        $this->stripe['cus_synthetic_scene'] = 'other-customer';
        $this->hook($this->customerUpdated('evt_wrong_customer', 'cus_synthetic_scene', 'pm_synthetic_new', 100))->assertStatus(503);

        $this->assertDefault($old);
        $this->assertSame(['received', 'received'], DB::table('client_stripe_events')->orderBy('id')->pluck('status')->all());
    }

    public function test_a_default_method_that_has_not_synchronized_yet_is_retried(): void
    {
        [, , , $old, $new] = $this->scene();
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_unseen';
        $payload = $this->customerUpdated('evt_unseen', 'cus_synthetic_scene', 'pm_synthetic_unseen', 100);

        $this->hook($payload)->assertStatus(503);
        $this->assertDefault($old);

        // Its attach event arrives; the retry then lands.
        $this->autopayMethod(Workspace::query()->sole(), ClientCompany::query()->sole(), ClientStripeCustomer::query()->sole(), 'pm_synthetic_unseen', false, '0005');
        $this->hook($payload)->assertOk();
        $this->assertSame('pm_synthetic_unseen', ClientStripePaymentMethod::query()->where('is_default', true)->sole()->stripe_payment_method_id);
        $this->assertFalse($new->fresh()->is_default);
    }

    public function test_a_read_overtaken_by_another_write_of_the_default_is_retried_and_never_applied_over_it(): void
    {
        [$workspace, $company, $contact, $old, $new] = $this->scene();
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_old';
        // While Stripe is being read, the client picks the new method in the portal.
        $this->whileReading = function () use ($workspace, $company, $contact, $new): void {
            DB::transaction(fn () => app(StripePaymentMethodService::class)->adoptDefault($workspace, $company, $new, $contact, 'portal-default:synthetic'));
        };
        $payload = $this->customerUpdated('evt_overtaken', 'cus_synthetic_scene', 'pm_synthetic_old', 100);

        $this->hook($payload)->assertStatus(503);
        $this->assertDefault($new);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertSame('received', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_overtaken')->value('status'));

        // Stripe was moved too (the portal sets it first); the redelivery reads it afresh.
        $this->whileReading = null;
        $this->stripe['cus_synthetic_scene'] = 'pm_synthetic_new';
        $this->hook($payload)->assertOk();
        $this->assertDefault($new);
    }

    public function test_an_event_for_a_customer_svc_does_not_know_asks_stripe_nothing(): void
    {
        $this->hook($this->customerUpdated('evt_unknown', 'cus_unknown_to_svc', 'pm_synthetic_new', 100))->assertOk();

        $this->assertSame([], $this->customerReads());
        $this->assertSame('processed', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_unknown')->value('status'));
    }

    public function test_a_workspaces_events_are_read_with_its_own_connection_and_never_for_another_workspaces_customer(): void
    {
        [$ownerA, $a, , $oldA, $newA] = $this->scene('a', 'cus_synthetic_a');
        [$ownerB, $b, , , $newB] = $this->scene('b', 'cus_synthetic_b');
        config(['services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null]);
        $this->actingAs($ownerA)->post('/workspaces/'.$a->public_id.'/payments/stripe', ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($ownerB)->post('/workspaces/'.$b->public_id.'/payments/stripe', ['secret' => self::KEY_B])->assertNoContent();
        $this->keyedStripeCalls = [];
        $this->stripe['cus_synthetic_a'] = 'pm_synthetic_new_a';
        $this->stripe['cus_synthetic_b'] = 'pm_synthetic_new_b';
        $tokenA = $this->tokenOf($a);
        $tokenB = $this->tokenOf($b);

        // B's endpoint is handed an event about A's customer: acknowledged, nothing read, nothing written.
        $crossed = $this->customerUpdated('evt_crossed', 'cus_synthetic_a', 'pm_synthetic_new_a', 100);
        $this->hook($crossed, $tokenB, 'whsec_'.substr(hash('sha256', self::KEY_B), 0, 8))->assertOk();
        $this->assertSame([], $this->customerReads(), 'Workspace B\'s connection is never used for workspace A\'s customer.');
        $this->assertDefault($oldA);
        $this->assertSame('processed', DB::table('client_stripe_events')->where('stripe_event_id', 'evt_crossed')->value('status'));
        $this->assertNull(DB::table('client_stripe_events')->where('stripe_event_id', 'evt_crossed')->value('workspace_id'));

        // Each workspace's own events are read with its own key and no other.
        $this->hook($this->customerUpdated('evt_a', 'cus_synthetic_a', null, 101), $tokenA, 'whsec_'.substr(hash('sha256', self::KEY_A), 0, 8))->assertOk();
        $this->hook($this->customerUpdated('evt_b', 'cus_synthetic_b', null, 101), $tokenB, 'whsec_'.substr(hash('sha256', self::KEY_B), 0, 8))->assertOk();

        $reads = $this->customerReads();
        $this->assertSame([['/v1/customers/cus_synthetic_a', self::KEY_A], ['/v1/customers/cus_synthetic_b', self::KEY_B]], array_map(static fn (array $call): array => [$call['path'], $call['key']], $reads));
        $this->assertDefault($newA);
        $this->assertDefault($newB);
        $this->assertSame($a->id, $newA->fresh()->workspace_id);
    }

    /**
     * A workspace with one customer, an old default method and a second, new one.
     *
     * @return array{0: Workspace, 1: ClientCompany, 2: User, 3: ClientStripePaymentMethod, 4: ClientStripePaymentMethod}
     */
    private function scene(string $slug = 'scene', ?string $customerId = null): array
    {
        [$workspace, $owner, $company, $contact, $old] = $this->autopayFixture($slug);
        $customer = ClientStripeCustomer::query()->where('workspace_id', $workspace->id)->sole();
        $customer->forceFill(['stripe_customer_id' => $customerId ?? 'cus_synthetic_scene'])->save();
        $suffix = $customerId === null ? '' : '_'.substr($slug, 0, 1);
        $old->forceFill(['stripe_payment_method_id' => 'pm_synthetic_old'.$suffix])->save();
        $new = $this->autopayMethod($workspace, $company, $customer, 'pm_synthetic_new'.$suffix, false, '1881');
        $this->stripe[$customer->stripe_customer_id] = 'pm_synthetic_old'.$suffix;

        return $customerId === null ? [$workspace, $company, $contact, $old, $new] : [$owner, $workspace, $company, $old, $new];
    }

    private function assertDefault(ClientStripePaymentMethod $expected): void
    {
        $this->assertSame(
            [$expected->id],
            ClientStripePaymentMethod::query()->where('client_stripe_customer_id', $expected->client_stripe_customer_id)->where('is_default', true)->pluck('id')->all(),
            'The customer has exactly the expected default.',
        );
    }

    private function tokenOf(Workspace $workspace): string
    {
        return (string) WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'stripe')->whereNull('revoked_at')->firstOrFail()->endpoint_token;
    }

    /** @return list<array{method: string, path: string, params: array<string, mixed>, key: string}> */
    private function customerReads(): array
    {
        return array_values(array_filter($this->keyedStripeCalls, static fn (array $call): bool => str_starts_with($call['path'], '/v1/customers/')));
    }

    private function customerUpdated(string $eventId, string $customerId, ?string $snapshotDefault, int $created): string
    {
        return json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'customer.updated',
            'created' => $created,
            'data' => ['object' => ['id' => $customerId, 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $snapshotDefault]]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return TestResponse<Response> */
    private function hook(string $payload, ?string $endpointToken = null, string $secret = 'whsec_synthetic'): TestResponse
    {
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/stripe'.($endpointToken === null ? '' : '/'.$endpointToken), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }

    /** @return \Closure(string, string, array<string, mixed>, string): array{0: array<string, mixed>, 1?: int} */
    private function stripeAnswer(): \Closure
    {
        $standard = self::stripeAnswers();

        return function (string $method, string $path, array $params, string $key) use ($standard): array {
            if ($method !== 'get' || preg_match('#^/v1/customers/([^/]+)$#', $path, $match) !== 1) {
                return $standard($method, $path, $params, $key);
            }
            $id = $match[1];
            if ($this->whileReading !== null) {
                ($this->whileReading)();
            }
            $current = $this->stripe[$id] ?? null;
            // Each customer belongs to the account of one key: another key finds nothing.
            if (str_contains($key, 'workspace_a') && $id !== 'cus_synthetic_a' || str_contains($key, 'workspace_b') && $id !== 'cus_synthetic_b') {
                return [['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such customer: '.$id]], 404];
            }

            return match (true) {
                $current === 410 => [['id' => $id, 'object' => 'customer', 'deleted' => true]],
                is_int($current) => [['error' => ['type' => 'api_error', 'message' => 'Synthetic Stripe failure.']], $current],
                $current === 'malformed' => [['id' => $id, 'object' => 'customer']],
                $current === 'other-customer' => [['id' => 'cus_someone_else', 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => null]]],
                default => [['id' => $id, 'object' => 'customer', 'invoice_settings' => ['default_payment_method' => $current]]],
            };
        };
    }
}
