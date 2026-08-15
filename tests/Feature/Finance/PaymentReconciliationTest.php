<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\PaymentReconciliationService;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upsert_is_idempotent_and_supports_many_to_many_allocations(): void
    {
        [$creator, $workspace, $firstPayment] = $this->payment('First invoice', 6000);
        [, , $secondPayment] = $this->payment('Second invoice', 4000, $workspace, $creator);
        $service = app(PaymentReconciliationService::class);
        $transactionUuid = (string) Str::uuid();

        $first = $service->upsert($workspace, $firstPayment, $creator, [
            'external_system_slug' => 'Fidelity',
            'external_transaction_uuid' => $transactionUuid,
            'allocated_amount' => 3000,
            'currency' => 'USD',
            'reconciled_on' => '2026-08-15',
        ]);
        $same = $service->upsert($workspace, $firstPayment, $creator, [
            'external_system_slug' => 'fidelity',
            'external_transaction_uuid' => strtoupper($transactionUuid),
            'allocated_amount' => 3000,
            'currency' => 'USD',
            'reconciled_on' => '2026-08-15',
        ]);
        $second = $service->upsert($workspace, $secondPayment, $creator, [
            'external_system_slug' => 'fidelity',
            'external_transaction_uuid' => $transactionUuid,
            'allocated_amount' => 2000,
            'currency' => 'USD',
        ]);
        $other = $service->upsert($workspace, $firstPayment, $creator, [
            'external_system_slug' => 'fidelity',
            'external_transaction_uuid' => (string) Str::uuid(),
            'allocated_amount' => 2000,
            'currency' => 'USD',
        ]);

        $this->assertSame($first->id, $same->id);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->id, $other->id);
        $this->assertSame(3, PaymentReconciliation::query()->count());
        $this->assertSame(2, $firstPayment->fresh()->reconciliations()->count());
        $this->assertSame(1, $secondPayment->fresh()->reconciliations()->count());
        $this->assertSame($workspace->id, $first->fresh()->workspaceId());
        $this->assertSame($creator->id, $first->fresh()->createdBy->id);
        $this->assertSame('2026-08-15', $first->fresh()->reconciled_on->toDateString());

        $serialized = $first->fresh()->toArray();
        $this->assertArrayHasKey('public_id', $serialized);
        $this->assertArrayNotHasKey('id', $serialized);
        $this->assertArrayNotHasKey('workspace_id', $serialized);
        $this->assertArrayNotHasKey('client_invoice_payment_id', $serialized);
        $this->assertArrayNotHasKey('created_by_user_id', $serialized);
    }

    public function test_active_allocations_are_limited_by_payment_net_of_refunds(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Refunded payment', 10000);
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 1000, $workspace);
        $service = app(PaymentReconciliationService::class);

        $allocation = $service->upsert($workspace, $payment, $creator, [
            'external_system_slug' => 'ledger',
            'external_transaction_uuid' => (string) Str::uuid(),
            'allocated_amount' => 9000,
            'currency' => 'USD',
        ]);
        $this->assertTrue($allocation->is_active);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('net of refunds');
        $service->upsert($workspace, $payment, $creator, [
            'external_system_slug' => 'ledger',
            'external_transaction_uuid' => (string) Str::uuid(),
            'allocated_amount' => 1,
            'currency' => 'USD',
        ]);
    }

    public function test_a_later_refund_cannot_reduce_net_payment_below_active_allocations(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Later refund', 10000);
        app(PaymentReconciliationService::class)->upsert(
            $workspace,
            $payment,
            $creator,
            $this->attributes(9000, 'USD'),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('active finance reconciliation allocations');
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 1001, $workspace);
    }

    public function test_reconciliation_date_must_be_a_real_calendar_date(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Invalid calendar date', 1000);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('valid YYYY-MM-DD date');
        app(PaymentReconciliationService::class)->upsert($workspace, $payment, $creator, [
            ...$this->attributes(100, 'USD'),
            'reconciled_on' => '2026-02-30',
        ]);
    }

    public function test_only_successful_matching_currency_payments_can_be_reconciled(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Pending payment', 1000, null, null, 'pending');
        $service = app(PaymentReconciliationService::class);

        try {
            $service->upsert($workspace, $payment, $creator, $this->attributes(100, 'USD'));
            $this->fail('Expected pending payments to be rejected.');
        } catch (DomainException $exception) {
            $this->assertSame('Only successful payments can be reconciled.', $exception->getMessage());
        }

        [, $workspace, $succeeded] = $this->payment('Currency payment', 1000, null, null, 'succeeded', 'EUR');
        $creator = $workspace->users()->firstOrFail();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('match the payment currency');
        $service->upsert($workspace, $succeeded, $creator, $this->attributes(100, 'USD'));
    }

    public function test_deactivated_allocations_do_not_consume_capacity_and_can_be_reactivated(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Active flag', 5000);
        $service = app(PaymentReconciliationService::class);
        $attributes = $this->attributes(5000, 'USD');

        $inactive = $service->upsert($workspace, $payment, $creator, [...$attributes, 'is_active' => false]);
        $this->assertFalse($inactive->is_active);

        $active = $service->upsert($workspace, $payment, $creator, [...$attributes, 'is_active' => true]);
        $this->assertSame($inactive->id, $active->id);
        $this->assertTrue($active->is_active);
    }

    public function test_payment_from_another_workspace_returns_a_typed_not_found(): void
    {
        [$creator, $workspace] = $this->payment('Owner workspace');
        [, $otherWorkspace, $otherPayment] = $this->payment('Other workspace');
        $service = app(PaymentReconciliationService::class);

        try {
            $service->upsert($workspace, $otherPayment, $creator, $this->attributes(100, 'USD'));
            $this->fail('Expected a workspace ownership not-found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(ClientInvoicePayment::class, $exception->getModel());
        }

        $allocation = $service->upsert($workspace, $this->payment('Third invoice', 1000, $workspace, $creator)[2], $creator, $this->attributes(100, 'USD'));
        try {
            $service->assertTenant($otherWorkspace, $allocation);
            $this->fail('Expected a reconciliation ownership not-found exception.');
        } catch (ModelNotFoundException $exception) {
            $this->assertSame(PaymentReconciliation::class, $exception->getModel());
        }

        $this->assertNotSame($workspace->id, $otherWorkspace->id);
    }

    public function test_creator_must_be_a_workspace_member_and_input_identifiers_are_validated(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Validation workspace');
        $outsider = User::factory()->create(['email' => 'outsider@synthetic.test']);
        $service = app(PaymentReconciliationService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->upsert($workspace, $payment, $outsider, $this->attributes(100, 'USD'));
    }

    /** @return array<string, mixed> */
    private function attributes(int $amount, string $currency): array
    {
        return [
            'external_system_slug' => 'ledger',
            'external_transaction_uuid' => (string) Str::uuid(),
            'allocated_amount' => $amount,
            'currency' => $currency,
        ];
    }

    /** @return array{0:User,1:Workspace,2:ClientInvoicePayment} */
    private function payment(
        string $name,
        int $amount = 10000,
        ?Workspace $workspace = null,
        ?User $creator = null,
        string $status = 'succeeded',
        string $currency = 'USD',
    ): array {
        $workspace ??= Workspace::query()->create(['name' => $name, 'slug' => str()->slug($name).'-'.str()->random(5)]);
        $creator ??= User::factory()->create();
        if (! $workspace->memberships()->where('user_id', $creator->id)->exists()) {
            $workspace->users()->attach($creator, ['role' => 'owner']);
        }
        $company = ClientCompany::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name.' client',
            'slug' => str()->slug($name).'-client-'.str()->random(5),
        ]);
        $invoiceService = app(InvoiceLifecycleService::class);
        $invoice = $invoiceService->createDraft($workspace, $company, [
            'invoice_number' => 'INV-SYNTH-'.str()->upper(str()->random(10)),
            'currency' => $currency,
        ], [[
            'type' => 'service',
            'description' => 'Synthetic reconciliation service',
            'quantity' => '1',
            'unit_amount' => $amount,
            'tax_amount' => 0,
        ]]);
        $invoiceService->issue($invoice, $workspace);
        $payment = $invoiceService->applyPayment($invoice, [
            'amount' => $amount,
            'currency' => $currency,
            'method' => 'wire',
            'status' => $status,
            'idempotency_key' => 'payment-'.str()->random(16),
        ], $workspace);

        return [$creator, $workspace, $payment];
    }
}
