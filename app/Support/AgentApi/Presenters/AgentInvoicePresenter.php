<?php

namespace App\Support\AgentApi\Presenters;

use App\Models\ClientInvoice;
use App\Models\Workspace;
use App\Support\AgentApi\AgentApiVersion;

final class AgentInvoicePresenter
{
    /** @return array<string, mixed> */
    public function present(Workspace $workspace, ClientInvoice $invoice, bool $includeNotes = false): array
    {
        $payload = [
            'id' => $invoice->public_id,
            'company_id' => $invoice->clientCompany->public_id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'total_amount' => $invoice->total_amount,
            'paid_amount' => $invoice->paid_amount,
            'balance_amount' => $invoice->balance_amount,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'version' => AgentApiVersion::for($invoice),
            'web_url' => route('svc.billing.invoices.show', [$workspace, $invoice]),
            'pdf_url' => route('svc.billing.invoices.pdf', [$workspace, $invoice]),
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
            'invoice_number' => $invoice->invoice_number,
            'version' => AgentApiVersion::for($invoice),
            'web_url' => route('svc.billing.invoices.show', [$workspace, $invoice]),
        ];
    }
}
