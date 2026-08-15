<?php

namespace Tests\Feature\Api;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceReconciliationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_index_requires_read_ability_and_workspace_access(): void
    {
        [$owner, $workspace] = $this->payment('Read API');
        $path = "/api/v1/workspaces/{$workspace->public_id}/invoice-payments";

        $this->getJson($path)->assertUnauthorized();

        $reconcileOnly = $owner->createToken('reconcile-only', ['finance.reconcile'], now()->addHour())->plainTextToken;
        $this->withToken($reconcileOnly)->getJson($path)->assertForbidden();

        Auth::forgetGuards();
        $outsider = User::factory()->create();
        $outsiderToken = $outsider->createToken('outsider', ['finance.read'], now()->addHour())->plainTextToken;
        $this->withToken($outsiderToken)->getJson($path)->assertForbidden();

        Auth::forgetGuards();
        $readToken = $owner->createToken('read', ['finance.read'], now()->addHour())->plainTextToken;
        $this->withToken($readToken)->getJson($path)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'succeeded')
            ->assertJsonPath('data.0.reconciled_amount', 0)
            ->assertJsonPath('data.0.unreconciled_amount', 5000)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonMissingPath('data.0.workspace_id')
            ->assertJsonMissingPath('data.0.invoice.id_internal');
    }

    public function test_reconciliation_upsert_is_idempotent_deactivatable_and_cross_tenant_safe(): void
    {
        [$owner, $workspace, $payment] = $this->payment('Primary API', 6000);
        [, $otherWorkspace, $otherPayment] = $this->payment('Other API', 4000, $owner);
        $token = $owner->createToken(
            'finance integration',
            ['finance.read', 'finance.reconcile'],
            now()->addHour(),
        )->plainTextToken;
        $transactionUuid = (string) Str::uuid();
        $path = $this->reconciliationPath($workspace, $payment, 'bwh-finance', $transactionUuid);
        $payload = ['allocated_amount' => 2500, 'currency' => 'USD', 'reconciled_on' => '2026-08-15'];

        $this->withToken($token)->putJson($path, $payload)
            ->assertCreated()
            ->assertJsonPath('data.external_system', 'bwh-finance')
            ->assertJsonPath('data.external_transaction_id', $transactionUuid)
            ->assertJsonPath('data.is_active', true);
        $this->withToken($token)->putJson($path, $payload)->assertOk();
        $this->assertDatabaseCount('payment_reconciliations', 1);

        $crossTenantPath = $this->reconciliationPath($workspace, $otherPayment, 'bwh-finance', (string) Str::uuid());
        $this->withToken($token)->putJson($crossTenantPath, ['allocated_amount' => 100, 'currency' => 'USD'])
            ->assertNotFound();

        $indexPath = "/api/v1/workspaces/{$workspace->public_id}/invoice-payments";
        $this->withToken($token)->getJson($indexPath)
            ->assertOk()
            ->assertJsonPath('data.0.reconciled_amount', 2500)
            ->assertJsonPath('data.0.unreconciled_amount', 3500)
            ->assertJsonPath('data.0.reconciliations.0.external_transaction_id', $transactionUuid)
            ->assertJsonMissingPath('data.0.reconciliations.0.client_invoice_payment_id');

        $this->withToken($token)->deleteJson($path)
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertFalse(PaymentReconciliation::query()->sole()->is_active);
        $this->withToken($token)->getJson($indexPath)
            ->assertJsonPath('data.0.reconciled_amount', 0)
            ->assertJsonPath('data.0.unreconciled_amount', 6000);

        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    public function test_workspace_viewer_can_read_but_cannot_reconcile(): void
    {
        [, $workspace, $payment] = $this->payment('Viewer API');
        $viewer = User::factory()->create();
        $workspace->memberships()->create(['user_id' => $viewer->id, 'role' => 'member']);
        $token = $viewer->createToken('viewer', ['finance.read', 'finance.reconcile'], now()->addHour())->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments")
            ->assertOk();
        $this->withToken($token)->putJson(
            $this->reconciliationPath($workspace, $payment, 'viewer-ledger', (string) Str::uuid()),
            ['allocated_amount' => 100, 'currency' => 'USD'],
        )->assertForbidden();

        $this->assertDatabaseCount('payment_reconciliations', 0);
    }

    public function test_expired_bearer_token_is_rejected(): void
    {
        [$owner, $workspace] = $this->payment('Expired API');
        $token = $owner->createToken('expired', ['finance.read'], now()->subMinute())->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/v1/workspaces/{$workspace->public_id}/invoice-payments")
            ->assertUnauthorized();
    }

    private function reconciliationPath(
        Workspace $workspace,
        ClientInvoicePayment $payment,
        string $system,
        string $transactionUuid,
    ): string {
        return "/api/v1/workspaces/{$workspace->public_id}/invoice-payments/{$payment->public_id}"
            ."/reconciliations/{$system}/{$transactionUuid}";
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoicePayment} */
    private function payment(string $name, int $amount = 5000, ?User $owner = null): array
    {
        $owner ??= User::factory()->create();
        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
        ]);
        $workspace->memberships()->create(['user_id' => $owner->id, 'role' => 'owner']);
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' Client',
            'slug' => Str::slug($name).'-client-'.Str::lower(Str::random(5)),
        ]);
        $invoices = app(InvoiceLifecycleService::class);
        $invoice = $invoices->createDraft($workspace, $company, [
            'invoice_number' => 'INV-'.Str::upper(Str::random(10)),
            'currency' => 'USD',
        ], [[
            'type' => 'service',
            'description' => 'Synthetic API service',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
        ]]);
        $invoices->issue($invoice, $workspace);
        $payment = $invoices->applyPayment($invoice, [
            'amount' => $amount,
            'currency' => 'USD',
            'method' => 'wire',
            'status' => 'succeeded',
            'idempotency_key' => 'api-'.Str::random(16),
        ], $workspace);

        return [$owner, $workspace, $payment];
    }
}
