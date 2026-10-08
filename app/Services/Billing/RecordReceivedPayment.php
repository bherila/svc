<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoicePayment;
use App\Models\Workspace;
use App\Support\Billing\ReceivedPaymentData;

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

    /** Preserve the historical standalone and issue-with-payment namespaces. */
    public static function agentKey(int $actor, string $client, string $key, ?string $operation = null): string
    {
        $namespace = $operation === null ? [$actor, $client, $key] : [$actor, $client, $operation, $key];

        return 'agent-payment:'.hash('sha256', json_encode($namespace, JSON_THROW_ON_ERROR));
    }
}
