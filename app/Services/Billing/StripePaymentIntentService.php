<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeCustomer;
use App\Models\Workspace;
use App\Services\WorkspaceAuthorization;
use App\Support\Billing\InvoiceStatus;
use DomainException;
use Illuminate\Support\Facades\DB;

final class StripePaymentIntentService
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly InvoiceLifecycleService $invoices,
        private readonly WorkspaceAuthorization $workspaceAuthorization,
    ) {}

    /** @return array{payment_intent_id:string,client_secret:string|null,payment:ClientInvoicePayment} */
    public function create(ClientInvoice $invoice, ?Workspace $workspace, ?string $paymentMethodId, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') {
            throw new DomainException('A Stripe idempotency key is required.');
        }
        if ($workspace !== null && ! $this->workspaceAuthorization->isOwnedBy($workspace, $invoice)) {
            throw new DomainException('Invoice does not belong to this workspace.');
        }

        return DB::transaction(function () use ($invoice, $workspace, $paymentMethodId, $idempotencyKey): array {
            $invoice = ClientInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            return $this->createLocked($invoice, $workspace, $paymentMethodId, $idempotencyKey);
        });
    }

    /** @return array{payment_intent_id:string,client_secret:string|null,payment:ClientInvoicePayment} */
    private function createLocked(ClientInvoice $invoice, ?Workspace $workspace, ?string $paymentMethodId, string $idempotencyKey): array
    {
        if (! in_array($invoice->status, InvoiceStatus::collectible(), true)) {
            throw new DomainException('Stripe payment intents require an issued invoice with a balance.');
        }
        if ($invoice->balance_amount <= 0) {
            throw new DomainException('The invoice has no remaining balance.');
        }

        $existing = $invoice->payments()
            ->where('idempotency_key', $idempotencyKey)
            ->where('provider', 'stripe')
            ->first();
        if ($existing !== null && $existing->provider_payment_identifier !== null) {
            return [
                'payment_intent_id' => $existing->provider_payment_identifier,
                'client_secret' => null,
                'payment' => $existing,
            ];
        }

        // Pending intents reserve balance: without this, two concurrent requests with
        // distinct client-supplied idempotency keys each mint a full-balance intent
        // and the customer is charged twice.
        $reservedPending = (int) $invoice->payments()
            ->where('status', 'pending')
            ->get(['amount', 'refunded_amount'])
            ->sum(fn (ClientInvoicePayment $payment): int => max(0, $payment->amount - $payment->refunded_amount));
        $chargeAmount = $invoice->balance_amount - $reservedPending;
        if ($chargeAmount <= 0) {
            throw new DomainException('A pending payment already reserves the remaining invoice balance.');
        }

        $params = [
            'amount' => $chargeAmount,
            'currency' => strtolower($invoice->currency),
            'description' => 'Invoice '.$invoice->invoice_number,
            'metadata' => [
                'invoice_public_id' => $invoice->public_id,
                'workspace_public_id' => $invoice->workspace->public_id,
            ],
        ];
        $customer = ClientStripeCustomer::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('client_company_id', $invoice->client_company_id)
            ->first();
        if ($customer !== null) {
            $params['customer'] = $customer->stripe_customer_id;
        }
        if ($paymentMethodId !== null && $paymentMethodId !== '') {
            $params['payment_method'] = $paymentMethodId;
        }

        $options = ['idempotency_key' => $idempotencyKey];
        $intent = $this->gateway->client()->paymentIntents->create($params, $options);
        $intentId = (string) $intent->id;
        if ($intentId === '') {
            throw new DomainException('Stripe returned an invalid payment intent.');
        }

        $payment = $this->invoices->applyPayment($invoice, [
            'amount' => $chargeAmount,
            'currency' => $invoice->currency,
            'received_on' => now()->toDateString(),
            'method' => 'stripe',
            'status' => 'pending',
            'provider' => 'stripe',
            'provider_payment_identifier' => $intentId,
            'idempotency_key' => $idempotencyKey,
        ], $workspace);

        return [
            'payment_intent_id' => $intentId,
            'client_secret' => is_string($intent->client_secret ?? null) ? $intent->client_secret : null,
            'payment' => $payment,
        ];
    }
}
