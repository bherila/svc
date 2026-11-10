<?php

namespace Tests\StripeLive;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\PortalPaymentMethodService;
use App\Services\Billing\StripePaymentMethodService;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Billing\PaymentMethods\SetupOutcome;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Stripe\Stripe;
use Stripe\StripeClient;
use Tests\Concerns\BuildsAutopay;
use Tests\StripeLive\Support\DisposableDatabase;
use Tests\StripeLive\Support\RecordedEvent;
use Tests\StripeLive\Support\StripeApiEventSource;
use Tests\StripeLive\Support\StripeEventFeed;
use Tests\StripeLive\Support\StripeExpectation;
use Tests\StripeLive\Support\StripeTestKeyGate;
use Tests\StripeLive\Support\StripeWebhookSignature;
use Tests\TestCase;
use Throwable;

/**
 * Drives SVC's real code against Stripe's real test mode (`--testsuite=StripeLive`
 * under `phpunit.stripe-live.xml`; see docs/client-management/stripe-billing.md).
 *
 * The suite skips when `STRIPE_TEST_SECRET_KEY` is absent and refuses to run for
 * any key that is not `sk_test_` / `rk_test_`. The workspace uses that key
 * through the deployment-environment connection (`services.stripe.*`), so the
 * production adapters, the autopay command and the webhook route all run
 * unchanged; the database is the disposable in-memory one of every other test.
 *
 * Nothing needs a public URL: events are read back from Stripe's Events API and
 * delivered to the real webhook route, signed with a secret generated for the run.
 */
abstract class StripeLiveTestCase extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    /** Every event type SVC's webhook acts on that a scenario can cause. */
    protected const array REPLAYABLE = [
        'payment_intent.succeeded',
        'payment_intent.processing',
        'payment_intent.payment_failed',
        'payment_intent.canceled',
        'setup_intent.succeeded',
        'payment_method.attached',
        'payment_method.detached',
        // The default method changes on customer.updated (invoice_settings).
        'customer.updated',
    ];

    protected string $runId;

    protected int $runStart;

    protected StripeClient $stripe;

    protected string $webhookSecret;

    /** The route events are delivered to: the environment endpoint, or a saved connection's own. */
    protected string $webhookPath = '/api/webhooks/stripe';

    protected function setUp(): void
    {
        $key = StripeTestKeyGate::acceptedKey(StripeTestKeyGate::fromEnvironment());
        if ($key === null) {
            $this->markTestSkipped(StripeTestKeyGate::ENVIRONMENT_VARIABLE.' is not set; the Stripe test-mode suite is skipped.');
        }
        parent::setUp();

        // A few seconds of slack: Stripe's clock and this machine's need not agree.
        $this->runStart = time() - 10;
        $this->runId = gmdate('YmdHis').'-'.bin2hex(random_bytes(3));
        $this->webhookSecret = 'whsec_svc_e2e_'.bin2hex(random_bytes(16));
        Stripe::setMaxNetworkRetries(2);
        $this->stripe = new StripeClient(['api_key' => $key, 'max_network_retries' => 2]);

        config([
            'services.stripe.secret_key' => $key,
            'services.stripe.publishable_key' => getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: 'pk_test_svc_e2e_unused',
            'services.stripe.webhook_secret' => $this->webhookSecret,
        ]);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    /**
     * RefreshDatabase runs migrate:fresh right after this. Refuse anything but
     * SQLite in memory, judged on the configuration actually resolved.
     */
    protected function beforeRefreshingDatabase(): void
    {
        $name = (string) config('database.default');
        $connection = config('database.connections.'.$name);
        DisposableDatabase::assertDisposable($name, is_array($connection) ? $connection : null, $this->app->configurationIsCached());
    }

    protected function tearDown(): void
    {
        // Best effort: a customer left behind is test-mode data tagged svc_e2e.
        if (isset($this->stripe) && $this->app !== null) {
            try {
                foreach (ClientStripeCustomer::query()->pluck('stripe_customer_id') as $customerId) {
                    try {
                        $this->stripe->customers->delete((string) $customerId);
                    } catch (Throwable) {
                        // Already gone, or the key may not delete customers.
                    }
                }
            } catch (Throwable) {
                // The database may already be unusable after a failed test.
            }
        }
        Stripe::setMaxNetworkRetries(0);

        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: User, 2: ClientCompany, 3: User} workspace, owner, client company, portal contact */
    protected function tenant(): array
    {
        $tag = strtolower(str_replace('-', '', $this->runId)).substr(bin2hex(random_bytes(2)), 0, 4);
        $workspace = Workspace::query()->create(['name' => 'SVC E2E Studio '.$tag, 'slug' => 'svc-e2e-'.$tag, 'timezone' => 'UTC']);
        $owner = User::factory()->create(['name' => 'E2E Owner', 'email' => 'owner-'.$tag.'@example.test']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'SVC E2E Client '.$tag,
            'slug' => 'svc-e2e-client-'.$tag,
            'billing_email' => 'billing-'.$tag.'@example.test',
        ]);
        $contact = User::factory()->create(['name' => 'E2E Contact', 'email' => 'contact-'.$tag.'@example.test']);
        $company->portalUsers()->attach($contact->id, ['workspace_id' => $workspace->id]);

        return [$workspace->refresh(), $owner, $company->refresh(), $contact];
    }

    /**
     * SVC's portal path: customer and SetupIntent through the production
     * service, confirmed server-side with Stripe's `pm_card_visa` (the browser's
     * job), then read back and recorded by SVC. The first method becomes the default.
     */
    protected function saveVisaThroughSetup(Workspace $workspace, ClientCompany $company, User $actor): ClientStripePaymentMethod
    {
        $service = app(PortalPaymentMethodService::class);
        $clientSecret = $service->startSetup($workspace, $company, 'e2e-'.$this->runId);
        $this->assertStringStartsWith('seti_', $clientSecret);
        $setupIntentId = strstr($clientSecret, '_secret_', true);
        $this->assertIsString($setupIntentId);

        $this->tagCustomer($company);
        $confirmed = $this->stripe->setupIntents->confirm($setupIntentId, ['payment_method' => 'pm_card_visa']);
        $this->assertSame('succeeded', $confirmed->status, 'Stripe did not confirm the SetupIntent with pm_card_visa.');

        $this->assertSame(SetupOutcome::Saved, $service->completeSetup($workspace, $company, $actor, $setupIntentId));

        return $this->defaultMethod($workspace, $company);
    }

    /**
     * Save a test card that a SetupIntent cannot (declines on charge, or needs
     * authentication): attach it to the company's customer at Stripe, then record
     * and default it through SVC's own services, as the webhook and portal would.
     */
    protected function saveAttachedMethod(Workspace $workspace, ClientCompany $company, User $actor, string $testPaymentMethod): ClientStripePaymentMethod
    {
        $service = app(PortalPaymentMethodService::class);
        // Creates the company's Stripe customer the way the portal does.
        $service->startSetup($workspace, $company, 'e2e-'.$this->runId);
        $customer = ClientStripeCustomer::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->sole();
        $this->tagCustomer($company);

        $attached = $this->stripe->paymentMethods->attach($testPaymentMethod, ['customer' => $customer->stripe_customer_id]);
        $created = is_int($attached->created) ? $attached->created : time();
        $saved = DB::transaction(fn (): ?ClientStripePaymentMethod => app(StripePaymentMethodService::class)->attach($attached->toArray(), 'e2e-attach:'.$attached->id, $created));
        $this->assertInstanceOf(ClientStripePaymentMethod::class, $saved, 'SVC did not record the attached method.');
        $service->makeDefault($workspace, $company, $saved, $actor);

        return $this->defaultMethod($workspace, $company);
    }

    protected function defaultMethod(Workspace $workspace, ClientCompany $company): ClientStripePaymentMethod
    {
        return ClientStripePaymentMethod::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('is_default', true)
            ->sole();
    }

    protected function customerId(Workspace $workspace, ClientCompany $company): string
    {
        return ClientStripeCustomer::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->sole()
            ->stripe_customer_id;
    }

    /** Mark the company's customer as this run's synthetic one, and prove it is test mode. */
    protected function tagCustomer(ClientCompany $company): void
    {
        $row = ClientStripeCustomer::query()->where('client_company_id', $company->id)->sole();
        $customer = $this->stripe->customers->update($row->stripe_customer_id, [
            'email' => 'billing-'.$this->runId.'@example.test',
            'metadata' => ['svc_e2e' => '1', 'svc_e2e_run' => $this->runId],
        ]);
        if ($customer->livemode !== false) {
            throw new RuntimeException('Stripe reported a live-mode customer; the suite stops.');
        }
    }

    /** An issued invoice that is due today, through the production lifecycle. */
    protected function dueInvoice(Workspace $workspace, ClientCompany $company, int $amount = 5_000): ClientInvoice
    {
        $today = gmdate('Y-m-d');
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-E2E-'.strtoupper(bin2hex(random_bytes(3))),
            'currency' => 'USD',
            'issue_date' => $today,
            'due_date' => $today,
        ], [['type' => 'service', 'description' => 'Synthetic E2E service', 'quantity' => '1', 'unit_amount' => $amount, 'tax_amount' => 0]]);

        return $service->issue($invoice, $workspace);
    }

    /**
     * The real command, applied. Returns the outcomes it printed.
     *
     * @return list<array<string, mixed>>
     */
    protected function runAutopay(): array
    {
        Artisan::call('svc:billing:charge-autopay', ['--apply' => true, '--format' => 'json']);
        $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($report);
        $outcomes = $report['outcomes'] ?? [];
        $this->assertIsArray($outcomes);

        return array_values($outcomes);
    }

    /**
     * Read this scenario's events back from Stripe and wait until each expectation
     * is met. A plain string waits for any event of that type; a
     * {@see StripeExpectation} can wait for a particular object or, for
     * `customer.updated`, for the event that carries the expected default method,
     * so the earlier metadata update cannot end the wait.
     *
     * @param  list<string>  $ids  Stripe ids the scenario created (customer, intent, method)
     * @param  list<string|StripeExpectation>  $expected
     * @return list<RecordedEvent> oldest first
     */
    protected function collectStripeEvents(array $ids, array $expected): array
    {
        return (new StripeEventFeed(new StripeApiEventSource($this->stripe)))->await($this->runStart, $ids, $expected, self::REPLAYABLE);
    }

    /**
     * Collect this scenario's events and deliver each to SVC's real webhook
     * route, signed with the run's secret, in the order Stripe produced them.
     * The route must accept every one. Delivering the same events again is then
     * a second assertion, about duplicates only (every one is acknowledged as a
     * duplicate and changes nothing): ordering is covered by
     * {@see deliverUntilAccepted()} on a cleared projection, which makes
     * reordered delivery the first delivery.
     *
     * @param  list<string>  $ids
     * @param  list<string|StripeExpectation>  $expected
     * @return list<RecordedEvent>
     */
    protected function replayStripeEvents(array $ids, array $expected): array
    {
        $events = $this->collectStripeEvents($ids, $expected);
        foreach ($events as $event) {
            $response = $this->deliverOnce($event);
            $this->assertSame(200, $response->getStatusCode(), 'SVC refused '.$event->type.' '.$event->id.' (in order): '.$response->getContent());
        }
        $this->assertDuplicatesAreNoOps($events);

        return $events;
    }

    /**
     * Deliver events that SVC has not seen, in this order, as Stripe would: an
     * event SVC cannot apply yet (503, such as a default naming a method whose
     * attach has not arrived) is delivered again after the others, until all are
     * accepted. Anything but 200 or 503 fails.
     *
     * @param  list<RecordedEvent>  $events
     */
    protected function deliverUntilAccepted(array $events): void
    {
        $pending = $events;
        for ($round = 1; $pending !== [] && $round <= count($events) + 1; $round++) {
            $retry = [];
            foreach ($pending as $event) {
                $response = $this->deliverOnce($event);
                if ($response->getStatusCode() === 503) {
                    $retry[] = $event;

                    continue;
                }
                $this->assertSame(200, $response->getStatusCode(), 'SVC refused '.$event->type.' '.$event->id.' (round '.$round.'): '.$response->getContent());
                $this->assertFalse($response->json('duplicate'), $event->id.' was a duplicate of an event SVC had not been sent.');
            }
            $pending = $retry;
        }
        $this->assertSame([], array_map(static fn (RecordedEvent $event): string => $event->id, $pending), 'SVC still could not apply these events after Stripe\'s retries.');
    }

    /**
     * Every event again: acknowledged as a duplicate, and nothing changes.
     *
     * @param  list<RecordedEvent>  $events
     */
    protected function assertDuplicatesAreNoOps(array $events): void
    {
        foreach ($events as $event) {
            $response = $this->deliverOnce($event);
            $this->assertSame(200, $response->getStatusCode(), 'SVC refused a repeat of '.$event->type.' '.$event->id.': '.$response->getContent());
            $this->assertTrue($response->json('duplicate'), 'A repeat of '.$event->id.' was applied again.');
        }
    }

    /**
     * Forget everything SVC has recorded from Stripe about the company, as if no
     * event had ever been delivered: its saved methods (soft-deleted ones too),
     * the method states, the receipt ledger and the activity those wrote. The
     * Stripe customer mapping stays - no webhook can create that, and the portal
     * made it - and so does what the portal alone owns (autopay consent).
     */
    protected function resetStripeProjection(Workspace $workspace, ClientCompany $company): void
    {
        ClientStripePaymentMethod::query()->withTrashed()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->forceDelete();
        DB::table('stripe_payment_method_states')->delete();
        DB::table('client_stripe_events')->delete();
        DB::table('client_company_activity')->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->where('action', 'like', 'payment_method.%')->delete();
        $this->assertSame(0, ClientStripePaymentMethod::query()->withTrashed()->where('workspace_id', $workspace->id)->count(), 'The projection is clean before the first delivery.');
        $this->assertSame(0, DB::table('client_stripe_events')->count(), 'The receipt ledger is clean before the first delivery.');
    }

    /**
     * Newest first, or a fixed shuffle: the same events always come back in the
     * same order, so a failure is reproducible.
     *
     * @param  list<RecordedEvent>  $events  oldest first
     * @return list<RecordedEvent>
     */
    protected function reordered(array $events, string $order): array
    {
        return match ($order) {
            'newest first' => array_reverse($events),
            'shuffled' => (new Randomizer(new Mt19937(20261108)))->shuffleArray($events),
        };
    }

    /** @return TestResponse<Response> */
    private function deliverOnce(RecordedEvent $event): TestResponse
    {
        $signature = StripeWebhookSignature::header($event->json, $this->webhookSecret, time());

        return $this->call('POST', $this->webhookPath, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $event->json);
    }

    /**
     * Route this workspace through a saved connection - the post-migration
     * path - instead of the deployment environment: the key is stored on a
     * `WorkspacePaymentConnection`, the environment credentials are removed, and
     * events are delivered to the connection's own endpoint, signed with its own
     * secret. Every adapter, the autopay command and the webhook route then reach
     * Stripe and the workspace only through that row.
     *
     * The row is written directly: saving through the settings page would verify
     * the key and register a webhook endpoint at Stripe, which a test run must
     * not leave behind. Nothing here reaches Stripe.
     */
    protected function connectThroughSavedKey(Workspace $workspace, User $owner): WorkspacePaymentConnection
    {
        $key = config('services.stripe.secret_key');
        $this->assertIsString($key);
        $this->webhookSecret = 'whsec_svc_e2e_conn_'.bin2hex(random_bytes(16));
        $connection = app(WorkspacePaymentConnections::class)->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
            'external_account_id' => 'acct_svc_e2e_'.strtolower(substr(str_replace('-', '', $this->runId), -12)),
            'display_name' => 'SVC E2E saved connection',
            'environment' => 'test',
            'secret' => $key,
            'webhook_secret' => $this->webhookSecret,
            'publishable_key' => 'pk_test_svc_e2e_unused',
            'endpoint_token' => Str::random(40),
        ]);
        config(['services.stripe.secret_key' => null, 'services.stripe.webhook_secret' => null]);
        $this->webhookPath = '/api/webhooks/stripe/'.$connection->endpoint_token;

        return $connection;
    }
}
