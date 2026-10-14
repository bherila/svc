<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class SquareReceivedPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_payment_is_recorded_once_even_after_a_refund(): void
    {
        Date::setTestNow('2030-02-02 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic payment workspace', 'slug' => 'synthetic-payment-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic payment company', 'slug' => 'synthetic-payment-company']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-001', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 1200, 'balance_amount' => 1200]);
        $facts = ReceivedPaymentData::from(['amount' => 1200, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'square']);
        $provider = new ReceivedPaymentProvider('square', 'pay_fixture_1');
        $service = app(RecordReceivedPayment::class);
        $first = $service->recordProvider($workspace, $invoice, $facts, $provider);
        $this->assertSame('square', $first->provider);
        $this->assertSame('pay_fixture_1', $first->provider_payment_identifier);
        $this->assertSame('paid', $invoice->fresh()->status);
        app(InvoiceLifecycleService::class)->setRefundedAmount($first, 300, $workspace);
        $second = $service->recordProvider($workspace, $invoice, $facts, $provider);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(300, $second->refunded_amount);
        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame(300, $invoice->fresh()->balance_amount);

        $other = $invoice->replicate(['public_id']);
        $other->forceFill(['invoice_number' => 'SYNTHETIC-002', 'status' => 'issued'])->save();
        foreach ([[$other, $facts], [$invoice, ReceivedPaymentData::from(['amount' => 1199, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'square'])]] as [$target, $changed]) {
            try {
                $service->recordProvider($workspace, $target, $changed, $provider);
                $this->fail('A provider identity was reused for different money.');
            } catch (DomainException $exception) {
                $this->assertSame('The provider identity is already bound to a different payment.', $exception->getMessage());
            }
        }
        $foreign = Workspace::query()->create(['name' => 'Synthetic foreign provider workspace', 'slug' => 'synthetic-foreign-provider-workspace']);
        try {
            $service->recordProvider($foreign, $invoice, $facts, $provider);
            $this->fail('A foreign invoice was paid.');
        } catch (DomainException $exception) {
            $this->assertSame('The invoice does not belong to this workspace.', $exception->getMessage());
        }
        $manual = ReceivedPaymentData::from(['amount' => 1, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'cash', 'provider' => 'square', 'provider_payment_identifier' => 'pay_fixture_spoof']);
        $this->assertArrayNotHasKey('provider', $manual->attributes());
        $this->assertArrayNotHasKey('provider_payment_identifier', $manual->attributes());
    }
}
