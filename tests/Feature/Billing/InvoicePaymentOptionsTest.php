<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Payments\WorkspacePaymentConnections;
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

    /**
     * `environment`: the deployment's own key serves a workspace that never
     * connected one. `own`: the workspace entered a key of its own. `none`:
     * nothing at all.
     */
    private function stripe(string $mode, ?string $publishable = 'pk_test_synthetic', ?string $ownPublishable = null): void
    {
        config([
            'services.stripe.publishable_key' => $publishable,
            'services.stripe.secret_key' => $mode === 'environment' ? 'sk_test_environment_key_0001' : null,
        ]);
        if ($mode === 'own' && app(WorkspacePaymentConnections::class)->activeRow($this->workspace, PaymentProcessor::Stripe) === null) {
            app(WorkspacePaymentConnections::class)->connect($this->workspace, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $this->member, [
                'external_account_id' => 'acct_synthetic', 'display_name' => 'Synthetic account', 'environment' => 'test', 'secret' => 'sk_test_synthetic_key_0001', 'publishable_key' => $ownPublishable,
            ]);
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

    public function test_a_workspace_served_by_the_environment_account_offers_the_intent_route_and_its_publishable_key(): void
    {
        $this->stripe('environment');
        $url = $this->owing();

        $response = $this->actingAs($this->client())->get($url)->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('payment.stripe.publishable_key', 'pk_test_synthetic')
            ->where('payment.stripe.intent_href', fn (string $href): bool => str_starts_with($href, '/workspaces/'.$this->workspace->public_id.'/invoices/') && str_ends_with($href, '/stripe-payment-intent'))
            ->where('payment.square', null));
        $this->assertStringNotContainsString('sk_test_', (string) $response->getContent());
    }

    public function test_a_workspace_with_its_own_stripe_key_is_not_handed_the_deployments_publishable_key(): void
    {
        $this->stripe('own');
        $url = $this->owing();

        // The intent would be created on the workspace's account, which the
        // deployment's publishable key cannot confirm.
        $this->actingAs($this->client())->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payment', ['stripe' => null, 'square' => null]));
    }

    public function test_a_workspace_with_its_own_stripe_key_and_publishable_key_is_offered_card_payment_with_its_own(): void
    {
        $this->stripe('own', 'pk_test_deployment_key', 'pk_test_workspace_key');
        $url = $this->owing();

        $this->actingAs($this->client())->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('payment.stripe.publishable_key', 'pk_test_workspace_key')
                ->where('payment.stripe.intent_href', fn (string $href): bool => str_ends_with($href, '/stripe-payment-intent')));
    }

    public function test_a_workspace_that_reuses_a_connection_is_offered_the_sources_publishable_key(): void
    {
        $this->stripe('none', 'pk_test_deployment_key');
        $owner = User::factory()->create();
        $other = Workspace::query()->create(['name' => 'Source Workspace', 'slug' => 'source-workspace']);
        foreach ([$this->workspace, $other] as $workspace) {
            $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        }
        $connections = app(WorkspacePaymentConnections::class);
        $source = $connections->connect($other, PaymentProcessor::Stripe, ConnectionOrigin::Entered, $owner, [
            'external_account_id' => 'acct_source', 'environment' => 'test', 'secret' => 'sk_test_source_key_0001', 'publishable_key' => 'pk_test_source_key',
        ]);
        $connections->reuse($this->workspace, $source, $owner);
        $url = $this->owing();

        $this->actingAs($this->client())->get($url)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('payment.stripe.publishable_key', 'pk_test_source_key'));
    }

    public function test_nothing_is_offered_without_a_connection_or_without_a_publishable_key(): void
    {
        $this->stripe('none');
        $url = $this->owing();
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment', ['stripe' => null, 'square' => null]));

        $this->stripe('environment', null);
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment.stripe', null));

        $this->stripe('environment', '');
        $this->actingAs($this->client())->get($url)->assertInertia(fn (Assert $page) => $page->where('payment.stripe', null));
    }

    public function test_nothing_is_offered_for_an_invoice_that_cannot_be_paid(): void
    {
        $this->stripe('environment');
        $client = $this->client();

        $paid = $this->owing(['status' => 'paid', 'paid_amount' => 10000, 'balance_amount' => 0]);
        $disputed = $this->owing(['status' => 'disputed']);

        foreach ([$paid, $disputed] as $url) {
            $this->actingAs($client)->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('payment', ['stripe' => null, 'square' => null]));
        }
    }

    public function test_a_client_page_carries_no_workspace_internal_field(): void
    {
        $this->stripe('environment');
        $url = $this->owing(['notes' => 'Synthetic private invoice note', 'void_reason' => 'Synthetic private void reason']);

        $content = (string) $this->actingAs($this->client())->get($url)->assertOk()->getContent();

        foreach (['Synthetic private invoice note', 'Synthetic private void reason', 'void_reason', 'administrator_notification', 'deliveries', 'payment_reminders'] as $internal) {
            $this->assertStringNotContainsString($internal, $content);
        }
    }
}
