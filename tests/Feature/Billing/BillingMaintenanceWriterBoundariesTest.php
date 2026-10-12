<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\StripePaymentIntentService;
use App\Support\Concurrency\LockMode;
use App\Support\Concurrency\LockOrderRecorder;
use App\Support\Concurrency\LockResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
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
