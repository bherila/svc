<?php

namespace Tests\Feature\Square;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

final class SquareProviderIdentifierCollationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_identifiers_that_differ_only_in_case_are_distinct_and_retries_remain_idempotent(): void
    {
        Date::setTestNow('2030-02-02 12:00:00');
        $workspace = Workspace::query()->create(['name' => 'Synthetic identifier workspace', 'slug' => 'synthetic-identifier-workspace']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic identifier company', 'slug' => 'synthetic-identifier-company']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-CASE', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 2400, 'balance_amount' => 2400]);
        $facts = ReceivedPaymentData::from(['amount' => 1200, 'currency' => 'USD', 'received_on' => '2030-02-01', 'method' => 'square']);
        $service = app(RecordReceivedPayment::class);
        $first = $service->recordProvider($workspace, $invoice, $facts, new ReceivedPaymentProvider('square', 'pay_fixture_a'));
        $second = $service->recordProvider($workspace, $invoice, $facts, new ReceivedPaymentProvider('square', 'pay_fixture_A'));
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->id, $service->recordProvider($workspace, $invoice, $facts, new ReceivedPaymentProvider('square', 'pay_fixture_a'))->id);
        $this->assertSame($second->id, $service->recordProvider($workspace, $invoice, $facts, new ReceivedPaymentProvider('square', 'pay_fixture_A'))->id);
        $this->assertSame(['pay_fixture_a', 'pay_fixture_A'], $invoice->payments()->orderBy('id')->pluck('provider_payment_identifier')->all());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(2400, $invoice->fresh()->paid_amount);
    }
}
