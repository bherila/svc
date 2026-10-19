<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ClientInvoicePayment;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoicePaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientInvoicePayment */
class InvoicePaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $netAmount = max(0, $this->amount - $this->refunded_amount);
        $reconciledAmount = (int) ($this->getAttribute('active_reconciled_amount') ?? 0);

        return [
            'id' => $this->public_id,
            // The payment's revision, which a change to any of its allocations
            // also advances: compare it, not updated_at, to tell a change.
            'version' => AgentApiVersion::for($this->resource),
            'status' => $this->status,
            'amount' => $this->amount,
            'refunded_amount' => $this->refunded_amount,
            // While `disputed`: what the processor's dispute holds back, the
            // whole amount when it did not say. Null otherwise.
            'disputed_amount' => $this->status === InvoicePaymentStatus::Disputed->value
                ? ($this->disputed_amount ?? $this->amount)
                : null,
            'net_amount' => $netAmount,
            'reconciled_amount' => $reconciledAmount,
            'unreconciled_amount' => max(0, $netAmount - $reconciledAmount),
            'currency' => $this->currency,
            'received_on' => $this->received_on?->toDateString(),
            'method' => $this->method,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'invoice' => [
                'id' => $this->invoice->public_id,
                'number' => $this->invoice->invoice_number,
                'status' => $this->invoice->status,
                'total_amount' => $this->invoice->total_amount,
                'currency' => $this->invoice->currency,
                'client' => [
                    'id' => $this->invoice->clientCompany->public_id,
                    'name' => $this->invoice->clientCompany->name,
                ],
            ],
            'reconciliations' => PaymentReconciliationResource::collection($this->reconciliations),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
