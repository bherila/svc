<?php

namespace Tests\Feature\Billing\Autopay;

use App\Models\ClientCompany;
use App\Models\ClientCompanyActivity;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceAutopayAttempt;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\Autopay\AutopayChargeService;
use App\Services\Billing\StripeGateway;
use App\Services\Payments\StripeAccess;
use App\Services\Payments\StripeConnectionHealth;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\AgentApi\Presenters\AutopayPresenter;
use App\Support\Billing\Autopay\AutopayAttemptState;
use App\Support\Billing\Autopay\AutopayChargeResult;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsAutopay;
use Tests\TestCase;

/**
 * Stripe refusing the provider's own key or account is not the client's card
 * failing (#641): nothing is charged, the invoice keeps its one attempt for the
 * first run after the connection is fixed, the provider is told, and a broken
 * key is not hammered every fifteen minutes.
 */
final class AutopayConnectionRefusalTest extends TestCase
{
    use BuildsAutopay;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->restoreAutopayStripe();
        parent::tearDown();
    }

    /** @return array{0: Workspace, 1: ClientCompany, 2: ClientInvoice} */
    private function dueInvoice(): array
    {
        $this->travelTo('2030-10-03 18:00:00');
        [$workspace, , $company, $contact] = $this->autopayFixture();
        $this->turnAutopayOn($workspace, $company, $contact, 500_000);
        $invoice = $this->autopayInvoice($company);
        $this->travelTo('2030-12-01 15:00:00');

        return [$workspace, $company->refresh(), $invoice];
    }

    /** @return array{0: array<string, mixed>, 1: int} */
    private static function refusal(int $status, string $type, ?string $code = null): array
    {
        return [['error' => ['type' => $type, 'message' => 'Synthetic refusal.'] + ($code === null ? [] : ['code' => $code])], $status];
    }

    /** @return iterable<string, array{int, string, string|null}> */
    public static function connectionRefusals(): iterable
    {
        yield 'a revoked key' => [401, 'authentication_error', null];
        yield 'a key without the permission' => [403, 'invalid_request_error', 'secret_key_required'];
        yield 'an expired key' => [400, 'invalid_request_error', 'api_key_expired'];
        yield 'an unusable account' => [400, 'invalid_request_error', 'account_invalid'];
    }

    #[DataProvider('connectionRefusals')]
    public function test_a_refused_connection_charges_no_one_spends_no_attempt_and_tells_the_provider(int $status, string $type, ?string $code): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal($status, $type, $code));

        $outcomes = app(AutopayChargeService::class)->run(50, true);

        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $outcomes[0]->result);
        $this->assertStringContainsString('Not charged yet: the Stripe connection needs attention', (string) $outcomes[0]->detail);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count(), 'The claim was given back: nothing is shown to the client as a failure.');
        $this->assertSame(0, ClientInvoicePayment::query()->count());
        $this->assertSame(['issued', 375_000], [$invoice->fresh()->status, $invoice->fresh()->balance_amount]);
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.autopay_failed')->count());
        $activity = ClientCompanyActivity::query()->where('action', 'invoice.autopay_connection_refused')->sole();
        $this->assertSame($invoice->public_id, $activity->subject_public_id);
        $this->assertNotNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
    }

    public function test_the_client_is_shown_nothing_and_the_provider_sees_the_warning_on_both_billing_cards(): void
    {
        [$workspace, $company, $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        app(AutopayChargeService::class)->run(50, true);
        $presenter = app(AutopayPresenter::class);

        $provider = $presenter->invoice($workspace, $invoice, forProvider: true);
        $this->assertSame('will_charge', $provider['state']);
        $this->assertSame('Not charged yet: the Stripe connection needs attention. Autopay charges on the first run after it is fixed.', $provider['connection_problem']['message']);
        $this->assertSame('2030-12-01T15:00:00Z', $provider['connection_problem']['since']);
        $this->assertNull($provider['attempt']);
        $this->assertNotNull($presenter->company($workspace, $company, forProvider: true)['connection_problem']);

        // The client's reads carry no warning and no failure: the invoice simply still reads "will be charged".
        $client = $presenter->invoice($workspace, $invoice, $company);
        $this->assertNull($client['connection_problem']);
        $this->assertSame('will_charge', $client['state']);
        $this->assertNull($presenter->company($workspace, $company)['connection_problem']);
    }

    public function test_a_refused_workspace_is_left_alone_until_the_backoff_passes_and_is_not_announced_twice(): void
    {
        [$workspace] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        $service = app(AutopayChargeService::class);
        $service->run(50, true);
        $this->assertCount(1, $this->autopayCharges());

        // The next run, fifteen minutes later, does not call Stripe again, and says why.
        $this->travel(15)->minutes();
        $outcomes = $service->run(50, true);
        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges(), 'A broken key is not hammered every fifteen minutes.');
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());

        // Just inside the backoff: still waiting. Just past it: one new try, which is refused again.
        $this->travelTo('2030-12-01 20:59:00');
        $service->run(50, true);
        $this->assertCount(1, $this->autopayCharges());
        $this->travelTo('2030-12-01 21:01:00');
        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $service->run(50, true)[0]->result);
        $this->assertCount(2, $this->autopayCharges());
        $this->assertSame(1, ClientCompanyActivity::query()->where('action', 'invoice.autopay_connection_refused')->count(), 'The provider is told once per breakage.');
        $this->assertSame(0, InvoiceAutopayAttempt::query()->count());
        // The refusal is dated from the latest one, so the next wait is a full backoff again.
        $this->assertSame('2030-12-01 21:01:00', app(StripeConnectionHealth::class)->refusedAt($workspace)?->format('Y-m-d H:i:s'));
    }

    public function test_the_first_run_after_the_connection_is_fixed_charges_the_invoice_once(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        $service = app(AutopayChargeService::class);
        $service->run(50, true);

        // The key is replaced: the connection is saved again, which ends the pause at once.
        $this->travel(20)->minutes();
        app(WorkspacePaymentConnections::class)->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
            'external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_autopay_new',
        ]);
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
        $this->assertNull(app(AutopayPresenter::class)->invoice($workspace, $invoice, forProvider: true)['connection_problem']);
        $this->autopayStripeCalls = [];
        $this->fakeAutopayStripe();

        $outcomes = $service->run(50, true);

        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $attempt = InvoiceAutopayAttempt::query()->sole();
        $this->assertSame(AutopayAttemptState::Submitted, $attempt->state);
        $this->assertSame([], $service->run(50, true));
        $this->assertSame($invoice->id, $attempt->client_invoice_id);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function inFlightConnections(): iterable
    {
        foreach (['entered', 'legacy', 'legacy-key', 'reused', 'reused-source'] as $path) {
            yield $path.' same second' => [$path, false];
            yield $path.' later response' => [$path, true];
        }
    }

    #[DataProvider('inFlightConnections')]
    public function test_a_late_refusal_cannot_pause_replacement_credentials(string $path, bool $later): void
    {
        [$workspace, $company, $invoice] = $this->dueInvoice();
        $replace = $this->inFlightConnection($workspace, $path);
        $this->fakeAutopayStripe(function () use ($replace, $later): array {
            $replace();
            if ($later) {
                $this->travel(1)->minutes();
            }

            return self::refusal(401, 'authentication_error');
        });
        $service = app(AutopayChargeService::class);

        $this->assertSame(AutopayChargeResult::Released, $service->run(50, true)[0]->result);
        $this->assertSame(0, InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(0, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
        $this->assertFalse(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertNull(app(AutopayPresenter::class)->invoice($workspace, $invoice, forProvider: true)['connection_problem']);
        $this->assertSame(0, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.autopay_connection_refused')->count());
        $this->autopayStripeCalls = [];
        $this->fakeAutopayStripe(function (string $method, string $requestPath, array $params, array $headers): array {
            $this->assertContains('Authorization: Bearer sk_test_synthetic_replacement_B', $headers);

            return [['id' => 'pi_synthetic_replacement_B', 'object' => 'payment_intent', 'status' => 'succeeded']];
        });
        if ($path === 'legacy-key') {
            config(['services.stripe.secret_key' => 'sk_test_synthetic_replacement_B']);
        }

        $this->assertSame(AutopayChargeResult::Submitted, $service->run(50, true)[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame([], $service->run(50, true));
        $this->assertSame($company->id, InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->sole()->client_company_id);
    }

    /** @return iterable<string, array{string, string}> */
    public static function staleAcceptedAnswers(): iterable
    {
        foreach (['entered', 'legacy', 'legacy-key', 'reused', 'reused-source'] as $path) {
            yield $path.' succeeds' => [$path, 'succeeded'];
        }
        yield 'card decline still accepts old key' => ['entered', 'card_declined'];
        yield 'charge error still accepts old key' => ['entered', 'resource_missing'];
    }

    #[DataProvider('staleAcceptedAnswers')]
    public function test_a_late_accepted_answer_preserves_replacement_refusal_and_records_the_real_old_outcome(string $path, string $answer): void
    {
        [$workspace, $company, $invoice] = $this->dueInvoice();
        $replace = $this->inFlightConnection($workspace, $path);
        $replacementInvoice = null;
        $replacementRefused = false;
        $this->fakeAutopayStripe(function () use ($company, $replace, $answer, &$replacementInvoice, &$replacementRefused): array {
            if ($replacementRefused) {
                return self::refusal(401, 'authentication_error');
            }
            $replace();
            $replacementInvoice = $this->autopayInvoice($company, 'INV-REPLACEMENT-B');
            $replacementRefused = true;
            // A remains claimed. A second request actually uses and refuses B
            // while A's response is still in flight, including in the same second.
            $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, app(AutopayChargeService::class)->run(1, true)[0]->result);
            if ($answer === 'succeeded') {
                return [['id' => 'pi_synthetic_old_A', 'object' => 'payment_intent', 'status' => 'succeeded']];
            }
            if ($answer === 'card_declined') {
                return [['error' => ['type' => 'card_error', 'code' => 'card_declined', 'message' => 'Synthetic card declined.', 'payment_intent' => ['id' => 'pi_synthetic_old_A', 'object' => 'payment_intent']]], 402];
            }

            return self::refusal(400, 'invalid_request_error', 'resource_missing');
        });

        $outcomes = app(AutopayChargeService::class)->run(1, true);

        $this->assertSame($answer === 'succeeded' ? AutopayChargeResult::Submitted : AutopayChargeResult::Failed, $outcomes[0]->result);
        $attempt = InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->sole();
        $this->assertSame($answer === 'succeeded' ? AutopayAttemptState::Submitted : AutopayAttemptState::Failed, $attempt->state);
        if ($answer !== 'resource_missing') {
            $this->assertSame('pi_synthetic_old_A', $attempt->payment_intent_id);
            $this->assertSame('pi_synthetic_old_A', ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->sole()->provider_payment_identifier);
        }
        $this->assertNotNull($replacementInvoice);
        $this->assertNotNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
        $this->assertTrue(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertNotNull(app(AutopayPresenter::class)->invoice($workspace, $replacementInvoice, forProvider: true)['connection_problem']);
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.autopay_connection_refused')->count());
        $calls = count($this->autopayCharges());
        app(AutopayChargeService::class)->run(50, true);
        $this->assertCount($calls, $this->autopayCharges(), 'The real refusal of B keeps its backoff after old A answers.');
    }

    /** Install A and return a same-account replacement B; no timestamp distinguishes them. */
    private function inFlightConnection(Workspace $workspace, string $path): \Closure
    {
        $connections = app(WorkspacePaymentConnections::class);
        $account = ['external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_request_A'];
        if ($path === 'entered') {
            $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, $account);
        }
        $sourceWorkspace = null;
        $owner = null;
        if (str_starts_with($path, 'reused')) {
            $owner = User::query()->findOrFail($workspace->memberships()->where('role', 'owner')->value('user_id'));
            $sourceWorkspace = Workspace::query()->create(['name' => 'Synthetic credential source', 'slug' => 'synthetic-credential-source']);
            $sourceWorkspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
            $source = $connections->connect($sourceWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, $account);
            $connections->reuse($workspace, $source, $owner);
        }

        return function () use ($workspace, $path, $connections, $account, $sourceWorkspace, $owner): void {
            $replacement = $account;
            $replacement['secret'] = 'sk_test_synthetic_replacement_B';
            if ($path === 'legacy-key') {
                config(['services.stripe.secret_key' => $replacement['secret']]);
            } elseif ($path === 'reused-source') {
                $source = $connections->connect($sourceWorkspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, $replacement);
                $connections->reuse($workspace, $source, $owner);
            } else {
                $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, $replacement);
            }
        };
    }

    public function test_same_second_reconnect_with_the_same_key_is_a_new_health_identity(): void
    {
        [$workspace] = $this->dueInvoice();
        $connections = app(WorkspacePaymentConnections::class);
        $account = ['external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_same_key'];
        $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, $account);
        $old = app(StripeGateway::class)->access($workspace);
        $this->assertNotNull($old);
        $health = app(StripeConnectionHealth::class);
        $this->assertTrue($health->refuse($workspace, $old));
        $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, $account);
        $this->assertNull($health->refusedAt($workspace), 'A save resets health even in the same clock second.');
        $current = app(StripeGateway::class)->access($workspace);
        $this->assertNotNull($current);
        $this->assertTrue($health->refuse($workspace, $current));
        $this->travel(1)->minutes();
        $this->assertFalse($health->refuse($workspace, $old));
        $this->assertSame('2030-12-01 15:00:00', $health->refusedAt($workspace)?->format('Y-m-d H:i:s'));
        $health->accepted($workspace, $old);
        $this->assertTrue($health->pauses($workspace));
    }

    public function test_a_refused_legacy_key_does_not_pause_its_environment_replacement(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        $service = app(AutopayChargeService::class);
        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $service->run(50, true)[0]->result);
        $this->assertTrue(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertNotNull(app(AutopayPresenter::class)->invoice($workspace, $invoice, forProvider: true)['connection_problem']);
        $this->autopayStripeCalls = [];
        $this->fakeAutopayStripe(function (string $method, string $path, array $params, array $headers): array {
            $this->assertContains('Authorization: Bearer sk_test_synthetic_legacy_repaired_B', $headers);

            return [['id' => 'pi_synthetic_legacy_repaired_B', 'object' => 'payment_intent', 'status' => 'succeeded']];
        });
        config(['services.stripe.secret_key' => 'sk_test_synthetic_legacy_repaired_B']);
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
        $this->assertFalse(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertNull(app(AutopayPresenter::class)->invoice($workspace, $invoice, forProvider: true)['connection_problem']);

        $this->assertSame(AutopayChargeResult::Submitted, $service->run(50, true)[0]->result);
        $this->assertCount(1, $this->autopayCharges());
        $this->assertSame([], $service->run(50, true));
        $this->assertSame(1, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.autopay_connection_refused')->count());
        $this->assertNull(DB::table('workspaces')->where('id', $workspace->id)->value('stripe_refused_credential_version'));
    }

    public function test_each_refused_legacy_key_has_one_notice_and_its_own_backoff(): void
    {
        [$workspace] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        $service = app(AutopayChargeService::class);
        $service->run(50, true);
        $old = DB::table('workspaces')->where('id', $workspace->id)->value('stripe_refused_credential_version');
        $this->assertIsString($old);
        $this->assertStringStartsWith('environment:', $old);
        config(['services.stripe.secret_key' => 'sk_test_synthetic_legacy_still_refused_B']);
        $this->assertFalse(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $service->run(50, true)[0]->result);
        $this->assertTrue(app(StripeConnectionHealth::class)->pauses($workspace));
        $this->assertNotSame($old, DB::table('workspaces')->where('id', $workspace->id)->value('stripe_refused_credential_version'));
        $service->run(50, true);
        $this->assertCount(2, $this->autopayCharges(), 'B is tried once, then its own refusal suppresses repeats.');
        $this->assertSame(2, ClientCompanyActivity::query()->where('workspace_id', $workspace->id)->where('action', 'invoice.autopay_connection_refused')->count());
    }

    public function test_a_cached_key_is_bound_to_its_own_generation_even_after_another_request_reconnects(): void
    {
        [$workspace] = $this->dueInvoice();
        $connections = app(WorkspacePaymentConnections::class);
        $row = $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
            'external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_cached_A',
        ]);
        $gateway = app(StripeGateway::class);
        $old = $gateway->access($workspace);
        $this->assertNotNull($old);
        // A different scoped repository does not invalidate this request's cache.
        (new WorkspacePaymentConnections)->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
            'external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_current_B',
        ]);
        $cached = $gateway->access($workspace);
        $this->assertNotNull($cached);
        $this->assertSame('sk_test_synthetic_cached_A', $cached->client()->getApiKey());
        $this->assertSame($row->id, $cached->credentialIdentity?->connectionId);
        $this->assertFalse(app(StripeConnectionHealth::class)->refuse($workspace, $cached));
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
    }

    public function test_revocation_invalidates_an_in_flight_health_answer(): void
    {
        [$workspace] = $this->dueInvoice();
        $connections = app(WorkspacePaymentConnections::class);
        $row = $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
            'external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_revoked',
        ]);
        $access = app(StripeGateway::class)->access($workspace);
        $this->assertNotNull($access);
        $connections->revoke($row, null);

        $this->assertFalse(app(StripeConnectionHealth::class)->refuse($workspace, $access));
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
        $this->assertSame('disconnected', DB::table('workspaces')->where('id', $workspace->id)->value('stripe_credential_version'));
    }

    public function test_health_transitions_cannot_cross_workspaces_or_use_unbound_access(): void
    {
        [$workspace] = $this->dueInvoice();
        $other = Workspace::query()->create(['name' => 'Synthetic other health', 'slug' => 'synthetic-other-health']);
        $health = app(StripeConnectionHealth::class);
        $access = app(StripeGateway::class)->access($workspace);
        $this->assertNotNull($access);
        DB::table('workspaces')->where('id', $other->id)->update(['stripe_refused_at' => now()]);

        $this->assertFalse($health->refuse($other, $access));
        $health->accepted($other, $access);
        $health->accepted($other, StripeAccess::forKey('sk_test_synthetic_unbound'));
        $this->assertFalse($health->refuse($other, StripeAccess::forKey('sk_test_synthetic_unbound')));
        $this->assertNotNull($health->refusedAt($other));
        $this->assertNull($health->refusedAt($workspace));
    }

    public function test_a_working_answer_from_stripe_ends_the_warning_without_a_re_save(): void
    {
        [$workspace] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));
        $service = app(AutopayChargeService::class);
        $service->run(50, true);
        $health = app(StripeConnectionHealth::class);
        $this->assertNotNull($health->refusedAt($workspace));

        // The same key works again (a Stripe-side permission was restored); the backoff has passed.
        $this->travel(7)->hours();
        $this->fakeAutopayStripe();
        $this->assertSame(AutopayChargeResult::Submitted, $service->run(50, true)[0]->result);

        $this->assertNull($health->refusedAt($workspace));
        $this->assertFalse($health->pauses($workspace));
    }

    public function test_a_declined_card_is_still_one_failed_attempt_and_shows_the_key_works(): void
    {
        [$workspace] = $this->dueInvoice();
        DB::table('workspaces')->where('id', $workspace->id)->update(['stripe_refused_at' => now()->subHours(7)]);
        $this->fakeAutopayStripe(static fn (): array => [['error' => [
            'type' => 'card_error', 'code' => 'card_declined', 'message' => 'Your card was declined.',
        ]], 402]);

        $this->assertSame(AutopayChargeResult::Failed, app(AutopayChargeService::class)->run(50, true)[0]->result);

        $this->assertSame(AutopayAttemptState::Failed, InvoiceAutopayAttempt::query()->sole()->state);
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace), 'A card error means Stripe accepted the key.');
        $this->assertSame(0, ClientCompanyActivity::query()->where('action', 'invoice.autopay_connection_refused')->count());
    }

    public function test_an_invalid_request_about_this_charge_stays_a_failed_attempt(): void
    {
        [$workspace] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(400, 'invalid_request_error', 'resource_missing'));

        $this->assertSame(AutopayChargeResult::Failed, app(AutopayChargeService::class)->run(50, true)[0]->result);

        $this->assertSame(['failed', 'resource_missing'], [InvoiceAutopayAttempt::query()->sole()->state->value, InvoiceAutopayAttempt::query()->sole()->failure_code]);
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($workspace));
    }

    public function test_a_refused_workspace_does_not_hold_up_another_workspaces_invoices(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->travelTo('2030-10-03 18:00:00');
        [$other, , $otherCompany, $otherContact] = $this->autopayFixture('other');
        $this->turnAutopayOn($other, $otherCompany, $otherContact, 500_000);
        $otherInvoice = $this->autopayInvoice($otherCompany, 'INV-0200');
        $this->travelTo('2030-12-01 15:00:00');
        DB::table('workspaces')->where('id', $workspace->id)->update(['stripe_refused_at' => now()->subMinutes(5)]);
        $this->fakeAutopayStripe();

        $outcomes = collect(app(AutopayChargeService::class)->run(1, true))->keyBy('invoiceId');

        // The refused workspace's invoice does not use up the run's limit of one.
        $this->assertSame(AutopayChargeResult::ConnectionNeedsAttention, $outcomes[$invoice->public_id]->result);
        $this->assertSame(AutopayChargeResult::Submitted, $outcomes[$otherInvoice->public_id]->result);
        $this->assertSame([$otherInvoice->id], InvoiceAutopayAttempt::query()->pluck('client_invoice_id')->all());
        $this->assertNull(app(StripeConnectionHealth::class)->refusedAt($other));
    }

    public function test_the_command_names_the_workspace_and_a_preview_reports_the_pause_without_calling_stripe(): void
    {
        [$workspace, , $invoice] = $this->dueInvoice();
        $this->fakeAutopayStripe(fn (): array => self::refusal(401, 'authentication_error'));

        Artisan::call('svc:billing:charge-autopay', ['--apply' => true, '--format' => 'json']);
        $applied = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([$workspace->public_id], $applied['connection_needs_attention']);
        $this->assertSame(['connection_needs_attention', $invoice->public_id], [$applied['outcomes'][0]['result'], $applied['outcomes'][0]['invoice_id']]);

        $calls = count($this->autopayStripeCalls);
        Artisan::call('svc:billing:charge-autopay', ['--apply' => true]);
        $text = Artisan::output();
        $this->assertStringContainsString('Stripe connection needs attention: workspace '.$workspace->public_id, $text);
        $this->assertStringContainsString('connection_needs_attention', $text);
        Artisan::call('svc:billing:charge-autopay', ['--format' => 'json']);
        $preview = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame('connection_needs_attention', $preview['outcomes'][0]['result']);
        $this->assertCount($calls, $this->autopayStripeCalls);
    }

    public function test_the_marker_is_dropped_only_when_the_connection_is_saved_after_it(): void
    {
        [$workspace] = $this->dueInvoice();
        $health = app(StripeConnectionHealth::class);
        $connections = app(WorkspacePaymentConnections::class);
        $save = static fn (): mixed => $connections->connect($workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, null, [
            'external_account_id' => 'acct_synthetic_autopay', 'secret' => 'sk_test_synthetic_autopay',
        ]);

        $save();
        $this->travel(1)->minutes();
        $this->assertTrue($health->refuse($workspace, app(StripeGateway::class)->access($workspace)), 'The first refusal is new.');
        $this->assertFalse($health->refuse($workspace, app(StripeGateway::class)->access($workspace)), 'A repeat is not.');
        $this->assertNotNull($health->refusedAt($workspace));
        $this->assertTrue($health->pauses($workspace));

        // A save made after the refusal ends it: the key is newer than the complaint.
        $this->travel(1)->minutes();
        $save();
        $this->assertNull($health->refusedAt($workspace));
        $this->assertFalse($health->pauses($workspace));
        $this->assertTrue($health->refuse($workspace, app(StripeGateway::class)->access($workspace)), 'A refusal after a save is new again.');

        $this->travel(StripeConnectionHealth::BACKOFF_HOURS * 60 - 1)->minutes();
        $this->assertTrue($health->pauses($workspace));
        $this->travel(2)->minutes();
        $this->assertFalse($health->pauses($workspace));
        $this->assertNotNull($health->refusedAt($workspace), 'The warning outlives the backoff: only a fix ends it.');
    }

    public function test_accepting_a_key_that_was_never_refused_changes_nothing(): void
    {
        [$workspace] = $this->dueInvoice();

        app(StripeConnectionHealth::class)->accepted($workspace, app(StripeGateway::class)->access($workspace));

        $this->assertNull(DB::table('workspaces')->where('id', $workspace->id)->value('stripe_refused_at'));
    }
}
