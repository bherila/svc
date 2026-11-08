<?php

namespace Tests\StripeLive;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientStripeCustomer;
use App\Models\ClientStripePaymentMethod;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\PortalPaymentMethodService;
use App\Services\Billing\StripePaymentMethodService;
use App\Support\Billing\PaymentMethods\SetupOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Stripe\Stripe;
use Stripe\StripeClient;
use Tests\Concerns\BuildsAutopay;
use Tests\StripeLive\Support\DisposableDatabase;
use Tests\StripeLive\Support\RecordedEvent;
use Tests\StripeLive\Support\StripeEventFeed;
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
     * Read this scenario's events back from Stripe, wait for the expected types,
     * and deliver each to SVC's real webhook route, signed with the run's secret.
     * The route must accept every one.
     *
     * @param  list<string>  $ids  Stripe ids the scenario created (customer, intent, method)
     * @param  list<string>  $expected  types that must be listed before replay starts
     * @return list<RecordedEvent>
     */
    protected function replayStripeEvents(array $ids, array $expected): array
    {
        $events = (new StripeEventFeed($this->stripe))->await($this->runStart, $ids, $expected, self::REPLAYABLE);
        foreach ($events as $event) {
            $this->deliver($event, 'in order');
        }
        // Stripe retries and does not promise order: deliver everything again,
        // newest first. Each must be accepted, and the caller's assertions about
        // the final state then cover duplicate and reordered delivery as well.
        foreach (array_reverse($events) as $event) {
            $this->deliver($event, 'again, newest first');
        }

        return $events;
    }

    private function deliver(RecordedEvent $event, string $pass): void
    {
        $signature = StripeWebhookSignature::header($event->json, $this->webhookSecret, time());
        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $event->json);
        $this->assertSame(200, $response->getStatusCode(), 'SVC refused '.$event->type.' '.$event->id.' ('.$pass.'): '.$response->getContent());
    }
}
