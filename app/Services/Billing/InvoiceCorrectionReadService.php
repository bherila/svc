<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceCorrection;
use App\Models\Workspace;

/** One evidence read boundary for browser and agent clients. Authorization belongs to each entry point. */
final class InvoiceCorrectionReadService
{
    public function __construct(private readonly InvoiceCorrectionRepository $records) {}

    /** @return array<string, mixed> */
    public function get(Workspace $workspace, ClientInvoice $invoice, string $publicId): array
    {
        $record = $this->records->find($workspace, $invoice, $publicId);

        return [
            'id' => $record->public_id,
            'from_revision' => $record->from_revision,
            'to_revision' => $record->to_revision,
            'reason' => $record->reason,
            'previous_due_date' => $record->previous_due_date,
            'due_date' => $record->due_date,
            'line_diffs' => $record->line_diffs,
            'created_at' => $record->created_at?->toISOString(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function browserHistory(Workspace $workspace, ClientInvoice $invoice): array
    {
        return array_values($this->records->recent($workspace, $invoice)->map(fn (ClientInvoiceCorrection $record): array => $this->summary($workspace, $invoice, $record, 'svc.billing.invoices.corrections.show'))->all());
    }

    /** @return list<array<string, mixed>> */
    public function agentHistory(Workspace $workspace, ClientInvoice $invoice): array
    {
        return array_values($this->records->recent($workspace, $invoice)->map(fn (ClientInvoiceCorrection $record): array => $this->summary($workspace, $invoice, $record, 'agent-api.v1.invoices.corrections.show'))->all());
    }

    /** @return array<string, mixed>|null */
    public function currentAgentSummary(Workspace $workspace, ClientInvoice $invoice): ?array
    {
        $record = $this->records->atRevision($workspace, $invoice);

        return $record === null ? null : $this->summary($workspace, $invoice, $record, 'agent-api.v1.invoices.corrections.show');
    }

    /** @return array<string, mixed> */
    private function summary(Workspace $workspace, ClientInvoice $invoice, ClientInvoiceCorrection $record, string $route): array
    {
        return [
            'id' => $record->public_id,
            'from_revision' => $record->from_revision,
            'to_revision' => $record->to_revision,
            'reason' => $record->reason,
            'href' => route($route, [$workspace, $invoice, $record->public_id], absolute: false),
        ];
    }
}
