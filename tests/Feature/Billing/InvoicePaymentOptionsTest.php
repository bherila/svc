<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\Payments\WorkspacePaymentConnections;
use App\Support\Billing\InvoicePaymentOptions;
use App\Support\Payments\ConnectionOrigin;
use App\Support\Payments\PaymentProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsGoldenInvoices;
use Tests\TestCase;

/**
 * What the portal invoice page offers a client to pay with, per workspace:
 * finished URLs or null, never a flag, and never for an invoice that cannot be
 * paid.
 */
class InvoicePaymentOptionsTest extends TestCase
{
    use BuildsGoldenInvoices;
    use RefreshDatabase;

    private ?User $portalClient = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['inertia.ssr.enabled' => false]);
        $this->setUpGoldenTenant();
    }

    /** The workspace's own Stripe connection, and the deployment's publishable key. */
    private function stripe(bool $connected, ?string $publishable = 'pk_test_synthetic'): void
    {
        config(['services.stripe.publishable_key' => $publishable, 'services.stripe.secret_key' => null]);
        $connections = app(WorkspacePaymentConnections::class);
        if ($connected && $connections->activeRow($this->workspace, PaymentProcessor::Stripe) === null) {
            $connections->connect($this->workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $this->member, [
                'external_account_id' => 'acct_synthetic', 'display_name' => 'Synthetic account', 'environment' => 'test', 'secret' => 'sk_test_synthetic_key_0001',
            ]);
        }
        if (! $connected && $connections->activeRow($this->workspace, PaymentProcessor::Stripe) === null) {
            // Never connected, and the deployment holds no key: nothing to charge.
            $this->assertFalse($connections->everConnected($this->workspace, PaymentProcessor::Stripe));
        }
    }

    private function client(): User
    {
        $client = $this->portalClient ??= User::factory()->create(['email' => 'portal-client@example.test']);
        if (! $this->company->portalUsers()->whereKey($client->id)->exists()) {
            $this->company->portalUsers()->attach($client->id, ['workspace_id' => $this->workspace->id]);
        }

        return $client;
    }

    /** @param array<string, mixed> $attributes */
    private function owing(array $attributes = []): string
    {
        $invoice = $this->goldenInvoice($attributes + ['subtotal_amount' => 10000, 'total_amount' => 10000, 'balance_amount' => 10000]);
        $this->goldenLine($invoice);

        return route('portal.invoice', [$this->company, $invoice], absolute: false);
    }

    public function test_a_workspace_with_stripe_offers_the_intent_route_and_its_publishable_key(): void
    {
        $this->stripe(true);
        $url = $this->owing();

        $response = $this->actingAs($this->client())->get($url)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('payment.stripe.publishable_key', 'pk_test_synthetic')
            ->where('payment.stripe.intent_href', fn (string $href): bool => str_starts_with($href, '/workspaces/'.$this->workspace->public_id.'/invoices/') && str_ends_with($href, '/stripe-payment-intent'))
            ->where('payment.square', null));
        $this->assertStringNotContainsString('sk_test_synthetic', (string) $response->getContent());
    }

    public function test_nothing_is_offered_without_a_connection_or_without_a_publishable_key(): void
    {
        $this->stripe(false);
        $url = $this->owing();
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment', ['stripe' => null, 'square' => null]));

        $this->stripe(true);

        config(['services.stripe.publishable_key' => null]);
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment.stripe', null));

        config(['services.stripe.publishable_key' => '']);
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment.stripe', null));
    }

    public function test_nothing_is_offered_for_an_invoice_that_cannot_be_paid(): void
    {
        $this->stripe(true);
        $client = $this->client();

        $paid = $this->owing(['status' => 'paid', 'paid_amount' => 10000, 'balance_amount' => 0]);
        $disputed = $this->owing(['status' => 'disputed']);

        foreach ([$paid, $disputed] as $url) {
            $this->actingAs($client)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment', ['stripe' => null, 'square' => null]));
        }
    }

    public function test_a_client_page_carries_no_workspace_internal_field(): void
    {
        $this->stripe(true);
        $url = $this->owing(['notes' => 'Synthetic private invoice note', 'void_reason' => 'Synthetic private void reason']);

        $content = (string) $this->actingAs($this->client())->get($url)->assertOk()->getContent();

        foreach (['Synthetic private invoice note', 'Synthetic private void reason', 'void_reason', 'administrator_notification', 'deliveries', 'payment_reminders'] as $internal) {
            $this->assertStringNotContainsString($internal, $content);
        }
    }

    public function test_the_composition_rules(): void
    {
        $key = 'pk_test_synthetic';
        $stripe = ['intent_href' => '/pay', 'publishable_key' => $key];

        $this->assertSame(['stripe' => $stripe, 'square' => ['href' => 'https://square.example.test/p']], InvoicePaymentOptions::compose(true, 'https://square.example.test/p', false, true, $key, '/pay'));
        $this->assertSame(['stripe' => $stripe, 'square' => null], InvoicePaymentOptions::compose(true, null, false, true, $key, '/pay'));
        // Square collects it: SVC never asks for money Square is collecting.
        $this->assertSame(['stripe' => null, 'square' => ['href' => 'https://square.example.test/p']], InvoicePaymentOptions::compose(true, 'https://square.example.test/p', true, true, $key, '/pay'));
        $this->assertSame(['stripe' => null, 'square' => null], InvoicePaymentOptions::compose(true, null, false, false, $key, '/pay'));
        $this->assertSame(['stripe' => null, 'square' => null], InvoicePaymentOptions::compose(true, null, false, true, null, '/pay'));
        $this->assertSame(['stripe' => null, 'square' => null], InvoicePaymentOptions::compose(true, null, false, true, '', '/pay'));
        $this->assertSame(['stripe' => null, 'square' => null], InvoicePaymentOptions::compose(false, 'https://square.example.test/p', false, true, $key, '/pay'));
    }
}
