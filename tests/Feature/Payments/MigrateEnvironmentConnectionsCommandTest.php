<?php

namespace Tests\Feature\Payments;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * The one-time, by-hand move from the deployment's environment credentials to a
 * workspace connection. Nothing here reaches Stripe or Square.
 */
final class MigrateEnvironmentConnectionsCommandTest extends TestCase
{
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string ENV_KEY = 'sk_live_synthetic_environment_key_9z9z';

    private const string ENV_WEBHOOK_SECRET = 'whsec_synthetic_environment_secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.stripe.secret_key' => self::ENV_KEY,
            'services.stripe.webhook_secret' => self::ENV_WEBHOOK_SECRET,
            'services.square.access_token' => 'EAAAsynthetic_environment_square',
            'services.square.webhook_signature_key' => 'synthetic-environment-signature',
            'services.square.environment' => 'production',
            'services.square.version' => '2026-01-22',
        ]);
        $this->fakeStripeKeys(self::stripeAnswers());
        Http::fake(['*' => Http::response(['locations' => [['id' => 'LOC_ENV', 'merchant_id' => 'MERCH_ENV', 'name' => 'Synthetic']]])]);
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    public function test_the_environment_key_keeps_working_until_the_command_has_run(): void
    {
        [, $workspace, $company] = $this->tenant('before');

        $this->charge($this->invoice($workspace, $company, 'B1'), $workspace, 'idem-1');

        $this->assertSame([self::ENV_KEY], array_column($this->callsTo('post', '/v1/payment_intents'), 'key'));
        $this->assertDatabaseCount('workspace_payment_connections', 0);
    }

    public function test_a_preview_is_the_default_and_calls_and_writes_nothing(): void
    {
        [, $workspace] = $this->tenant('preview');

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id])->assertSuccessful();
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('workspace_payment_connections', 0);
        $this->assertSame([], $this->keyedStripeCalls);
        Http::assertNothingSent();
    }

    public function test_apply_makes_the_first_connection_and_is_idempotent(): void
    {
        [, $workspace] = $this->tenant('apply');

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertSuccessful();
        $this->assertDatabaseCount('workspace_payment_connections', 2);
        $stripe = WorkspacePaymentConnection::query()->where('processor', 'stripe')->firstOrFail();
        $this->assertSame($workspace->id, $stripe->workspace_id);
        $this->assertSame('environment', $stripe->origin);
        $this->assertSame(self::ENV_KEY, $stripe->secret());
        $this->assertSame(self::ENV_WEBHOOK_SECRET, $stripe->webhookSecret());
        $this->assertNull($stripe->webhook_endpoint_id, 'svc did not create this endpoint and must never delete it.');
        $square = WorkspacePaymentConnection::query()->where('processor', 'square')->firstOrFail();
        $this->assertSame('MERCH_ENV', $square->external_account_id);

        $calls = count($this->keyedStripeCalls);
        $ids = WorkspacePaymentConnection::query()->pluck('id')->all();
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertSuccessful();
        $this->assertSame($ids, WorkspacePaymentConnection::query()->pluck('id')->all());
        $this->assertCount($calls, $this->keyedStripeCalls, 'A second run calls nothing.');
    }

    public function test_after_the_command_a_connection_serves_the_workspace_and_the_environment_serves_no_other(): void
    {
        [, $workspace, $company] = $this->tenant('after');
        [, $other, $otherCompany] = $this->tenant('other');
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertSuccessful();

        $this->charge($this->invoice($workspace, $company, 'A1'), $workspace, 'idem-a');
        $this->assertSame([self::ENV_KEY], array_column($this->callsTo('post', '/v1/payment_intents'), 'key'));

        $this->expectException(DomainException::class);
        $this->charge($this->invoice($other, $otherCompany, 'A2'), $other, 'idem-b');
    }

    public function test_the_legacy_webhook_url_follows_the_migrated_connection_and_stays_inside_its_workspace(): void
    {
        [, $workspace, $company] = $this->tenant('hook');
        [, $other, $otherCompany] = $this->tenant('hook-other');
        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertSuccessful();
        $mine = $this->invoice($workspace, $company, 'H1');
        $theirs = $this->invoice($other, $otherCompany, 'H2');

        $this->legacyHook($this->succeeded($theirs, $other), self::ENV_WEBHOOK_SECRET)->assertOk();
        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->legacyHook($this->succeeded($mine, $workspace), 'whsec_wrong')->assertStatus(400);
        $this->legacyHook($this->succeeded($mine, $workspace), self::ENV_WEBHOOK_SECRET)->assertOk();
        $this->assertSame('paid', $mine->fresh()->status);
    }

    public function test_it_will_not_replace_a_connection_someone_entered(): void
    {
        [$owner, $workspace] = $this->tenant('entered');
        $this->actingAs($owner)->post('/workspaces/'.$workspace->public_id.'/payments/stripe', ['secret' => 'rk_live_synthetic_entered_a1b2'])->assertNoContent();

        $this->artisan('svc:payments:migrate-environment', ['--workspace' => $workspace->public_id, '--apply' => true])->assertFailed();

        $this->assertSame('entered', WorkspacePaymentConnection::query()->where('processor', 'stripe')->firstOrFail()->origin);
        $this->assertSame(1, WorkspacePaymentConnection::query()->where('processor', 'stripe')->count());
    }

    // ---- helpers ----

    /** @return list<array{method: string, path: string, params: array<string, mixed>, key: string}> */
    private function callsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->keyedStripeCalls, static fn (array $call): bool => $call['method'] === $method && $call['path'] === $path));
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create(['email' => 'migrate-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Migrate Workspace '.$suffix, 'slug' => 'migrate-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Migrate Client '.$suffix, 'slug' => 'migrate-client-'.$suffix]);

        return [$owner, $workspace, $company];
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-MIG-'.$number, 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14',
        ], [['type' => 'service', 'description' => 'Synthetic', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]);
        $service->issue($invoice, $workspace);

        return $invoice->fresh();
    }

    private function charge(ClientInvoice $invoice, Workspace $workspace, string $idempotencyKey): void
    {
        app(StripePaymentIntentService::class)->create($invoice, $workspace, null, $idempotencyKey);
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
    private function legacyHook(string $payload, string $secret): TestResponse
    {
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }
}
