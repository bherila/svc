<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\ClientStripeCustomer;
use App\Models\Workspace;
use DomainException;

final class StripePaymentIntentService
{
    public function __construct(
        private readonly StripeGateway $gateway,
        private readonly InvoiceLifecycleService $invoices,
    ) {}

    /** @return array{payment_intent_id:string,client_secret:string|null,payment:ClientInvoicePayment} */
    public function create(ClientInvoice $invoice, ?Workspace $workspace, ?string $paymentMethodId, string $idempotencyKey): array
    {
        if (trim($idempotencyKey) === '') {
            throw new DomainException('A Stripe idempotency key is required.');
        }
        if ($workspace !== null && $invoice->workspace_id !== $workspace->id) {
            throw new DomainException('Invoice does not belong to this workspace.');
        }
        if (! in_array($invoice->status, ['issued', 'partially_paid'], true)) {
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

        $params = [
            'amount' => $invoice->balance_amount,
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
            'amount' => $invoice->balance_amount,
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
