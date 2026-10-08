<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;
use App\Support\Billing\InvoiceKind;

final class AgentInvoicePresenter
{
    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientInvoice $invoice, bool $includeNotes = false): array
    {
        $payload = [
            'id' => $invoice->public_id,
            'company_id' => $invoice->clientCompany->public_id,
            'company_name' => $invoice->clientCompany->name,
            'service_period_start' => $invoice->service_period_start?->toDateString(),
            'service_period_end' => $invoice->service_period_end?->toDateString(),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'invoice_kind' => $invoice->invoiceKindValue(),
            // What `invoices.update_draft` accepts (#349): a generated draft
            // is regenerated, never edited by hand.
            'editable' => $invoice->status === 'draft' && $invoice->invoiceKindValue() === InvoiceKind::AdHoc->value,
            'linked_time_state' => $this->linkedTimeState($invoice),
            'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'automatic_delivery_status' => $includeNotes ? $invoice->automatic_delivery_status : null,
            'automatic_delivery_due_at' => $includeNotes ? $invoice->automatic_delivery_due_at?->toISOString() : null,
            'version' => AgentApiVersion::for($invoice),
            'web_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false),
            'pdf_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.pdf', [$workspace, $invoice], absolute: false),
        ];

        if ($includeNotes) {
            $payload['notes'] = $invoice->notes;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function mutation(Workspace $workspace, ClientInvoice $invoice): array
    {
        return [
            'id' => $invoice->public_id,
            'status' => $invoice->status,
            'linked_time_state' => $this->linkedTimeState($invoice),
            'invoice_number' => $invoice->invoice_number,
            'version' => AgentApiVersion::for($invoice),
            'web_url' => rtrim((string) config('app.url'), '/').route('svc.billing.invoices.show', [$workspace, $invoice], absolute: false),
        ];
    }

    private function linkedTimeState(ClientInvoice $invoice): string
    {
        return match ($invoice->status) {
            'draft' => 'reserved',
            'void' => 'released',
            default => 'consumed',
        };
    }
}
