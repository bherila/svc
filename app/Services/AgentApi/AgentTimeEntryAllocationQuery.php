<?php

namespace App\Services\AgentApi;

use App\Models\ClientInvoiceLine;
use App\Models\ClientTimeEntry;
use Illuminate\Database\Eloquent\Builder;

/** The allocation predicates shared by the summary and the time-entry list. */
final class AgentTimeEntryAllocationQuery
{
    /** @param Builder<ClientTimeEntry> $query
     * @return Builder<ClientTimeEntry> */
    public function invoiceReady(Builder $query): Builder
    {
        return $this->filter($query->pricedForInvoicing()->where('is_billable', true)->where('is_deferred', false), 'unallocated');
    }

    /** @param Builder<ClientTimeEntry> $query
     * @return Builder<ClientTimeEntry> */
    public function filter(Builder $query, string $state): Builder
    {
        return match ($state) {
            'unallocated' => $query->whereDoesntHave('invoiceLines', self::owned(...)),
            'reserved' => $query
                ->whereHas('invoiceLines', self::reserved(...))
                ->whereDoesntHave('invoiceLines', self::consumed(...)),
            'consumed' => $query->whereHas('invoiceLines', self::consumed(...)),
            default => throw new \InvalidArgumentException('Unknown time-entry allocation state.'),
        };
    }

    /** @param Builder<ClientInvoiceLine> $lines
     * @return Builder<ClientInvoiceLine> */
    private static function reserved(Builder $lines): Builder
    {
        return self::owned($lines)->whereHas('invoice', fn (Builder $invoice): Builder => $invoice->where('status', 'draft'));
    }

    /** @param Builder<ClientInvoiceLine> $lines
     * @return Builder<ClientInvoiceLine> */
    private static function consumed(Builder $lines): Builder
    {
        return self::owned($lines)->whereHas('invoice', fn (Builder $invoice): Builder => $invoice->where('status', '!=', 'draft'));
    }

    /** @param Builder<ClientInvoiceLine> $lines
     * @return Builder<ClientInvoiceLine> */
    private static function owned(Builder $lines): Builder
    {
        return $lines
            ->whereColumn('client_invoice_lines.workspace_id', 'client_time_entries.workspace_id')
            ->whereColumn('client_invoice_line_time_entries.workspace_id', 'client_time_entries.workspace_id')
            ->whereHas('invoice', fn (Builder $invoice): Builder => $invoice
                ->whereColumn('client_invoices.workspace_id', 'client_time_entries.workspace_id')
                ->whereColumn('client_invoices.client_company_id', 'client_time_entries.client_company_id'));
    }
}
