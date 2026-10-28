<?php

namespace App\Support\AgentApi;

use App\Models\ClientInvoice;
use App\Models\ClientTimeEntry;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Database-free allocation classification after a tenant-scoped eager load. */
final readonly class TimeEntryAllocation
{
    public function __construct(public ?string $invoiceId, public string $state) {}

    /**
     * @return array<string, \Closure>
     *
     * @infection-ignore-all Tenant-scoped Eloquent boundary, covered by AgentTimeParityTest's canonical reserve/release and malformed allocation cases and DraftInvoiceTimeRegenerationTest's partially loaded relations. The mutation lane runs database-free unit tests; fromEntry() remains fully mutated.
     */
    public static function relations(int $workspaceId): array
    {
        return ['invoiceLines' => fn ($lines) => $lines
            ->where('client_invoice_lines.workspace_id', $workspaceId)
            ->wherePivot('workspace_id', $workspaceId),
            'invoiceLines.invoice' => fn ($invoice) => $invoice->where('workspace_id', $workspaceId),
        ];
    }

    /**
     * Load missing allocation facts before producing a version. A caller may
     * already have the line collection without its nested invoice relations.
     *
     * @infection-ignore-all Database-loading boundary covered by AgentTimeParityTest and DraftInvoiceTimeRegenerationTest; the pure classifier and composite token remain covered by database-free mutation tests.
     */
    public static function forVersion(ClientTimeEntry $entry): self
    {
        $entry->loadMissing(self::relations($entry->workspace_id));

        return self::fromEntry($entry);
    }

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
