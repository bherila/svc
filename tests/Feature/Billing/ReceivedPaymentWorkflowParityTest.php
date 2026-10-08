<?php

namespace Tests\Feature\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Billing\RecordReceivedPayment;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\ReceivedPaymentData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

final class ReceivedPaymentWorkflowParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_standalone_api_and_issue_with_payment_use_the_same_received_money_operation(): void
    {
        Mail::fake();
        $this->travelTo('2026-10-08 12:00:00');
        config(['agent_api.writes_enabled' => true, 'agent_api.invoice_writes_enabled' => true, 'agent_api.payment_writes_enabled' => true]);
        $owner = User::factory()->create();
        $workspace = Workspace::query()->create(['name' => 'Synthetic received money', 'slug' => 'synthetic-received-money']);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create(['workspace_id' => $workspace->id, 'name' => 'Synthetic received client', 'slug' => 'synthetic-received-client']);
        $lifecycle = app(InvoiceLifecycleService::class);
        $web = $lifecycle->issue($this->draft($workspace, $company, 'WEB'), $workspace);
        $api = $lifecycle->issue($this->draft($workspace, $company, 'API'), $workspace);
        $issue = $this->draft($workspace, $company, 'ISSUE');
        $pending = $lifecycle->issue($this->draft($workspace, $company, 'PENDING'), $workspace);
        $facts = ['amount' => 1200, 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => '2026-10-08'];
        $this->actingAs($owner)->post('/workspaces/'.$workspace->public_id.'/invoices/'.$web->public_id.'/payments', $facts, ['Idempotency-Key' => 'synthetic-web-receipt'])->assertRedirect()->assertSessionHas('status', 'Payment recorded.');
        $this->post('/workspaces/'.$workspace->public_id.'/invoices/'.$web->public_id.'/payments', $facts, ['Idempotency-Key' => 'synthetic-web-receipt'])->assertRedirect();
        $this->assertDatabaseCount('client_invoice_payments', 1);
        $this->postJson('/workspaces/'.$workspace->public_id.'/invoices/'.$pending->public_id.'/payments', ['amount' => 100, 'currency' => 'USD', 'method' => 'Synthetic check', 'status' => 'pending', 'notes' => 'Synthetic web bookkeeping', 'idempotency_key' => 'synthetic-web-pending'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame('Synthetic web bookkeeping', ClientInvoicePayment::query()->where('workspace_id', $workspace->id)->where('idempotency_key', 'synthetic-web-pending')->firstOrFail()->notes);
        $this->assertSame(1200, $pending->fresh()->balance_amount);
        $principal = $this->actingAsMcp($owner, ['billing:deliver', 'payments:record']);
        $client = $principal->token()->oauth_client_id;
        $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/payments', ['invoice_id' => $api->public_id, ...$facts], ['Idempotency-Key' => 'synthetic-agent-receipt'])->assertCreated();
        $this->postJson('/api/v1/workspaces/'.$workspace->public_id.'/invoices/'.$issue->public_id.'/issue', ['expected_version' => AgentApiVersion::for($issue), 'confirm' => true, 'payment' => $facts], ['Idempotency-Key' => 'synthetic-issue-receipt'])->assertOk();
        foreach ([$web, $api, $issue] as $invoice) {
            $this->assertSame('paid', $invoice->fresh()->status);
            $this->assertSame(0, $invoice->fresh()->balance_amount);
        }
        $this->assertDatabaseHas('client_invoice_payments', ['workspace_id' => $workspace->id, 'client_invoice_id' => $web->id, 'idempotency_key' => 'synthetic-web-receipt']);
        $this->assertDatabaseHas('client_invoice_payments', ['workspace_id' => $workspace->id, 'client_invoice_id' => $api->id, 'idempotency_key' => RecordReceivedPayment::agentKey($owner->id, $client, 'synthetic-agent-receipt')]);
        $this->assertDatabaseHas('client_invoice_payments', ['workspace_id' => $workspace->id, 'client_invoice_id' => $issue->id, 'idempotency_key' => RecordReceivedPayment::agentKey($owner->id, $client, 'synthetic-issue-receipt', 'invoices.issue')]);
    }

    public function test_shared_operation_cannot_record_against_another_workspaces_invoice(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Synthetic local money', 'slug' => 'synthetic-local-money']);
        $foreign = Workspace::query()->create(['name' => 'Synthetic foreign money', 'slug' => 'synthetic-foreign-money']);
        $company = ClientCompany::query()->create(['workspace_id' => $foreign->id, 'name' => 'Synthetic foreign client', 'slug' => 'synthetic-foreign-client']);
        $invoice = ClientInvoice::query()->create(['workspace_id' => $foreign->id, 'client_company_id' => $company->id, 'invoice_number' => 'SYNTHETIC-FOREIGN-PAYMENT', 'status' => 'issued', 'currency' => 'USD', 'total_amount' => 1200, 'balance_amount' => 1200]);
        $this->expectException(ModelNotFoundException::class);
        try {
            app(RecordReceivedPayment::class)->record($workspace, $invoice, ReceivedPaymentData::from(['amount' => 1200, 'currency' => 'USD', 'method' => 'Synthetic wire', 'received_on' => now()->toDateString()]));
        } finally {
            $this->assertDatabaseCount('client_invoice_payments', 0);
            $this->assertSame(1200, $invoice->fresh()->balance_amount);
        }
    }

    private function draft(Workspace $workspace, ClientCompany $company, string $suffix): ClientInvoice
    {
        return app(InvoiceLifecycleService::class)->createDraft($workspace, $company, ['invoice_number' => 'SYNTHETIC-RECEIVED-'.$suffix, 'currency' => 'USD'], [['type' => 'service', 'description' => 'Synthetic services', 'quantity' => '1', 'unit_amount' => 1200, 'tax_amount' => 0]]);
    }
}
