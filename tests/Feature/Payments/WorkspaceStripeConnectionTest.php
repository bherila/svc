<?php

namespace Tests\Feature\Payments;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\Payments\PaymentConnectionResolver;
use App\Services\Payments\PaymentConnectionService;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Payments\PaymentProcessor;
use DomainException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesStripeKeys;
use Tests\TestCase;

/**
 * Stripe through a restricted key each workspace stores for itself. Nothing
 * reaches Stripe: the SDK's HTTP layer is replaced and every key is synthetic.
 */
final class WorkspaceStripeConnectionTest extends TestCase
{
    use FakesStripeKeys;
    use RefreshDatabase;

    private const string KEY_A = 'rk_live_synthetic_workspace_a_a1b2';

    private const string KEY_B = 'rk_live_synthetic_workspace_b_c3d4';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://svc.example.test',
            'services.stripe.secret_key' => null,
            'services.stripe.webhook_secret' => null,
        ]);
        $this->fakeStripeKeys(self::stripeAnswers());
        if (! Route::has('svc.billing.stripe.webhook')) {
            require base_path('routes/billing.php');
        }
    }

    protected function tearDown(): void
    {
        $this->restoreStripeKeys();
        parent::tearDown();
    }

    // ---- 1 & 2: who may save a key, and that it is validated every time ----

    public function test_only_an_owner_or_admin_can_save_a_key(): void
    {
        [$owner, $workspace] = $this->tenant('role');
        $admin = $this->staff($workspace, 'admin');
        $member = $this->staff($workspace, 'member');
        $outsider = User::factory()->create();

        $this->actingAs($member)->post($this->url($workspace), ['secret' => self::KEY_A])->assertForbidden();
        $this->actingAs($outsider)->post($this->url($workspace), ['secret' => self::KEY_A])->assertForbidden();
        $this->assertDatabaseCount('workspace_payment_connections', 0);
        $this->assertSame([], $this->keyedStripeCalls, 'A refused request must not reach Stripe.');

        $this->actingAs($admin)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->assertDatabaseCount('workspace_payment_connections', 1);
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B])->assertNoContent();
        $this->assertDatabaseCount('workspace_payment_connections', 2);
        $this->assertSame(1, WorkspacePaymentConnection::query()->whereNull('revoked_at')->count());
    }

    public function test_only_an_owner_or_admin_can_reuse_or_remove_a_connection(): void
    {
        [$owner, $workspace] = $this->tenant('manage');
        $member = $this->staff($workspace, 'member');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        $this->actingAs($member)->delete($this->url($workspace))->assertForbidden();
        $this->actingAs($member)->post($this->url($workspace).'/reuse', ['source' => (string) Str::uuid()])->assertForbidden();
        $this->assertNotNull($this->activeFor($workspace));
    }

    public function test_saving_is_csrf_protected_and_revalidates_the_key_each_time(): void
    {
        $route = Route::getRoutes()->getByName('workspaces.payments.store');
        $this->assertNotNull($route);
        $resolved = app('router')->resolveMiddleware($route->gatherMiddleware(), $route->excludedMiddleware());
        $this->assertContains(PreventRequestForgery::class, $resolved);

        [$owner, $workspace] = $this->tenant('validate');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $reads = count($this->callsTo('get', '/v1/account'));

        // A key Stripe refuses stores nothing and leaves the earlier key alone.
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => 'rk_live_rejected_by_stripe_zzzz'])
            ->assertSessionHasErrors('secret');
        $this->assertSame($reads + 1, count($this->callsTo('get', '/v1/account')));
        $this->assertSame('rk_live_synthetic_workspace_a_a1b2', $this->activeFor($workspace)?->secret());

        // A value that is not a Stripe key never reaches Stripe.
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => 'pk_live_publishable_not_a_secret'])
            ->assertSessionHasErrors('secret');
        $this->assertSame($reads + 1, count($this->callsTo('get', '/v1/account')));

        // Saving the same key again reads the account again.
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->assertSame($reads + 2, count($this->callsTo('get', '/v1/account')));
    }

    public function test_saving_creates_the_workspaces_own_webhook_endpoint(): void
    {
        [$owner, $workspace] = $this->tenant('endpoint');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        $connection = $this->activeFor($workspace);
        $this->assertNotNull($connection);
        $create = $this->callsTo('post', '/v1/webhook_endpoints');
        $this->assertCount(1, $create);
        $this->assertSame(self::KEY_A, $create[0]['key']);
        $this->assertSame('https://svc.example.test/api/webhooks/stripe/'.$connection->endpoint_token, $create[0]['params']['url']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', $connection->endpoint_token);
        $this->assertSame('whsec_'.$this->suffix(self::KEY_A), $connection->webhookSecret());
    }

    public function test_replacing_or_removing_a_key_deletes_the_old_endpoint_after_commit(): void
    {
        [$owner, $workspace] = $this->tenant('replace');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->assertSame([], $this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A)));

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B])->assertNoContent();
        $deleted = $this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A));
        $this->assertCount(1, $deleted);
        // The old endpoint is deleted with the old key, not the new one.
        $this->assertSame(self::KEY_A, $deleted[0]['key']);

        $this->actingAs($owner)->delete($this->url($workspace))->assertNoContent();
        $removed = $this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_B));
        $this->assertCount(1, $removed);
        $this->assertNull($this->activeFor($workspace));
    }

    public function test_the_external_call_waits_for_the_enclosing_transaction_to_commit(): void
    {
        [$owner, $workspace] = $this->tenant('deferred');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $before = count($this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A)));

        DB::transaction(function () use ($workspace, $owner, $before): void {
            app(PaymentConnectionService::class)->revoke($workspace, PaymentProcessor::Stripe, $owner);
            $this->assertSame($before, count($this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A))), 'Nothing is sent while the change can still roll back.');
        });
        $this->assertSame($before + 1, count($this->callsTo('delete', '/v1/webhook_endpoints/we_'.$this->suffix(self::KEY_A))));
    }

    // ---- 3: secrets stay secret ----

    public function test_keys_are_encrypted_at_rest_masked_everywhere_and_never_logged(): void
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        [$owner, $workspace] = $this->tenant('secrets');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $connection = $this->activeFor($workspace);
        $this->assertNotNull($connection);

        $raw = (string) DB::table('workspace_payment_connections')->where('id', $connection->id)->value('secret');
        $rawWebhook = (string) DB::table('workspace_payment_connections')->where('id', $connection->id)->value('webhook_secret');
        $this->assertStringNotContainsString('synthetic_workspace_a', $raw);
        $this->assertStringNotContainsString('whsec_', $rawWebhook);
        $this->assertSame(self::KEY_A, $connection->secret());

        $serialized = json_encode($connection->toArray(), JSON_THROW_ON_ERROR).$connection->toJson();
        foreach ([self::KEY_A, 'whsec_'.$this->suffix(self::KEY_A)] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
            $this->assertStringNotContainsString($secret, print_r($connection, true));
        }
        $this->assertArrayNotHasKey('secret', $connection->toArray());
        $this->assertArrayNotHasKey('webhook_secret', $connection->toArray());
        $this->assertSame('rk_live_…a1b2', $connection->secret_hint);

        $page = $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertOk()
            ->assertInertia(fn (Assert $inertia) => $inertia->component('workspaces/payments')
                ->where('canManage', true)
                ->where('processors.0.connection.key_hint', 'rk_live_…a1b2'));
        $body = $page->getContent();
        foreach ([self::KEY_A, 'whsec_'.$this->suffix(self::KEY_A), $connection->webhookSecret()] as $secret) {
            $this->assertStringNotContainsString((string) $secret, (string) $body);
        }

        // A refused key is not echoed into the log either.
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => 'rk_live_rejected_by_stripe_zzzz']);
        /** @var TestHandler $handler */
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        foreach ($handler->getRecords() as $record) {
            $this->assertStringNotContainsString('synthetic_workspace_a', json_encode($record->toArray(), JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('rejected_by_stripe', json_encode($record->toArray(), JSON_THROW_ON_ERROR));
        }
    }

    // ---- 4 & 6 & 7: charging resolves from the invoice's workspace ----

    public function test_a_charge_uses_the_invoice_workspaces_key_and_never_config_or_another_workspace(): void
    {
        [$ownerA, $a, $companyA] = $this->tenant('a');
        [$ownerB, $b, $companyB] = $this->tenant('b');
        $this->actingAs($ownerA)->post($this->url($a), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($b), ['secret' => self::KEY_B])->assertNoContent();
        // The deployment's key is present and must be ignored once connections exist.
        config(['services.stripe.secret_key' => 'sk_live_environment_must_not_be_used']);

        $this->charge($this->invoice($a, $companyA, 'A'), $a, 'idem-a');
        $this->charge($this->invoice($b, $companyB, 'B'), $b, 'idem-b');

        $intents = $this->callsTo('post', '/v1/payment_intents');
        $this->assertSame([self::KEY_A, self::KEY_B], array_column($intents, 'key'));
        $this->assertSame($a->public_id, $intents[0]['params']['metadata']['workspace_public_id']);
        $this->assertSame($b->public_id, $intents[1]['params']['metadata']['workspace_public_id']);
    }

    public function test_a_workspace_with_no_connection_cannot_charge_once_connections_exist(): void
    {
        [$ownerA, $a] = $this->tenant('has');
        [, $none, $company] = $this->tenant('none');
        $this->actingAs($ownerA)->post($this->url($a), ['secret' => self::KEY_A])->assertNoContent();
        config(['services.stripe.secret_key' => 'sk_live_environment_must_not_be_used']);
        // The migration command has run for Stripe: the environment path is retired.
        $this->markEnvironmentMigrated($a);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stripe is not connected for this workspace.');
        try {
            $this->charge($this->invoice($none, $company, 'N'), $none, 'idem-n');
        } finally {
            $this->assertSame([], $this->callsTo('post', '/v1/payment_intents'));
        }
    }

    public function test_revoking_a_connection_stops_charges_immediately_and_never_falls_back_to_the_environment(): void
    {
        [$owner, $workspace, $company] = $this->tenant('revoke');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $invoice = $this->invoice($workspace, $company, 'R');
        $this->charge($invoice, $workspace, 'idem-1');

        $this->actingAs($owner)->delete($this->url($workspace))->assertNoContent();
        config(['services.stripe.secret_key' => 'sk_live_environment_must_not_be_used']);

        try {
            $this->charge($this->invoice($workspace, $company, 'R2'), $workspace, 'idem-2');
            $this->fail('A revoked connection must not charge.');
        } catch (DomainException $refused) {
            $this->assertSame('Stripe is not connected for this workspace.', $refused->getMessage());
        }
        $this->assertCount(1, $this->callsTo('post', '/v1/payment_intents'));
        // The stored key went with the revocation.
        $this->assertNull(WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->first()?->secret());
    }

    // ---- 6: isolation ----

    public function test_a_workspace_cannot_read_or_revoke_another_workspaces_connection(): void
    {
        [$ownerA, $a] = $this->tenant('iso-a');
        [$ownerB, $b] = $this->tenant('iso-b');
        $this->actingAs($ownerB)->post($this->url($b), ['secret' => self::KEY_B])->assertNoContent();

        // A's owner is not a member of B: B's page and B's connection are closed to them.
        $this->actingAs($ownerA)->get(route('workspaces.payments', $b))->assertForbidden();
        $this->actingAs($ownerA)->delete($this->url($b))->assertForbidden();
        $this->actingAs($ownerA)->post($this->url($b), ['secret' => self::KEY_A])->assertForbidden();
        $this->assertSame(self::KEY_B, $this->activeFor($b)?->secret());

        // Nothing of B is visible on A's page.
        $this->actingAs($ownerA)->get(route('workspaces.payments', $a))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('processors.0.connection', null)->where('processors.0.reusable', []));
    }

    public function test_the_repository_never_returns_another_workspaces_row(): void
    {
        [$ownerA, $a] = $this->tenant('repo-a');
        [$ownerB, $b] = $this->tenant('repo-b');
        $this->actingAs($ownerB)->post($this->url($b), ['secret' => self::KEY_B])->assertNoContent();

        $connections = app(WorkspacePaymentConnections::class);
        $this->assertNull($connections->activeRow($a, PaymentProcessor::Stripe));
        $this->assertNull($connections->active($a, PaymentProcessor::Stripe));
        $this->assertFalse($connections->everConnected($a, PaymentProcessor::Stripe));
        $this->assertNotNull($connections->activeRow($b, PaymentProcessor::Stripe));
    }

    // ---- 5: webhooks ----

    public function test_a_webhook_is_verified_with_the_secret_of_the_endpoint_it_arrived_at(): void
    {
        [$ownerA, $a, $companyA] = $this->tenant('hook-a');
        [$ownerB, $b] = $this->tenant('hook-b');
        $this->actingAs($ownerA)->post($this->url($a), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($b), ['secret' => self::KEY_B])->assertNoContent();
        $invoice = $this->invoice($a, $companyA, 'HA');
        $payload = $this->succeeded($invoice, $a);
        $secretA = 'whsec_'.$this->suffix(self::KEY_A);
        $secretB = 'whsec_'.$this->suffix(self::KEY_B);

        // B's secret cannot authorise a delivery to A's endpoint.
        $this->hook($this->activeFor($a)->endpoint_token, $payload, $secretB)->assertStatus(400);
        $this->assertDatabaseCount('client_stripe_events', 0);
        $this->assertDatabaseCount('client_invoice_payments', 0);

        // An unknown token, a malformed one and a guessed sequential id are all 4xx.
        $this->hook(str_repeat('a', 40), $payload, $secretA)->assertNotFound();
        $this->post('/api/webhooks/stripe/1', [], [])->assertNotFound();
        $this->assertDatabaseCount('client_stripe_events', 0);

        // Its own secret at its own endpoint is applied.
        $this->hook($this->activeFor($a)->endpoint_token, $payload, $secretA)->assertOk()->assertJsonPath('duplicate', false);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_an_event_naming_another_workspace_is_logged_and_ignored_not_applied_to_it(): void
    {
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        [$ownerA, $a] = $this->tenant('cross-a');
        [$ownerB, $b, $companyB] = $this->tenant('cross-b');
        $this->actingAs($ownerA)->post($this->url($a), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($ownerB)->post($this->url($b), ['secret' => self::KEY_B])->assertNoContent();
        $invoiceB = $this->invoice($b, $companyB, 'XB');

        // Signed correctly by A's endpoint, but the payment names B's invoice and workspace.
        $payload = $this->succeeded($invoiceB, $b);
        $this->hook($this->activeFor($a)->endpoint_token, $payload, 'whsec_'.$this->suffix(self::KEY_A))->assertOk();

        $this->assertDatabaseCount('client_invoice_payments', 0);
        $this->assertSame('issued', $invoiceB->fresh()->status);
        /** @var TestHandler $handler */
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->assertTrue($handler->hasWarningThatContains('not connected to its account'));
    }

    public function test_a_revoked_endpoint_receives_nothing(): void
    {
        [$owner, $workspace, $company] = $this->tenant('dead');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $token = $this->activeFor($workspace)->endpoint_token;
        $secret = 'whsec_'.$this->suffix(self::KEY_A);
        $this->actingAs($owner)->delete($this->url($workspace))->assertNoContent();

        $this->hook($token, $this->succeeded($this->invoice($workspace, $company, 'D'), $workspace), $secret)->assertNotFound();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    // ---- 8: reuse ----

    public function test_a_workspace_of_the_same_owner_can_reuse_a_connection_and_charges_with_its_key(): void
    {
        [$owner, $source] = $this->tenant('src');
        $target = $this->secondWorkspace($owner, 'tgt');
        $company = ClientCompany::query()->create(['workspace_id' => $target->id, 'name' => 'Reuse Client', 'slug' => 'reuse-client']);
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A])->assertNoContent();
        $sourceRow = $this->activeFor($source);

        $this->actingAs($owner)->get(route('workspaces.payments', $target))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.reusable.0.id', $sourceRow->public_id)
            ->where('processors.0.reusable.0.label', 'Use the Stripe account connected to '.$source->name));
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $sourceRow->public_id])->assertNoContent();

        $this->charge($this->invoice($target, $company, 'RU'), $target, 'idem-ru');
        $this->assertSame([self::KEY_A], array_column($this->callsTo('post', '/v1/payment_intents'), 'key'));
        // The reused row holds no key of its own and no endpoint of its own to delete.
        $reused = $this->activeFor($target);
        $this->assertNull($reused?->secret());
        $this->assertSame(self::KEY_A, app(WorkspacePaymentConnections::class)->credentialSource($reused)?->secret());
    }

    // ---- publishable key ----

    public function test_a_publishable_key_in_the_restricted_keys_mode_is_stored_and_shown_on_the_page(): void
    {
        [$owner, $workspace] = $this->tenant('pk');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => '  pk_live_syntheticpublishable0001  '])->assertNoContent();

        $this->assertSame('pk_live_syntheticpublishable0001', $this->activeFor($workspace)?->publishable_key);
        $this->actingAs($owner)->get(route('workspaces.payments', $workspace))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.publishable_key', 'pk_live_syntheticpublishable0001'));
    }

    public function test_the_publishable_key_is_optional_and_a_blank_one_is_stored_as_none(): void
    {
        [$owner, $workspace] = $this->tenant('pk-none');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => ''])->assertNoContent();
        $this->assertNull($this->activeFor($workspace)?->publishable_key);

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => '   '])->assertNoContent();
        $this->assertNull($this->activeFor($workspace)?->publishable_key);
    }

    public function test_a_publishable_key_of_the_other_mode_or_of_no_mode_is_refused_and_stores_nothing(): void
    {
        [$owner, $workspace] = $this->tenant('pk-mode');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $reads = count($this->callsTo('get', '/v1/account'));

        foreach (['pk_test_syntheticpublishable0002', 'sk_live_not_a_publishable_key', 'pk_live_', 'pk_live_has spaces in it'] as $wrong) {
            $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B, 'publishable_key' => $wrong])
                ->assertSessionHasErrors('publishable_key')
                ->assertSessionDoesntHaveErrors('secret');
        }

        // Refused before Stripe is called, and the earlier connection stands.
        $this->assertSame($reads, count($this->callsTo('get', '/v1/account')));
        $this->assertSame(self::KEY_A, $this->activeFor($workspace)?->secret());
        $this->assertSame('pk_live_syntheticpublishable0001', $this->activeFor($workspace)?->publishable_key);
    }

    // ---- publishable key, checked against the account ----

    public function test_a_publishable_key_is_read_back_through_a_setup_intent_the_restricted_key_made_and_cancels(): void
    {
        [$owner, $workspace] = $this->tenant('pk-live-check');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();

        $calls = array_values(array_filter($this->keyedStripeCalls, static fn (array $call): bool => str_starts_with($call['path'], '/v1/setup_intents')));
        $this->assertSame(
            [['post', '/v1/setup_intents', self::KEY_A], ['get', '/v1/setup_intents/seti_'.$this->suffix(self::KEY_A).'_1', 'pk_live_syntheticpublishable0001'], ['post', '/v1/setup_intents/seti_'.$this->suffix(self::KEY_A).'_1/cancel', self::KEY_A]],
            array_map(static fn (array $call): array => [$call['method'], $call['path'], $call['key']], $calls),
        );
        // No customer, no payment method; the client secret is what proves the account.
        $this->assertSame(['usage' => 'off_session'], $calls[0]['params']);
        $this->assertSame(['client_secret' => 'seti_'.$this->suffix(self::KEY_A).'_1_secret_synthetic'], $calls[1]['params']);
        $this->assertSame('pk_live_syntheticpublishable0001', $this->activeFor($workspace)?->publishable_key);
    }

    public function test_a_publishable_key_of_another_account_is_a_field_error_and_stores_and_registers_nothing(): void
    {
        [$owner, $workspace] = $this->tenant('pk-other-account');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $endpoints = count($this->callsTo('post', '/v1/webhook_endpoints'));

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B, 'publishable_key' => 'pk_live_otheraccount0000002'])
            ->assertSessionHasErrors(['publishable_key' => 'This publishable key belongs to a different Stripe account than the restricted key.'])
            ->assertSessionDoesntHaveErrors('secret');

        $this->assertSame(self::KEY_A, $this->activeFor($workspace)?->secret());
        $this->assertSame('pk_live_syntheticpublishable0001', $this->activeFor($workspace)?->publishable_key);
        $this->assertSame($endpoints, count($this->callsTo('post', '/v1/webhook_endpoints')));
        $this->assertSame(2, count($this->callsTo('post', '/v1/setup_intents')));
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: int}> */
    public static function checksThatCannotReachAnAnswer(): array
    {
        return [
            'the restricted key may not create SetupIntents' => ['rk_live_synthetic_no_setup_perm_a1', 'pk_live_syntheticpublishable0001', 'secret', 'set SetupIntents to Write', 0],
            'Stripe cannot be reached' => ['rk_live_synthetic_workspace_a_a1b2', 'pk_live_unreachable00000001', 'publishable_key', 'could not reach Stripe', 1],
            'Stripe answers with an error' => ['rk_live_synthetic_workspace_a_a1b2', 'pk_live_server5xx000000001', 'publishable_key', 'no usable answer', 1],
            'Stripe does not accept the publishable key' => ['rk_live_synthetic_workspace_a_a1b2', 'pk_live_rolledkey00000001', 'publishable_key', 'did not accept this publishable key', 1],
        ];
    }

    #[DataProvider('checksThatCannotReachAnAnswer')]
    public function test_a_check_that_cannot_reach_an_answer_refuses_the_save_and_never_accepts_silently(string $secret, string $publishable, string $field, string $says, int $created): void
    {
        [$owner, $workspace] = $this->tenant('pk-unverifiable');

        $response = $this->actingAs($owner)->post($this->url($workspace), ['secret' => $secret, 'publishable_key' => $publishable]);

        $response->assertSessionHasErrors($field);
        $this->assertStringContainsString($says, (string) session('errors')->first($field));
        $this->assertNull($this->activeFor($workspace));
        $this->assertSame([], $this->callsTo('post', '/v1/webhook_endpoints'));
        // Whatever was created is cancelled, so a refused check leaves nothing open.
        $this->assertSame($created, count(array_filter($this->keyedStripeCalls, static fn (array $call): bool => str_ends_with($call['path'], '/cancel'))));
    }

    public function test_the_mode_check_runs_first_and_no_check_is_made_without_a_publishable_key(): void
    {
        [$owner, $workspace] = $this->tenant('pk-order');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => 'pk_test_syntheticpublishable0002'])
            ->assertSessionHasErrors('publishable_key');
        $this->assertSame([], $this->keyedStripeCalls);

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->assertSame([], $this->callsTo('post', '/v1/setup_intents'));
    }

    public function test_a_check_that_cannot_cancel_still_reports_what_it_found_and_never_fails_a_good_save(): void
    {
        [$owner, $workspace] = $this->tenant('pk-cancel');
        $key = 'rk_live_synthetic_cancel_fails_e5';
        config(['logging.default' => 'capture', 'logging.channels.capture' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug']]);
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => $key, 'publishable_key' => 'pk_live_otheraccount0000003'])
            ->assertSessionHasErrors(['publishable_key' => 'This publishable key belongs to a different Stripe account than the restricted key.']);
        $this->assertNull($this->activeFor($workspace));

        $this->actingAs($owner)->post($this->url($workspace), ['secret' => $key, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $this->assertSame('pk_live_syntheticpublishable0001', $this->activeFor($workspace)?->publishable_key);
        /** @var TestHandler $handler */
        $handler = Log::channel('capture')->getLogger()->getHandlers()[0];
        $this->assertTrue($handler->hasWarningThatContains('could not be cancelled'));
        $this->assertFalse($handler->hasRecordThatContains('cancel_fails', Level::Debug));
    }

    public function test_replacing_the_key_without_a_publishable_key_drops_the_old_one(): void
    {
        [$owner, $workspace] = $this->tenant('pk-replace');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_B])->assertNoContent();

        $this->assertNull($this->activeFor($workspace)?->publishable_key);
    }

    public function test_a_reused_connection_shows_and_uses_the_sources_publishable_key(): void
    {
        [$owner, $source] = $this->tenant('pk-src');
        $target = $this->secondWorkspace($owner, 'pk-tgt');
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $this->activeFor($source)?->public_id])->assertNoContent();

        $this->assertNull($this->activeFor($target)?->publishable_key);
        $this->assertSame('pk_live_syntheticpublishable0001', app(PaymentConnectionResolver::class)->stripePublishableKey($target));
        $this->actingAs($owner)->get(route('workspaces.payments', $target))->assertInertia(fn (Assert $page) => $page
            ->where('processors.0.connection.publishable_key', 'pk_live_syntheticpublishable0001'));
    }

    public function test_a_connection_with_no_publishable_key_resolves_none_and_never_the_deployments(): void
    {
        config(['services.stripe.publishable_key' => 'pk_live_deployment_key_0001']);
        [$owner, $workspace] = $this->tenant('pk-fail');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();

        $this->assertNull(app(PaymentConnectionResolver::class)->stripePublishableKey($workspace));
    }

    public function test_a_connection_made_from_the_environment_uses_the_deployments_publishable_key(): void
    {
        config(['services.stripe.publishable_key' => 'pk_live_deployment_key_0001']);
        [$owner, $workspace] = $this->tenant('pk-env');
        $this->actingAs($owner)->post($this->url($workspace), ['secret' => self::KEY_A])->assertNoContent();
        $this->markEnvironmentMigrated($workspace);

        $this->assertSame('pk_live_deployment_key_0001', app(PaymentConnectionResolver::class)->stripePublishableKey($workspace));

        config(['services.stripe.publishable_key' => '']);
        $this->assertNull(app(PaymentConnectionResolver::class)->stripePublishableKey($workspace));
    }

    public function test_a_revoked_source_leaves_a_reusing_workspace_with_no_publishable_key(): void
    {
        [$owner, $source] = $this->tenant('pk-rev-src');
        $target = $this->secondWorkspace($owner, 'pk-rev-tgt');
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A, 'publishable_key' => 'pk_live_syntheticpublishable0001'])->assertNoContent();
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $this->activeFor($source)?->public_id])->assertNoContent();
        $this->actingAs($owner)->delete($this->url($source))->assertNoContent();

        $this->assertNull(app(PaymentConnectionResolver::class)->stripePublishableKey($target));
    }

    public function test_a_connection_is_not_reusable_across_different_owners(): void
    {
        [$ownerA, $a] = $this->tenant('own-a');
        [$ownerB, $b] = $this->tenant('own-b');
        $this->actingAs($ownerA)->post($this->url($a), ['secret' => self::KEY_A])->assertNoContent();
        $sourceId = $this->activeFor($a)->public_id;
        // The second owner manages B and also administers A, but does not own both.
        $a->memberships()->create(['user_id' => $ownerB->id, 'role' => 'admin']);

        $this->actingAs($ownerB)->get(route('workspaces.payments', $b))->assertInertia(fn (Assert $page) => $page->where('processors.0.reusable', []));
        $this->actingAs($ownerB)->post($this->url($b).'/reuse', ['source' => $sourceId])->assertSessionHasErrors('source');
        $this->assertNull($this->activeFor($b));

        // Even the service refuses it when called directly.
        $this->expectException(DomainException::class);
        app(WorkspacePaymentConnections::class)->reuse($b, $this->activeFor($a), $ownerB);
    }

    public function test_revoking_the_source_stops_every_workspace_that_reuses_it(): void
    {
        [$owner, $source] = $this->tenant('rs-src');
        $target = $this->secondWorkspace($owner, 'rs-tgt');
        $company = ClientCompany::query()->create(['workspace_id' => $target->id, 'name' => 'Reuse Client', 'slug' => 'reuse-client-2']);
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $this->activeFor($source)->public_id])->assertNoContent();
        $invoice = $this->invoice($target, $company, 'RS');

        $this->actingAs($owner)->delete($this->url($source))->assertNoContent();

        $this->assertNull($this->activeFor($target));
        $this->expectException(DomainException::class);
        $this->charge($invoice, $target, 'idem-rs');
    }

    public function test_a_reused_connection_delivers_events_to_the_workspace_that_borrows_it(): void
    {
        [$owner, $source] = $this->tenant('ev-src');
        $target = $this->secondWorkspace($owner, 'ev-tgt');
        $company = ClientCompany::query()->create(['workspace_id' => $target->id, 'name' => 'Reuse Client', 'slug' => 'reuse-client-3']);
        $this->actingAs($owner)->post($this->url($source), ['secret' => self::KEY_A])->assertNoContent();
        $this->actingAs($owner)->post($this->url($target).'/reuse', ['source' => $this->activeFor($source)->public_id])->assertNoContent();
        $invoice = $this->invoice($target, $company, 'EV');

        $token = WorkspacePaymentConnection::query()->where('workspace_id', $source->id)->value('endpoint_token');
        $this->hook($token, $this->succeeded($invoice, $target), 'whsec_'.$this->suffix(self::KEY_A))->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);

        // The borrowing row's own token is not an endpoint.
        $own = WorkspacePaymentConnection::query()->where('workspace_id', $target->id)->value('endpoint_token');
        $this->hook($own, $this->succeeded($invoice, $target), 'whsec_'.$this->suffix(self::KEY_A))->assertNotFound();
    }

    // ---- helpers ----

    /** @return list<array{method: string, path: string, params: array<string, mixed>, key: string}> */
    private function callsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->keyedStripeCalls, static fn (array $call): bool => $call['method'] === $method && $call['path'] === $path));
    }

    private function suffix(string $key): string
    {
        return substr(hash('sha256', $key), 0, 8);
    }

    private function url(Workspace $workspace): string
    {
        return '/workspaces/'.$workspace->public_id.'/payments/stripe';
    }

    private function activeFor(Workspace $workspace): ?WorkspacePaymentConnection
    {
        return WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->where('processor', 'stripe')->whereNull('revoked_at')->first();
    }

    /** Mark the environment migration as done, without a real environment account. */
    private function markEnvironmentMigrated(Workspace $workspace): void
    {
        $row = $this->activeFor($workspace);
        $this->assertNotNull($row);
        DB::table('workspace_payment_connections')->where('id', $row->id)->update(['origin' => 'environment']);
    }

    private function staff(Workspace $workspace, string $role): User
    {
        $user = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    private function secondWorkspace(User $owner, string $suffix): Workspace
    {
        $workspace = Workspace::query()->create(['name' => 'Second Workspace '.$suffix, 'slug' => 'second-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);

        return $workspace;
    }

    /** @return array{0:User,1:Workspace,2:ClientCompany} */
    private function tenant(string $suffix): array
    {
        $owner = User::factory()->create(['email' => 'payments-owner-'.$suffix.'@synthetic.test']);
        $workspace = Workspace::query()->create(['name' => 'Payments Workspace '.$suffix, 'slug' => 'payments-'.$suffix]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Payments Client '.$suffix, 'slug' => 'payments-client-'.$suffix]);

        return [$owner, $workspace, $company];
    }

    private function invoice(Workspace $workspace, ClientCompany $company, string $number): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);
        $invoice = $service->createDraft($workspace, $company, [
            'invoice_number' => 'INV-PAY-'.$number, 'currency' => 'USD', 'issue_date' => '2026-08-15', 'due_date' => '2026-09-14',
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
    private function hook(string $token, string $payload, string $secret): TestResponse
    {
        $timestamp = time();

        return $this->call('POST', '/api/webhooks/stripe/'.$token, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret),
        ], $payload);
    }
}
