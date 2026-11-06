<?php

namespace App\Services\Billing;

use App\Models\ClientInvoice;
use App\Support\Billing\InvoiceDeferredWorkSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of an invoice's stored deferred-work snapshot (#601, #610).
 *
 * The snapshot is JSON on the invoice, and the projects it discloses are
 * indexed in `client_invoice_deferred_projects` so the portal grant query can
 * decide visibility in SQL. Both are written here, together, so the index can
 * never describe a snapshot the invoice no longer carries. Every write names
 * the invoice's workspace.
 */
final class InvoiceDeferredWorkStatements
{
    public const string PROJECTS_TABLE = 'client_invoice_deferred_projects';

    public function write(ClientInvoice $invoice, ?InvoiceDeferredWorkSnapshot $snapshot): void
    {
        DB::transaction(function () use ($invoice, $snapshot): void {
            $invoice->forceFill(['deferred_work_statement' => $snapshot?->toArray()])->save();

            DB::table(self::PROJECTS_TABLE)
                ->where('workspace_id', $invoice->workspace_id)
                ->where('client_invoice_id', $invoice->id)
                ->delete();

            $rows = array_map(static fn (int $project): array => [
                'workspace_id' => $invoice->workspace_id,
                'client_invoice_id' => $invoice->id,
                'client_project_id' => $project,
            ], $snapshot?->projectIds() ?? []);
            if ($rows !== []) {
                DB::table(self::PROJECTS_TABLE)->insert($rows);
            }
        });
    }
}
