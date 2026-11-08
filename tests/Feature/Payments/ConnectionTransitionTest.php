<?php

namespace Tests\Feature\Payments;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripeWebhookService;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\PaymentCredentials;
use App\Services\Payments\StripeWebhookHealthService;
use App\Support\Payments\PaymentProcessor;
use App\Support\Payments\WebhookUrl;
use Closure;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * The boundary where a payment connection changes hands: the environment
 * migration, a reconnect that replaces the webhook endpoint and secret, a health
 * check that outlives the reconnect, and a connection entered while the
 * migration is running. Nothing here reaches Stripe; keys, ids and secrets are
 * synthetic, and the "remote" endpoints live in an array.
 */
final class ConnectionTransitionTest extends TestCase
{
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string ENV_KEY = 'sk_live_synthetic_transition_env_9z9z';

    private const string ENV_WEBHOOK_SECRET = 'whsec_synthetic_transition_environment';

    private const string ENTERED_KEY = 'rk_live_synthetic_transition_entered_a1';

    private const string KEY_A = 'rk_live_synthetic_transition_a_a1b2';

    private const string KEY_B = 'rk_live_synthetic_transition_b_c3d4';

    /** @var array<string, array<string, mixed>> endpoint id => the endpoint as the fake Stripe lists it */
    private array $remote = [];

    /** @var array<string, string> endpoint id => signing secret Stripe issued for it */
    private array $issuedSecrets = [];

    /** @var list<string> endpoint ids deleted at Stripe, in order */
    private array $deleted = [];

    private int $created = 0;

    /** @var (Closure(): void)|null runs once, after the next endpoint is created and before Stripe answers */
    private ?Closure $afterCreate = null;

    /** @var (Closure(): void)|null runs once, when the next endpoint list is requested, after its answer is fixed */
    private ?Closure $afterList = null;

    /** @var (Closure(): void)|null runs once, when the environment key's account is read */
    private ?Closure $afterAccount = null;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.stripe.secret_key' => null,
            'services.stripe.webhook_secret' => null,
            'services.square.access_token' => null,
            'services.square.webhook_signature_key' => null,
        ]);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
        $this->fakeStripeKeys($this->stripe());
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    // ---- 1. a retried event after migration and reconnect ----

    public function test_after_a_reconnect_the_old_secret_is_refused_with_a_400_and_the_new_endpoint_is_accepted(): void
    {
        config(['services.stripe.secret_key' => self::ENV_KEY, 'services.stripe.webhook_secret' => self::ENV_WEBHOOK_SECRET]);
        [$owner, $workspace, $company] = $this->tenant('retry');
        $this->remote = self::seededEndpoint('we_legacy_env', WebhookUrl::legacy(PaymentProcessor::Stripe), StripeWebhookService::REQUIRED_EVENTS);
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertSuccessful();
        $migrated = $this->activeFor($workspace);
        $this->assertNotNull($migrated);
        $token = $migrated->endpoint_token;
        $this->assertNull($migrated->webhook_endpoint_id);
        $this->assertSame(self::ENV_WEBHOOK_SECRET, $migrated->webhookSecret());
        $retried = $this->invoice($workspace, $company, 'R1');
        $fresh = $this->invoice($workspace, $company, 'R2');
        // Before the reconnect the environment secret is honoured on both URLs.
        $this->hook('/api/webhooks/stripe/'.$token, $this->succeeded($this->invoice($workspace, $company, 'R0'), $workspace), self::ENV_WEBHOOK_SECRET)->assertOk();

        $result = app(StripeWebhookHealthService::class)->fix($workspace, $owner, StripeWebhookHealthService::RECONNECT);

        $this->assertSame('healthy', $result['health']->status->value);
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $this->assertSame($migrated->id, $row->id, 'The reconnect changes the endpoint and secret, not the connection.');
        $this->assertSame($token, $row->endpoint_token, 'The URL does not change.');
        $this->assertNotNull($row->webhook_endpoint_id);
        $this->assertNotSame(self::ENV_WEBHOOK_SECRET, $row->webhookSecret());
        $this->assertSame($this->issuedSecrets[$row->webhook_endpoint_id], $row->webhookSecret());

        // A delivery Stripe retries, still signed with the original endpoint's
        // secret, is refused with 400 on both URLs and nothing is applied: SVC
        // keeps no second secret. A 4xx lets Stripe stop treating it as a
        // transient failure; it is not applied (see the runbook).
        $payload = $this->succeeded($retried, $workspace);
        $this->hook('/api/webhooks/stripe/'.$token, $payload, self::ENV_WEBHOOK_SECRET)->assertStatus(400)->assertJson(['message' => 'Invalid Stripe webhook signature.']);
        $this->hook('/api/webhooks/stripe', $payload, self::ENV_WEBHOOK_SECRET)->assertStatus(400);
        $this->assertSame('issued', $retried->fresh()->status);
        $this->assertDatabaseCount('client_invoice_payments', 1);

        // A new event, signed by the replacement endpoint, is applied.
        $this->hook('/api/webhooks/stripe/'.$token, $this->succeeded($fresh, $workspace), (string) $row->webhookSecret())->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
        $this->assertSame('paid', $fresh->fresh()->status);
        // The same signed bytes under the replaced secret stay refused: nothing was relaxed.
        $this->hook('/api/webhooks/stripe/'.$token, $payload, self::ENV_WEBHOOK_SECRET)->assertStatus(400);
    }

    // ---- 2. two reconnects at once ----

    public function test_two_interleaved_reconnects_leave_one_consistent_pair_and_delete_the_loser(): void
    {
        [$owner, $workspace] = $this->tenant('twice');
        [$otherOwner, $other] = $this->tenant('twice-other');
        $this->staleStoredEndpoint($workspace, $owner, self::KEY_A);
        $this->staleStoredEndpoint($other, $otherOwner, self::KEY_B);
        $otherBefore = $this->activeFor($other)?->only(['webhook_endpoint_id', 'updated_at']);
        $otherSecret = $this->activeFor($other)?->webhookSecret();
        $health = app(StripeWebhookHealthService::class);
        $second = null;
        $secondFailure = null;
        // The second reconnect runs entirely between the first one's remote create and its save.
        $this->afterCreate = function () use ($health, $workspace, $owner, &$second, &$secondFailure): void {
            try {
                $second = $health->fix($workspace, $owner, StripeWebhookHealthService::RECONNECT);
            } catch (DomainException $failure) {
                $secondFailure = $failure;
            }
        };

        $firstFailure = null;
        try {
            $health->fix($workspace, $owner, StripeWebhookHealthService::RECONNECT);
        } catch (DomainException $failure) {
            $firstFailure = $failure;
        }

        $this->assertNull($secondFailure);
        $this->assertNotNull($second, 'The reconnect that saved first stands.');
        $this->assertInstanceOf(DomainException::class, $firstFailure, 'The one that lost says so instead of reporting success.');
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $stored = $row->webhook_endpoint_id;
        $this->assertNotNull($stored);
        $this->assertSame($this->issuedSecrets[$stored], $row->webhookSecret(), 'The stored secret belongs to the stored endpoint id.');
        // Exactly one endpoint was left at Stripe for this reconnect, and it is the stored one; the other was deleted.
        $this->assertCount(2, $this->createdIds());
        $this->assertCount(1, $this->deleted);
        $this->assertNotContains($stored, $this->deleted);
        $this->assertSame(array_values(array_diff($this->createdIds(), $this->deleted)), [$stored]);
        $this->assertSame([$stored], array_values(array_filter(array_keys($this->remote), fn (string $id): bool => in_array($id, $this->createdIds(), true) && ! in_array($id, $this->deleted, true))));
        // The other workspace's connection is untouched.
        $otherRow = $this->activeFor($other);
        $this->assertEquals($otherBefore, $otherRow?->only(['webhook_endpoint_id', 'updated_at']));
        $this->assertSame($otherSecret, $otherRow?->webhookSecret());
        $this->assertSame(1, WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->whereNull('revoked_at')->count());
    }

    // ---- 3. a health check that outlives a reconnect ----

    public function test_a_check_that_started_before_a_reconnect_cannot_overwrite_the_new_endpoints_record(): void
    {
        [$owner, $workspace] = $this->tenant('stale');
        $this->staleStoredEndpoint($workspace, $owner, self::KEY_A);
        $health = app(StripeWebhookHealthService::class);
        $started = $this->activeFor($workspace);
        $this->assertNotNull($started);
        $oldId = $started->webhook_endpoint_id;
        $snapshot = null;
        // Stripe has answered the check (this endpoint is gone) when the reconnect runs; the check then finishes.
        $this->afterList = function () use ($health, $workspace, $owner, &$snapshot): void {
            $health->fix($workspace, $owner, StripeWebhookHealthService::RECONNECT);
            $snapshot = $this->activeFor($workspace)?->only(['webhook_endpoint_id', 'webhook_check_status', 'webhook_checked_at']);
        };

        $health->check($started);

        $this->assertNotNull($snapshot);
        $this->assertNotSame($oldId, $snapshot['webhook_endpoint_id']);
        $this->assertSame('healthy', $snapshot['webhook_check_status']);
        $row = $this->activeFor($workspace);
        $this->assertEquals($snapshot, $row?->only(['webhook_endpoint_id', 'webhook_check_status', 'webhook_checked_at']), 'The stale answer (endpoint_missing) was not written over the new endpoint.');
    }

    // ---- 4. a connection entered while the migration runs ----

    public function test_apply_never_overwrites_a_connection_entered_after_it_verified_the_credentials(): void
    {
        config(['services.stripe.secret_key' => self::ENV_KEY, 'services.stripe.webhook_secret' => self::ENV_WEBHOOK_SECRET]);
        [$owner, $workspace] = $this->tenant('race');
        $entered = null;
        $service = app(PaymentConnectionService::class);
        // The owner saves a key on the Payments page between the command's check of the environment key and its write.
        $this->afterAccount = function () use ($service, $workspace, $owner, &$entered): void {
            $entered = $service->save($workspace, PaymentProcessor::Stripe, new PaymentCredentials(self::ENTERED_KEY), $owner);
        };

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])
            ->expectsOutputToContain('workspace_already_connected')
            ->assertFailed();

        $this->assertNotNull($entered);
        $rows = WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'stripe')->get();
        $this->assertCount(1, $rows, 'No second row and no revoked row: the entered connection was neither replaced nor retired.');
        $row = $rows->first();
        $this->assertNotNull($row);
        $this->assertNull($row->revoked_at);
        $this->assertSame($entered->id, $row->id);
        $this->assertSame('entered', $row->origin);
        $this->assertSame(self::ENTERED_KEY, $row->secret());
        $this->assertNotSame(self::ENV_WEBHOOK_SECRET, $row->webhookSecret());
        $this->assertNotNull($row->webhook_endpoint_id);
        $this->assertSame(0, WorkspacePaymentConnection::query()->where('origin', 'environment')->count());
    }

    // ---- helpers ----

    /** @return list<string> endpoint ids this fake Stripe issued */
    private function createdIds(): array
    {
        return array_keys($this->issuedSecrets);
    }

    /**
     * Save a key (the fake answers as an ordinary Stripe), then forget the
     * endpoint it made, so the stored id is one Stripe no longer lists.
     */
    private function staleStoredEndpoint(Workspace $workspace, User $owner, string $key): void
    {
        app(PaymentConnectionService::class)->save($workspace, PaymentProcessor::Stripe, new PaymentCredentials($key), $owner);
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        $this->assertNotNull($row->webhook_endpoint_id);
        unset($this->remote[$row->webhook_endpoint_id]);
        $this->issuedSecrets = [];
        $this->deleted = [];
    }

    /**
     * A fake Stripe with a real endpoint list: unique endpoint ids and secrets,
     * deletions recorded, and hooks that run at chosen moments.
     *
     * @return Closure(string, string, array<string, mixed>, string): array{0: array<string, mixed>, 1?: int}
     */
    private function stripe(): Closure
    {
        $base = self::stripeAnswers();

        return function (string $method, string $path, array $params, string $key) use ($base): array {
            if ($path === '/v1/webhook_endpoints' && $method === 'get') {
                $answer = [['object' => 'list', 'url' => '/v1/webhook_endpoints', 'has_more' => false, 'data' => array_values($this->remote)]];
                if ($this->afterList !== null) {
                    $hook = $this->afterList;
                    $this->afterList = null;
                    $hook();
                }

                return $answer;
            }
            if ($path === '/v1/webhook_endpoints' && $method === 'post') {
                $id = 'we_synthetic_'.(++$this->created).'_'.substr(hash('sha256', $key), 0, 6);
                $secret = 'whsec_synthetic_'.$this->created.'_'.substr(hash('sha256', $key), 0, 6);
                $this->issuedSecrets[$id] = $secret;
                $this->remote[$id] = ['id' => $id, 'object' => 'webhook_endpoint', 'url' => (string) $params['url'], 'status' => 'enabled', 'livemode' => str_contains($key, '_live_'), 'enabled_events' => $params['enabled_events']];
                $answer = [[...$this->remote[$id], 'secret' => $secret]];
                if ($this->afterCreate !== null) {
                    $hook = $this->afterCreate;
                    $this->afterCreate = null;
                    $hook();
                }

                return $answer;
            }
            if (str_starts_with($path, '/v1/webhook_endpoints/') && $method === 'delete') {
                $id = basename($path);
                $this->deleted[] = $id;
                unset($this->remote[$id]);

                return [['id' => $id, 'object' => 'webhook_endpoint', 'deleted' => true]];
            }
            if ($path === '/v1/account' && $key === self::ENV_KEY && $this->afterAccount !== null) {
                $hook = $this->afterAccount;
                $this->afterAccount = null;
                $hook();
            }

            return $base($method, $path, $params, $key);
        };
    }

    // ---- shared fixtures ----

    private function activeFor(Workspace $workspace): ?WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'stripe')->whereNull('revoked_at')->first();
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create(['email' => 'transition-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Transition Workspace '.$suffix, 'slug' => 'transition-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Transition Client '.$suffix, 'slug' => 'transition-client-'.$suffix]);

        return [$owner, $workspace, $company];
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-TRN-'.$number, 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);

        return $invoice->fresh();
    }

    private function succeeded(ClientInvoice $invoice, Workspace $workspace): string
    {
        return json_encode([
            'id' => 'evt_synthetic_'.$invoice->invoice_number, 'object' => 'event', 'type' => 'payment_intent.succeeded',
            'data' => ['object' => [
                'id' => 'pi_synthetic_'.$invoice->invoice_number, 'amount' => 1000, 'amount_received' => 1000, 'currency' => 'usd',
                'metadata' => ['invoice_public_id' => $invoice->public_id, 'workspace_public_id' => $workspace->public_id],
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    /** @return TestResponse<Response> */
    private function hook(string $path, string $payload, string $secret): TestResponse
    {
        $timestamp = time();

        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }
}
