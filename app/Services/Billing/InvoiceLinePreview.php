<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Support\Billing\LinkedTimeSpan;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every line of a set of invoices with the time each links, in two queries.
 *
 * Read for the rehearsal's `--show`: one query for the lines, one for the
 * time they link - however many invoices and lines there are. Line, pivot and
 * entry must all be the workspace's own.
 */
final class InvoiceLinePreview
{
    /**
     * @param  Collection<int, ClientInvoice>  $invoices
     * @return array<int, list<array{line: ClientInvoiceLine, time: LinkedTimeSpan}>> keyed by invoice id
     */
    public function forInvoices(int $workspaceId, Collection $invoices): array
    {
        $lines = ClientInvoiceLine::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('client_invoice_id', $invoices->pluck('id'))
            ->orderBy('client_invoice_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $linked = DB::table('client_invoice_line_time_entries')
            ->join('client_time_entries', 'client_time_entries.id', '=', 'client_invoice_line_time_entries.client_time_entry_id')
            ->where('client_invoice_line_time_entries.workspace_id', $workspaceId)
            ->where('client_time_entries.workspace_id', $workspaceId)
            ->whereNull('client_time_entries.deleted_at')
            ->whereIn('client_invoice_line_time_entries.client_invoice_line_id', $lines->pluck('id'))
            ->get([
                'client_invoice_line_time_entries.client_invoice_line_id as line_id',
                'client_time_entries.minutes',
                'client_time_entries.worked_on',
                'client_time_entries.is_deferred',
            ])
            ->groupBy(static fn (object $row): int => (int) $row->line_id);

        $preview = [];
        foreach ($invoices as $invoice) {
            $preview[(int) $invoice->id] = [];
        }
        foreach ($lines as $line) {
            $rows = $linked->get((int) $line->id, collect())->map(static fn (object $row): array => [
                'minutes' => (int) $row->minutes,
                'worked_on' => Carbon::parse((string) $row->worked_on)->toDateString(),
                'is_deferred' => (bool) $row->is_deferred,
            ]);
            $preview[(int) $line->client_invoice_id][] = ['line' => $line, 'time' => LinkedTimeSpan::of($rows)];
        }

        return $preview;
    }
}
