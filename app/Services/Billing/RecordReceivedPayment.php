<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Support\Billing\ReceivedPaymentData;
use App\Support\Billing\ReceivedPaymentProvider;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;

/** Shared received-money operation. Authorization and transport receipts belong to the caller. */
final class RecordReceivedPayment
{
    public function __construct(private readonly InvoiceLifecycleService $invoices) {}

    public function record(Workspace $workspace, ClientInvoice $invoice, ReceivedPaymentData $facts, ?string $idempotencyKey = null): ClientInvoicePayment
    {
        $payment = $this->invoices->applyPayment($invoice, $facts->attributes($idempotencyKey), $workspace);
        $payment->setRelation('invoice', ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($payment->client_invoice_id)->firstOrFail());

        return $payment;
    }

    /** Provider retries remain safe after refund corrections change the stored status. */
    public function recordProvider(Workspace $workspace, ClientInvoice $invoice, ReceivedPaymentData $facts, ReceivedPaymentProvider $provider): ClientInvoicePayment
    {
        if ($invoice->workspace_id !== $workspace->id) {
            throw new DomainException('The invoice does not belong to this workspace.');
        }
        $existing = $this->providerPayment($workspace, $provider);
        if ($existing !== null) {
            return $this->sameProviderPayment($existing, $invoice, $facts, $provider);
        }
        try {
            return $this->invoices->applyPayment($invoice, [
                ...$facts->attributes('provider-payment:'.hash('sha256', json_encode([$workspace->id, $provider->name, $provider->paymentIdentifier], JSON_THROW_ON_ERROR))),
                'provider' => $provider->name,
                'provider_payment_identifier' => $provider->paymentIdentifier,
            ], $workspace);
        } catch (DomainException|UniqueConstraintViolationException $exception) {
            // Another sync can commit between the lookup and the invoice lock.
            // Re-read after the failed transaction, including refund corrections.
            $existing = $this->providerPayment($workspace, $provider);
            if ($existing === null) {
                throw $exception;
            }

            return $this->sameProviderPayment($existing, $invoice, $facts, $provider);
        }
    }

    private function providerPayment(Workspace $workspace, ReceivedPaymentProvider $provider): ?ClientInvoicePayment
    {
        return ClientInvoicePayment::query()->where('workspace_id', $workspace->id)
            ->where('provider', $provider->name)->where('provider_payment_identifier', $provider->paymentIdentifier)->first();
    }

    private function sameProviderPayment(ClientInvoicePayment $existing, ClientInvoice $invoice, ReceivedPaymentData $facts, ReceivedPaymentProvider $provider): ClientInvoicePayment
    {
        if ($existing->client_invoice_id !== $invoice->id || $existing->amount !== $facts->amount
            || $existing->currency !== $facts->currency
            || ! hash_equals((string) $existing->provider_payment_identifier, $provider->paymentIdentifier)) {
            throw new DomainException('The provider identity is already bound to a different payment.');
        }

        return $existing;
    }

    /** Preserve the historical standalone and issue-with-payment namespaces. */
    public static function agentKey(int $actor, string $client, string $key, ?string $operation = null): string
    {
        $namespace = $operation === null ? [$actor, $client, $key] : [$actor, $client, $operation, $key];

        return 'agent-payment:'.hash('sha256', json_encode($namespace, JSON_THROW_ON_ERROR));
    }
}
