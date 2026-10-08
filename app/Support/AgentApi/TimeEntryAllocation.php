<?php

namespace App\Support\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Database-free allocation classification after a tenant-scoped eager load. */
final readonly class TimeEntryAllocation
{
    public function __construct(public ?string $invoiceId, public string $state) {}

    public static function fromEntry(ClientTimeEntry $entry): self
    {
        if (! $entry->relationLoaded('invoiceLines')) {
            throw new \LogicException('Load scoped time-entry allocations before classifying them.');
        }
        $invoices = [];
        foreach ($entry->invoiceLines as $line) {
            if (! $line->relationLoaded('invoice')) {
                throw new \LogicException('Load allocation invoices before classifying them.');
            }
            $invoice = $line->invoice;
            $pivot = $line->relationLoaded('pivot') ? $line->getRelation('pivot') : null;
            if ($line->workspace_id === $entry->workspace_id
                && $pivot instanceof Pivot
                && (int) $pivot->getAttribute('workspace_id') === $entry->workspace_id
                && $invoice instanceof ClientInvoice
                && $invoice->workspace_id === $entry->workspace_id
                && $invoice->client_company_id === $entry->client_company_id) {
                $invoices[$invoice->id] = $invoice;
            }
        }
        ksort($invoices);
        // A non-draft allocation takes precedence if legacy data names more
        // than one invoice: charged time must never appear merely reserved.
        foreach ($invoices as $invoice) {
            if ($invoice->status !== 'draft') {
                return new self($invoice->public_id, 'consumed');
            }
        }
        $invoice = reset($invoices);

        return $invoice instanceof ClientInvoice
            ? new self($invoice->public_id, 'reserved')
            : new self(null, 'unallocated');
    }
}
