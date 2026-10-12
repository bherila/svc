<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\Workspace;
use DomainException;

/** Persistence for correction evidence, always bounded by workspace and invoice. */
final class InvoiceCorrectionRepository
{
    /** @param list<array<string, mixed>> $lineDiffs */
    public function append(Workspace $workspace, ClientInvoice $invoice, int $fromRevision, string $reason, ?string $previousDueDate, array $lineDiffs): ClientInvoiceCorrection
    {
        if ($invoice->workspace_id !== $workspace->id) {
            throw new DomainException('Correction evidence must belong to the invoice workspace.');
        }

        return ClientInvoiceCorrection::query()->create([
            'workspace_id' => $workspace->id,
            'client_invoice_id' => $invoice->id,
            'from_revision' => $fromRevision,
            'to_revision' => $invoice->document_revision,
            'reason' => trim($reason),
            'previous_due_date' => $previousDueDate,
            'due_date' => $invoice->due_date?->toDateString(),
            'line_diffs' => $lineDiffs,
        ]);
    }

    public function find(Workspace $workspace, ClientInvoice $invoice, string $publicId): ClientInvoiceCorrection
    {
        return ClientInvoiceCorrection::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_invoice_id', $invoice->id)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }
}
