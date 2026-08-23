<?php

namespace App\Services\Billing;

use App\Models\ClientCompany;
use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use App\Models\Workspace;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Creates a draft only from explicitly selected, approved time. */
final class InvoiceFromTimeService
{
    public function __construct(private readonly InvoiceLifecycleService $invoices) {}

    /** @param array<string, mixed> $attributes
     * @param list<string> $timeEntryIds
     * @param list<array<string, mixed>> $manualLines */
    public function create(Workspace $workspace, ClientCompany $company, array $attributes, array $timeEntryIds, array $manualLines = []): ClientInvoice
    {
        return DB::transaction(function () use ($workspace, $company, $attributes, $timeEntryIds, $manualLines): ClientInvoice {
            $entries = ClientTimeEntry::query()->where('workspace_id', $workspace->id)->whereIn('public_id', $timeEntryIds)->lockForUpdate()->get();
            if ($entries->count() !== count(array_unique($timeEntryIds))) {
                throw new DomainException('One or more selected time entries were not found.');
            }
            $lines = $manualLines;
            foreach ($entries as $entry) {
                if ($entry->client_company_id !== $company->id || $entry->status !== 'approved' || ! $entry->is_billable || $entry->is_deferred || $entry->billing_rate_amount === null || $entry->currency !== ($attributes['currency'] ?? null)) {
                    throw new DomainException('Selected time must be approved, billable, non-deferred, and currency-compatible.');
                }
                if ($entry->invoiceLines()->exists()) {
                    throw new DomainException('Selected time has already been allocated to an invoice.');
                }
                $lines[] = ['client_project_id' => $entry->client_project_id, 'type' => 'time', 'description' => $entry->description, 'quantity' => (string) ($entry->minutes / 60), 'unit_amount' => $entry->billing_rate_amount, 'tax_amount' => 0, 'sort_order' => count($lines), '_entry_id' => $entry->id];
            }
            $invoice = $this->invoices->createDraft($workspace, $company, $attributes, $lines);
            foreach ($invoice->lines as $index => $line) {
                if (isset($lines[$index]['_entry_id'])) {
                    $line->timeEntries()->attach($lines[$index]['_entry_id'], ['workspace_id' => $workspace->id]);
                }
            }

            return $invoice->fresh(['lines', 'clientCompany']);
        });
    }
}
