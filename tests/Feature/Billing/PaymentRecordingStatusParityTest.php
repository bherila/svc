<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoicePaymentStatus;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every door that records a payment by hand accepts the same statuses (#454):
 * the console command, `payments.record` and the invoice screen's form, all
 * through {@see ReceivedPaymentData}. A status one door records and another
 * refuses would be a payment the API cannot reproduce.
 */
final class PaymentRecordingStatusParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_console_the_api_and_the_screen_record_the_same_statuses(): void
    {
        config(['agent_api.writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $owner = User::factory()->create(['email' => 'synthetic-'.Str::lower(Str::random(8)).'@example.test']);
        $workspace = Workspace::query()->create(['name' => 'Synthetic status parity', 'slug' => 'synthetic-'.Str::lower(Str::random(10))]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic client', 'slug' => 'synthetic-client']);

        $accepted = ['console' => [], 'api' => [], 'screen' => []];
        foreach (InvoicePaymentStatus::all() as $status) {
            $invoice = $this->issuedInvoice($workspace, $company);
            $exit = $this->artisan('svc:billing:payment', [
                'invoice' => $invoice->public_id, 'amount' => '100', 'currency' => 'USD', 'method' => 'wire',
                '--workspace' => $workspace->public_id, '--status' => $status,
            ])->run();
            if ($exit === 0) {
                $accepted['console'][] = $status;
            }

            $invoice = $this->issuedInvoice($workspace, $company);
            $this->actingAsMcp($owner, ['payments:record', 'billing:read']);
            $response = $this->withHeader('Idempotency-Key', 'synthetic-parity-'.$status)->postJson('/api/v1/workspaces/'.$workspace->public_id.'/payments', [
                'invoice_id' => $invoice->public_id, 'amount' => 100, 'currency' => 'USD', 'method' => 'wire', 'status' => $status,
                'confirm' => true, 'expected_version' => AgentApiVersion::for($invoice),
            ]);
            if ($response->status() === 201) {
                $accepted['api'][] = $status;
            }

            $invoice = $this->issuedInvoice($workspace, $company);
            $this->actingAs($owner)->from('/synthetic-back')->post('/workspaces/'.$workspace->public_id.'/invoices/'.$invoice->public_id.'/payments', [
                'amount' => 100, 'currency' => 'USD', 'method' => 'wire', 'status' => $status,
            ]);
            if (ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('client_invoice_id', $invoice->id)->exists()) {
                $accepted['screen'][] = $status;
            }
        }

        $recordable = ReceivedPaymentData::recordableStatusValues();
        $this->assertSame(['pending', 'succeeded'], $recordable);
        $this->assertSame(['console' => $recordable, 'api' => $recordable, 'screen' => $recordable], $accepted);
        // And nothing refused left a row behind.
        $this->assertSame(6, ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->count());
    }

    private function issuedInvoice(Workspace $workspace, ClientCompany $company): ClientInvoice
    {
        $service = app(InvoiceLifecycleService::class);

        return $service->issue($service->createDraft($workspace, $company, ['currency' => 'USD', 'invoice_number' => 'SYN-'.Str::upper(Str::random(10))], [
            ['type' => 'adjustment', 'description' => 'Synthetic service', 'quantity' => 1, 'unit_amount' => 10000],
        ]), $workspace);
    }
}
