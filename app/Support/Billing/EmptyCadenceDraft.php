<?php

namespace App\Support\Billing;

use App\Console\Commands\Billing\GenerateCadenceDraftsCommand;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;
use App\Models\Workspace;

/**
 * Does a generated cadence draft ask the client for nothing at all?
 *
 * A $0 retainer with no priced line, no billable time and no milestone is a
 * draft an operator could only discard, so {@see GenerateCadenceDraftsCommand}
 * rolls it back instead of creating it. Billable time at a $0 rate still
 * counts as something billed; non-billable time alone does not.
 *
 * {@see billsNothing()} reads only what {@see load()} loaded - the draft's
 * lines, their billable time and their milestones, each held to the draft's
 * workspace - so the classification itself needs no database.
 */
final class EmptyCadenceDraft
{
    /**
     * The draft with exactly the relations {@see billsNothing()} reads. Every
     * relation is held to the workspace on its own: the invoice's scope does
     * not reach a line, a time entry, the pivot between them, or a task, and a
     * malformed reference into another workspace must read as absent.
     */
    public static function load(Workspace $workspace, ClientInvoice $draft): ClientInvoice
    {
        return ClientInvoice::query()->where('workspace_id', $workspace->id)->whereKey($draft->id)->with([
            'lines' => fn ($lines) => $lines->where('workspace_id', $workspace->id),
            'lines.timeEntries' => fn ($entries) => $entries->where('client_time_entries.workspace_id', $workspace->id)->wherePivot('workspace_id', $workspace->id)
                ->where('client_time_entries.is_billable', true)->select('client_time_entries.id'),
            'lines.tasks' => fn ($tasks) => $tasks->where('workspace_id', $workspace->id)->select(['id', 'client_invoice_line_id']),
        ])->firstOrFail();
    }

    public static function billsNothing(ClientInvoice $draft): bool
    {
        return $draft->total_amount === 0 && $draft->lines->every(
            static fn (ClientInvoiceLine $line): bool => $line->total_amount === 0 && $line->timeEntries->isEmpty() && $line->tasks->isEmpty(),
        );
    }
}
