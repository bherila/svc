<?php

namespace Tests\StripeLive;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripePaymentMethod;
use App\Models\InvoiceAutopayAttempt;
use App\Models\Workspace;
use App\Models\WorkspacePaymentConnection;
use App\Services\Billing\PortalPaymentMethodService;
use App\Support\Billing\Autopay\AutopayAttemptState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\StripeLive\Support\StripeExpectation;

/**
 * Real Stripe test mode, real SVC code. Each scenario creates its own synthetic
 * customer (example.test addresses, `svc_e2e=1` metadata and the run id), drives
 * the production path, checks what Stripe holds, then feeds Stripe's own events
 * to SVC's webhook route and checks the ordinary ledger.
 */
final class StripeTestModeEndToEndTest extends StripeLiveTestCase
{
    public function test_a_method_saved_through_the_portal_setup_path_is_recorded_and_becomes_the_default(): void
    {
        [$workspace, $owner, $company] = $this->tenant();

        $saved = $this->saveVisaThroughSetup($workspace, $company, $owner);

        $this->assertSame(['card', 'visa', '4242', true], [$saved->type, $saved->brand, $saved->last4, $saved->is_default]);
        $customerId = $this->customerId($workspace, $company);
        $customer = $this->stripe->customers->retrieve($customerId);
        $this->assertSame($saved->stripe_payment_method_id, $customer->invoice_settings->default_payment_method);
        $this->assertSame('1', $customer->metadata['svc_e2e']);

        // Setup tags the customer first (a customer.updated with no default), so the wait is for
        // the customer.updated that carries this method as the default, not for the type.
        $this->replayStripeEvents([$customerId, $saved->stripe_payment_method_id], [
            'setup_intent.succeeded',
            'payment_method.attached',
            StripeExpectation::customerDefault($customerId, $saved->stripe_payment_method_id),
        ]);

        // Stripe's own events are the second delivery of what the portal already recorded.
        $this->assertSame(1, ClientStripePaymentMethod::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame($saved->id, $this->defaultMethod($workspace, $company)->id);
        $this->assertSame(0, DB::table('client_stripe_events')->where('status', 'failed')->count());
    }

    public function test_autopay_charges_the_saved_card_and_the_replayed_webhook_settles_the_invoice(): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        $saved = $this->saveVisaThroughSetup($workspace, $company, $owner);
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->dueInvoice($workspace, $company, 5_000);

        $outcomes = $this->runAutopay();

        $this->assertCount(1, $outcomes);
        $this->assertSame('submitted', $outcomes[0]['result']);
        $attempt = InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(AutopayAttemptState::Submitted, $attempt->state);
        $intentId = (string) $attempt->payment_intent_id;
        $this->assertStringStartsWith('pi_', $intentId);

        $intent = $this->stripe->paymentIntents->retrieve($intentId);
        $this->assertSame(['succeeded', 5_000, 'usd', $this->customerId($workspace, $company), $saved->stripe_payment_method_id], [$intent->status, $intent->amount, $intent->currency, $intent->customer, $intent->payment_method]);
        $this->assertSame($invoice->public_id, $intent->metadata['invoice_public_id']);
        $this->assertSame($workspace->public_id, $intent->metadata['workspace_public_id']);
        $this->assertSame($company->public_id, $intent->metadata['client_company_public_id']);
        $this->assertSame($attempt->public_id, $intent->metadata['autopay_attempt_public_id']);

        // Charged, but not yet paid in the ledger: only Stripe's event settles it.
        $this->assertSame('pending', ClientInvoicePayment::query()->where('provider_payment_identifier', $intentId)->sole()->status);
        $this->replayStripeEvents([$this->customerId($workspace, $company), $intentId], ['payment_intent.succeeded']);

        $invoice = $invoice->fresh();
        $this->assertSame(['paid', 0], [$invoice->status, $invoice->balance_amount]);
        $this->assertSame('succeeded', ClientInvoicePayment::query()->where('provider_payment_identifier', $intentId)->sole()->status);
    }

    public function test_a_declined_card_is_one_failed_attempt_that_is_never_retried(): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        // Attaches to a customer but declines when charged (pm_card_chargeDeclined cannot be attached at all).
        $this->saveAttachedMethod($workspace, $company, $owner, 'pm_card_chargeCustomerFail');
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->dueInvoice($workspace, $company, 5_000);
        $customerId = $this->customerId($workspace, $company);

        $first = $this->runAutopay();

        $this->assertCount(1, $first);
        $this->assertSame('failed', $first[0]['result']);
        $attempt = InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(AutopayAttemptState::Failed, $attempt->state);
        $this->assertSame(1, $this->intentCount($customerId));

        $this->assertSame([], $this->runAutopay(), 'A second run must not touch an invoice that already has an attempt.');
        $this->assertSame(1, InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->count());
        $this->assertSame(1, $this->intentCount($customerId), 'A second run must not create a second PaymentIntent at Stripe.');

        $this->replayStripeEvents([$customerId], ['payment_intent.payment_failed']);
        $this->assertStillCollectible($invoice->id, 5_000);
    }

    public function test_a_card_that_needs_authentication_is_a_failed_attempt_and_leaves_no_charge(): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        $this->saveAttachedMethod($workspace, $company, $owner, 'pm_card_authenticationRequired');
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->dueInvoice($workspace, $company, 5_000);
        $customerId = $this->customerId($workspace, $company);

        $outcomes = $this->runAutopay();

        $this->assertCount(1, $outcomes);
        $this->assertSame('failed', $outcomes[0]['result']);
        $attempt = InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(AutopayAttemptState::Failed, $attempt->state);
        // Stripe refuses off-session confirmation outright (requires_payment_method) or
        // SVC cancels an intent left awaiting the client; either way nothing was charged.
        if ($attempt->payment_intent_id !== null) {
            $this->assertContains($this->stripe->paymentIntents->retrieve($attempt->payment_intent_id)->status, ['canceled', 'requires_payment_method', 'requires_action']);
        }
        $this->assertSame(0, $this->succeededIntentCount($customerId));
        $this->assertSame([], $this->runAutopay());

        $this->replayStripeEvents([$customerId], ['payment_intent.payment_failed']);
        $this->assertStillCollectible($invoice->id, 5_000);
    }

    public function test_removing_the_default_method_detaches_it_at_stripe_and_turns_autopay_off(): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        $saved = $this->saveVisaThroughSetup($workspace, $company, $owner);
        $this->turnAutopayOn($workspace, $company, $contact);
        $this->assertNotNull($company->fresh()->autopay_enabled_at);
        $customerId = $this->customerId($workspace, $company);
        $methodId = $saved->stripe_payment_method_id;

        app(PortalPaymentMethodService::class)->remove($workspace, $company, $saved, $owner);

        $this->assertNull($this->stripe->paymentMethods->retrieve($methodId)->customer);
        $this->assertSame(0, ClientStripePaymentMethod::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNull($company->fresh()->autopay_enabled_at);

        // The whole history, attach then detach, in order: the detach wins and the old attach cannot resurrect it.
        $this->replayStripeEvents([$customerId, $methodId], ['payment_method.attached', 'payment_method.detached']);
        $this->assertSame(0, ClientStripePaymentMethod::query()->where('workspace_id', $workspace->id)->count());
        $this->assertNull($company->fresh()->autopay_enabled_at);
    }

    /** @return array<string, array{0: string}> */
    public static function reorderings(): array
    {
        return ['newest first' => ['newest first'], 'a fixed shuffle' => ['shuffled']];
    }

    /**
     * Stripe does not promise delivery order and its event time has one-second
     * precision, so the default method's two changes (and the metadata update
     * that setup makes first) can reach SVC in any order. Here SVC starts from
     * nothing - no method, no state, no receipt - and the first delivery is
     * already reordered. Whatever arrives when, SVC must end where Stripe is.
     * (A default naming a method whose attach has not arrived is refused to be
     * retried, as Stripe would.) Delivering everything again afterwards is the
     * extra, duplicate-only assertion.
     */
    #[DataProvider('reorderings')]
    public function test_reordered_events_as_the_first_delivery_converge_on_what_stripe_holds(string $order): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        $first = $this->saveVisaThroughSetup($workspace, $company, $owner);
        $this->turnAutopayOn($workspace, $company, $contact);
        $second = $this->saveAttachedMethod($workspace, $company, $owner, 'pm_card_mastercard');
        $customerId = $this->customerId($workspace, $company);
        [$firstId, $secondId] = [$first->stripe_payment_method_id, $second->stripe_payment_method_id];
        $this->assertNotSame($firstId, $secondId);
        $final = $this->stripe->customers->retrieve($customerId)->invoice_settings->default_payment_method;
        $this->assertSame($secondId, $final, 'Stripe holds the second method as the default.');

        $events = $this->collectStripeEvents([$customerId, $firstId, $secondId], [
            'setup_intent.succeeded',
            StripeExpectation::forObject('payment_method.attached', $firstId),
            StripeExpectation::forObject('payment_method.attached', $secondId),
            StripeExpectation::customerDefault($customerId, $firstId),
            StripeExpectation::customerDefault($customerId, $secondId),
        ]);

        $this->resetStripeProjection($workspace, $company);
        $this->deliverUntilAccepted($this->reordered($events, $order));

        $this->assertConvergedOn($workspace, $company, $final, [$firstId, $secondId]);
        $this->assertSame(count($events), DB::table('client_stripe_events')->where('status', 'processed')->count(), 'Every event was applied.');
        $this->assertNotNull($company->fresh()->autopay_enabled_at, 'Stripe never lacked a default, so autopay stays on.');

        $this->assertDuplicatesAreNoOps($events);
        $this->assertConvergedOn($workspace, $company, $final, [$firstId, $secondId]);
    }

    /**
     * The post-migration path: the workspace's key lives on a saved connection
     * and the deployment has no Stripe credentials of its own. Saving a method,
     * the autopay charge and the webhook all reach Stripe and this workspace
     * through that row alone, and events delivered newest first to the
     * connection's own endpoint still settle the invoice.
     */
    public function test_a_workspace_on_a_saved_connection_charges_and_settles_through_it_alone(): void
    {
        [$workspace, $owner, $company, $contact] = $this->tenant();
        $connection = $this->connectThroughSavedKey($workspace, $owner);
        $this->assertNull(config('services.stripe.secret_key'), 'The deployment has no Stripe key of its own.');
        $this->assertNull(config('services.stripe.webhook_secret'));
        $this->assertSame($workspace->id, $connection->workspace_id);

        $saved = $this->saveVisaThroughSetup($workspace, $company, $owner);
        $this->turnAutopayOn($workspace, $company, $contact);
        $invoice = $this->dueInvoice($workspace, $company, 5_000);
        $outcomes = $this->runAutopay();

        $this->assertCount(1, $outcomes);
        $this->assertSame('submitted', $outcomes[0]['result']);
        $intentId = (string) InvoiceAutopayAttempt::query()->where('workspace_id', $workspace->id)->sole()->payment_intent_id;
        $customerId = $this->customerId($workspace, $company);
        $intent = $this->stripe->paymentIntents->retrieve($intentId);
        $this->assertSame(['succeeded', 5_000, $customerId, $saved->stripe_payment_method_id], [$intent->status, $intent->amount, $intent->customer, $intent->payment_method]);
        $this->assertSame($workspace->public_id, $intent->metadata['workspace_public_id']);

        $events = $this->collectStripeEvents([$customerId, $intentId, $saved->stripe_payment_method_id], [
            'payment_intent.succeeded',
            StripeExpectation::customerDefault($customerId, $saved->stripe_payment_method_id),
        ]);
        $this->resetStripeProjection($workspace, $company);
        $this->deliverUntilAccepted($this->reordered($events, 'newest first'));

        $this->assertConvergedOn($workspace, $company, $saved->stripe_payment_method_id, [$saved->stripe_payment_method_id]);
        $invoice = $invoice->fresh();
        $this->assertSame(['paid', 0], [$invoice->status, $invoice->balance_amount]);
        $this->assertSame('succeeded', ClientInvoicePayment::query()->where('provider_payment_identifier', $intentId)->sole()->status);
        $this->assertSame(count($events), DB::table('client_stripe_events')->where('status', 'processed')->where('workspace_id', $workspace->id)->count(), 'Each event was routed to this workspace through its connection.');
        $this->assertSame($connection->public_id, WorkspacePaymentConnection::query()->where('workspace_id', $workspace->id)->whereNull('revoked_at')->sole()->public_id);
    }

    /**
     * SVC holds exactly the methods Stripe holds for the customer, one default,
     * and it is the one Stripe reports; nothing was recorded as a failure.
     *
     * @param  list<string>  $methodIds
     */
    private function assertConvergedOn(Workspace $workspace, ClientCompany $company, string $stripeDefault, array $methodIds): void
    {
        $held = ClientStripePaymentMethod::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)->orderBy('stripe_payment_method_id')->pluck('stripe_payment_method_id')->all();
        sort($methodIds);
        $this->assertSame($methodIds, $held);
        $this->assertSame([$stripeDefault], ClientStripePaymentMethod::query()->where('workspace_id', $workspace->id)->where('is_default', true)->pluck('stripe_payment_method_id')->all());
        $this->assertSame($stripeDefault, $this->stripe->customers->retrieve($this->customerId($workspace, $company))->invoice_settings->default_payment_method);
        $this->assertSame(0, DB::table('client_stripe_events')->where('status', 'failed')->count());
        $this->assertSame(0, DB::table('client_stripe_events')->where('status', 'received')->count());
    }

    /** How many PaymentIntents Stripe holds for the customer. */
    private function intentCount(string $customerId): int
    {
        return count($this->stripe->paymentIntents->all(['customer' => $customerId, 'limit' => 100])->data);
    }

    private function succeededIntentCount(string $customerId): int
    {
        return count(array_filter(
            $this->stripe->paymentIntents->all(['customer' => $customerId, 'limit' => 100])->data,
            static fn ($intent): bool => $intent->status === 'succeeded',
        ));
    }

    /** A failed charge leaves the invoice owing and issued, for the ordinary payment reminders. */
    private function assertStillCollectible(int $invoiceId, int $balance): void
    {
        $invoice = ClientInvoice::query()->findOrFail($invoiceId);
        $this->assertSame(['issued', $balance], [$invoice->status, $invoice->balance_amount]);
        $this->assertSame(0, ClientInvoicePayment::query()->where('client_invoice_id', $invoiceId)->where('status', 'succeeded')->count());
    }
}
