<?php

namespace Tests\Feature\Billing;

use App\Models\ClientAgreement;
use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentApi\AgentInvoiceOperations;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Services\Billing\StripeWebhookService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Concurrency\LockMode;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\Event;
use Tests\TestCase;

final class BillingMaintenanceWriterBoundariesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        LockOrderRecorder::stop();
        parent::tearDown();
    }

    public function test_payment_intent_work_takes_share_mode_before_the_invoice_lock_or_adapter(): void
    {
        [$workspace, $invoice] = $this->invoice();
        config(['services.stripe.secret_key' => null]);
        LockOrderRecorder::start();
        try {
            app(StripePaymentIntentService::class)->create($invoice, $workspace, null, 'synthetic-maintenance-intent');
            $this->fail('An unconfigured adapter was called.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Stripe is not configured.', $exception->getMessage());
        }
        $this->assertShareBeforeBilling();
        $this->assertDatabaseCount('client_invoice_payments', 0);
    }

    public static function paymentEvents(): iterable
    {
        yield 'status' => ['payment_intent.succeeded', 'pending'];
        yield 'refund' => ['charge.refunded', 'succeeded'];
    }

    #[DataProvider('paymentEvents')]
    public function test_provider_payment_events_take_share_mode_before_payment_locks(string $type, string $status): void
    {
        [$workspace, $invoice] = $this->invoice();
        app(InvoiceLifecycleService::class)->applyPayment($invoice, ['amount' => 1000, 'currency' => 'USD', 'status' => $status,
            'method' => 'stripe', 'provider' => 'stripe', 'provider_payment_identifier' => 'pi_synthetic_maintenance'], $workspace);
        $data = ['id' => 'evt_synthetic_maintenance', 'type' => $type, 'created' => 200,
            'data' => ['object' => ['id' => $type === 'charge.refunded' ? 'ch_synthetic_maintenance' : 'pi_synthetic_maintenance',
                'payment_intent' => 'pi_synthetic_maintenance', 'amount_received' => 1000, 'amount_refunded' => 100, 'currency' => 'usd']]];
        LockOrderRecorder::start();
        $this->assertTrue(app(StripeWebhookService::class)->process(Event::constructFrom($data), json_encode($data, JSON_THROW_ON_ERROR)));
        $this->assertShareBeforeBilling();
        $this->assertSame(1, $invoice->payments()->where('workspace_id', $workspace->id)->count());
    }

    public function test_agent_generation_takes_share_mode_before_agreement_and_invoice_preflight_locks(): void
    {
        $this->travelTo('2026-10-12 12:00:00');
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true]);
        [$workspace, $invoice] = $this->invoice();
        $actor = User::factory()->create(['email' => 'maintenance-actor@synthetic.test']);
        $workspace->memberships()->create(['user_id' => $actor->id, 'role' => 'owner']);
        $agreement = ClientAgreement::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $invoice->client_company_id,
            'title' => 'Synthetic recurring agreement', 'status' => 'active', 'currency' => 'USD', 'starts_on' => '2026-07-01',
            'retainer_minutes' => 120, 'retainer_amount' => 30000, 'hourly_rate_amount' => 12000,
            'catch_up_threshold_minutes' => 0, 'billing_cadence' => 'monthly', 'bill_overage_interim' => false, 'rollover_months' => 0]);
        LockOrderRecorder::start();
        $draft = app(AgentInvoiceOperations::class)->generate($actor, $workspace, 'synthetic-client', 'synthetic-generation',
            $agreement->public_id, ['expected_version' => AgentApiVersion::for($agreement), 'period_start' => '2026-10-01', 'confirm' => true]);
        $this->assertSame('draft', $draft->status);
        $this->assertSame($workspace->id, $draft->workspace_id);
        $this->assertShareBeforeBilling();
    }

    private function assertShareBeforeBilling(): void
    {
        $sequences = LockOrderRecorder::acquisitions();
        $this->assertNotEmpty($sequences);
        foreach ($sequences as $sequence) {
            $billing = array_values(array_filter($sequence, static fn ($lock): bool => $lock->resource !== LockResource::AgentMutationReceipt));
            $this->assertNotEmpty($billing);
            $this->assertSame(LockResource::BillingMaintenance, $billing[0]->resource);
            $this->assertSame(LockMode::Share, $billing[0]->mode);
        }
    }

    /** @return array{Workspace, ClientInvoice} */
    private function invoice(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic Writer Boundary', 'slug' => 'synthetic-writer-boundary']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic Client', 'slug' => 'synthetic-client']);
        $lifecycle = app(InvoiceLifecycleService::class);
        $invoice = $lifecycle->issue($lifecycle->createDraft($workspace, $company,
            ['invoice_number' => 'SYNTHETIC-BOUNDARY', 'currency' => 'USD'],
            [['type' => 'adjustment', 'description' => 'Synthetic boundary proof', 'quantity' => '1', 'unit_amount' => 1000, 'tax_amount' => 0]]), $workspace);

        return [$workspace, $invoice];
    }
}
