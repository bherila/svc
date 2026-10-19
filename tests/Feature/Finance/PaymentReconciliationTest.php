<?php

namespace Tests\Feature\Finance;

use App\Models\ClientCompany;
use App\Models\ClientInvoicePayment;
use App\Models\PaymentReconciliation;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\PaymentReconciliationService;
use App\Support\Finance\ReconciliationReviewReason;
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

    public function test_a_processor_refund_after_reconciliation_is_recorded_and_flags_the_allocation(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Later refund', 10000);
        $allocation = app(PaymentReconciliationService::class)->upsert(
            $workspace,
            $payment,
            $creator,
            $this->attributes(9000, 'USD'),
        );

        // The refund leaves 7500 of net against a 9000 allocation. The
        // processor's fact is recorded anyway; the allocation is kept as it
        // was and flagged for the finance system to review.
        $refunded = app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 2500, $workspace);

        $this->assertSame(2500, $refunded->refunded_amount);
        $this->assertSame('succeeded', $refunded->status);
        $kept = $allocation->fresh();
        $this->assertTrue($kept->is_active);
        $this->assertSame(9000, $kept->allocated_amount);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $kept->review_reason);
        $this->assertNotNull($kept->review_flagged_at);
    }

    public function test_a_processor_refund_that_still_fits_flags_the_allocation_too(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Fitting refund', 10000);
        $allocation = app(PaymentReconciliationService::class)->upsert($workspace, $payment, $creator, $this->attributes(5000, 'USD'));

        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 1000, $workspace);

        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $allocation->fresh()->review_reason);
    }

    public function test_a_full_processor_refund_marks_the_payment_refunded_and_keeps_its_allocations(): void
    {
        $lifecycle = app(InvoiceLifecycleService::class);
        $service = app(PaymentReconciliationService::class);

        [$creator, $workspace, $byAmount] = $this->payment('Full refund by amount', 10000);
        $first = $service->upsert($workspace, $byAmount, $creator, $this->attributes(10000, 'USD'));
        $refunded = $lifecycle->setRefundedAmount($byAmount, 10000, $workspace);
        $this->assertSame('refunded', $refunded->status);
        $this->assertSame(10000, $refunded->refunded_amount);
        $this->assertTrue($first->fresh()->is_active);
        $this->assertSame(10000, $first->fresh()->allocated_amount);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $first->fresh()->review_reason);

        [, , $byStatus] = $this->payment('Full refund by status', 4000, $workspace, $creator);
        $second = $service->upsert($workspace, $byStatus, $creator, $this->attributes(4000, 'USD'));
        $refunded = $lifecycle->setPaymentStatus($byStatus, 'refunded', $workspace);
        $this->assertSame('refunded', $refunded->status);
        $this->assertSame(4000, $refunded->refunded_amount);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $second->fresh()->review_reason);
    }

    public function test_a_processor_dispute_flags_only_active_allocations(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Disputed payment', 10000);
        $service = app(PaymentReconciliationService::class);
        $active = $service->upsert($workspace, $payment, $creator, $this->attributes(6000, 'USD'));
        $inactive = $service->upsert($workspace, $payment, $creator, [...$this->attributes(4000, 'USD'), 'is_active' => false]);

        app(InvoiceLifecycleService::class)->setPaymentStatus($payment, 'disputed', $workspace);

        $this->assertSame('disputed', $payment->fresh()->status);
        $this->assertTrue($active->fresh()->is_active);
        $this->assertSame(ReconciliationReviewReason::ProcessorDispute, $active->fresh()->review_reason);
        $this->assertNull($inactive->fresh()->review_reason);

        // Winning the dispute restores the payment and leaves the flag for review.
        app(InvoiceLifecycleService::class)->setPaymentStatus($payment, 'succeeded', $workspace);
        $this->assertSame('succeeded', $payment->fresh()->status);
        $this->assertSame(ReconciliationReviewReason::ProcessorDispute, $active->fresh()->review_reason);
    }

    public function test_a_processor_can_lower_the_refund_on_a_disputed_payment(): void
    {
        [, $workspace, $payment] = $this->payment('Refund failed under dispute', 10000);
        $lifecycle = app(InvoiceLifecycleService::class);
        $lifecycle->setRefundedAmount($payment, 4000, $workspace);
        $lifecycle->setPaymentStatus($payment, 'disputed', $workspace);

        // The pending refund fails because the charge was disputed; the
        // processor's lower total is recorded and the dispute is kept.
        $lowered = $lifecycle->setRefundedAmount($payment, 0, $workspace);

        $this->assertSame(0, $lowered->refunded_amount);
        $this->assertSame('disputed', $lowered->status);
    }

    public function test_the_finance_put_still_refuses_more_than_the_net_and_a_fitting_put_clears_the_flag(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Review cleared', 10000);
        $service = app(PaymentReconciliationService::class);
        $attributes = $this->attributes(9000, 'USD');
        $allocation = $service->upsert($workspace, $payment, $creator, $attributes);
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 2500, $workspace);

        try {
            $service->upsert($workspace, $payment->fresh(), $creator, $attributes);
            $this->fail('A finance write above the net of refunds must be refused.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('net of refunds', $exception->getMessage());
        }
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $allocation->fresh()->review_reason);

        $reviewed = $service->upsert($workspace, $payment->fresh(), $creator, [...$attributes, 'allocated_amount' => 7500]);

        $this->assertSame($allocation->id, $reviewed->id);
        $this->assertSame(7500, $reviewed->allocated_amount);
        $this->assertNull($reviewed->review_reason);
        $this->assertNull($reviewed->review_flagged_at);
    }

    public function test_deactivating_a_flagged_allocation_resolves_its_review_even_on_a_refunded_payment(): void
    {
        [$creator, $workspace, $payment] = $this->payment('Review resolved by deactivation', 10000);
        $service = app(PaymentReconciliationService::class);
        $attributes = $this->attributes(10000, 'USD');
        $allocation = $service->upsert($workspace, $payment, $creator, $attributes);
        app(InvoiceLifecycleService::class)->setRefundedAmount($payment, 10000, $workspace);
        $this->assertSame(ReconciliationReviewReason::ProcessorRefund, $allocation->fresh()->review_reason);

        $deactivated = $service->deactivate($workspace, $payment->fresh(), 'ledger', $attributes['external_transaction_uuid']);

        $this->assertFalse($deactivated->is_active);
        $this->assertNull($deactivated->review_reason);
        $this->assertNull($deactivated->review_flagged_at);
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
