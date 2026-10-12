<?php

namespace Tests\Feature\Square;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\InvoiceSquareLink;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

/**
 * Handing collection to Square needs SVC's own collection to be at rest (#438).
 *
 * The guard stops SVC *starting* to collect an invoice Square collects, but a
 * Stripe intent the client already started has its client secret out and can
 * still complete. So both hand-offs - linking an invoice, and marking an
 * agreement as billed in Square - refuse while a Stripe payment is pending on
 * an invoice they would hand over.
 */
final class SquareCollectionHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const array SCOPES = ['mcp:use', 'identity:read', 'billing:read', 'billing:write'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.client_writes_enabled' => true]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function test_an_invoice_with_a_pending_stripe_payment_is_not_linked(): void
    {
        [$workspace, $owner, , $invoice] = $this->fixture();
        $this->pendingStripePayment($invoice);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/square-link", [
            'expected_version' => AgentApiVersion::for($invoice->refresh()), 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001',
        ], ['Idempotency-Key' => 'synthetic-pending'])
            ->assertConflict()
            ->assertJsonPath('message', 'A Stripe payment on invoice SYN-1 can still complete (pending, or failed and retryable). Let it succeed or cancel it in Stripe before Square collects this invoice.');

        $this->assertSame(0, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->count());
    }

    /** A declined intent is retryable with its client secret, so it still blocks; a canceled one does not. */
    public function test_a_failed_stripe_intent_still_blocks_linking_until_it_is_canceled(): void
    {
        [$workspace, $owner, , $invoice] = $this->fixture();
        $this->pendingStripePayment($invoice);
        ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->update(['status' => 'failed']);
        $this->actingAsMcp($owner, self::SCOPES);
        $link = fn (string $key) => $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/square-link", [
            'expected_version' => AgentApiVersion::for($invoice->refresh()), 'square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001',
        ], ['Idempotency-Key' => $key]);

        $link('synthetic-failed')->assertConflict();
        ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);
        $link('synthetic-canceled')->assertOk();
    }

    /**
     * The intent advances the invoice's version, so a link composed before it
     * started is refused on the version alone rather than relying on what an
     * older read could see.
     */
    public function test_starting_a_stripe_payment_refuses_a_link_composed_before_it(): void
    {
        [$workspace, $owner, $company, $invoice] = $this->fixture();
        $before = AgentApiVersion::for($invoice);
        config(['services.stripe.secret_key' => 'sk_test_synthetic']);
        ApiRequestor::setHttpClient(new class implements ClientInterface
        {
            /**
             * @param  array<int|string, mixed>  $headers
             * @param  array<string, mixed>  $params
             * @return array{0: string, 1: int, 2: array<string, string>}
             */
            public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
            {
                return [json_encode(['id' => 'pi_synthetic_1', 'object' => 'payment_intent', 'client_secret' => 'pi_synthetic_1_secret_fixture'], JSON_THROW_ON_ERROR), 200, []];
            }
        });
        $client = User::factory()->create();
        ClientCompanyMembership::query()->create(['client_company_id' => $company->id, 'user_id' => $client->id, 'role' => 'client', 'access_scope' => ClientCompanyMembership::SCOPE_COMPANY]);
        $this->actingAs($client)
            ->postJson("/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/stripe-payment-intent", ['idempotency_key' => 'synthetic-intent'])
            ->assertCreated();
        $this->assertNotSame($before, AgentApiVersion::for($invoice->refresh()));

        $this->actingAsMcp($owner, self::SCOPES);
        $body = ['square_invoice_id' => 'inv:0-fixture1', 'square_invoice_number' => '000001-R-0001'];
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/square-link", ['expected_version' => $before, ...$body], ['Idempotency-Key' => 'synthetic-stale'])
            ->assertConflict()->assertJsonPath('message', 'The invoice has changed; read it and retry.');
        $this->postJson("/api/v1/workspaces/{$workspace->public_id}/invoices/{$invoice->public_id}/square-link", ['expected_version' => AgentApiVersion::for($invoice), ...$body], ['Idempotency-Key' => 'synthetic-current'])
            ->assertConflict()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'can still complete'));
        $this->assertSame(0, InvoiceSquareLink::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_an_agreement_with_a_pending_stripe_payment_on_an_invoice_is_not_marked(): void
    {
        [$workspace, $owner, , $invoice, $agreement] = $this->fixture();
        $this->pendingStripePayment($invoice);
        $this->actingAsMcp($owner, self::SCOPES);

        $this->putJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/square-series", [
            'expected_version' => AgentApiVersion::for($agreement), 'series_key' => '000001', 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-pending-mark'])
            ->assertConflict()
            ->assertJsonPath('message', 'A Stripe payment on invoice SYN-1 of this agreement can still complete (pending, or failed and retryable). Let it succeed or cancel it in Stripe before Square collects the agreement\'s invoices.');

        $this->assertNull($agreement->refresh()->square_series_key);

        // A declined intent can be confirmed again with its client secret:
        // still in flight. Canceled in Stripe, it no longer is.
        ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->update(['status' => 'failed']);
        $this->putJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/square-series", [
            'expected_version' => AgentApiVersion::for($agreement), 'series_key' => '000001', 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-failed-mark'])->assertConflict();
        ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->update(['status' => 'canceled']);
        $this->putJson("/api/v1/workspaces/{$workspace->public_id}/agreements/{$agreement->public_id}/square-series", [
            'expected_version' => AgentApiVersion::for($agreement), 'series_key' => '000001', 'confirm' => true,
        ], ['Idempotency-Key' => 'synthetic-settled-mark'])->assertOk();
    }

    private function pendingStripePayment(ClientInvoice $invoice): void
    {
        ClientInvoicePayment::query()->create(['workspace_id' => $invoice->workspace_id, 'client_invoice_id' => $invoice->id, 'amount' => 5000, 'refunded_amount' => 0, 'currency' => 'USD', 'status' => 'pending', 'method' => 'stripe', 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_pending', 'received_on' => '2026-10-08']);
    }

    /** @return array{Workspace, User, ClientCompany, ClientInvoice, ClientAgreement} */
    private function fixture(): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic Square Handoff', 'slug' => 'synthetic-square-handoff-'.uniqid()]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Handoff Client', 'slug' => 'synthetic-handoff-client']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'title' => 'Synthetic Monthly', 'status' => 'active', 'starts_on' => '2026-01-01', 'billing_cadence' => 'monthly', 'currency' => 'USD', 'hourly_rate_amount' => 10000]);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'client_agreement_id' => $agreement->id, 'invoice_number' => 'SYN-1', 'invoice_kind' => 'ad_hoc', 'status' => 'issued', 'is_visible_to_client' => true, 'issue_date' => '2026-10-01', 'due_date' => '2026-10-31', 'currency' => 'USD', 'subtotal_amount' => 5000, 'tax_amount' => 0, 'total_amount' => 5000, 'paid_amount' => 0, 'balance_amount' => 5000]);

        return [$workspace, $owner, $company, $invoice, $agreement];
    }
}
