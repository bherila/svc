<?php

namespace App\Support\Billing;

use App\Console\Commands\Billing\GenerateCadenceDraftsCommand;
use App\Models\ClientInvoice;
use App\Models\ClientInvoiceLine;

/**
 * Does a generated cadence draft ask the client for nothing at all?
 *
 * A $0 retainer with no priced line, no billable time and no milestone is a
 * draft an operator could only discard, so {@see GenerateCadenceDraftsCommand}
 * rolls it back instead of creating it. Billable time at a $0 rate still
 * counts as something billed; non-billable time alone does not.
 *
 * Reads only what the caller loaded: `lines`, each line's `timeEntries`
 * constrained to billable entries, and each line's `tasks`.
 */
final class EmptyCadenceDraft
{
    public static function billsNothing(ClientInvoice $draft): bool
    {
        return $draft->total_amount === 0 && $draft->lines->every(
            static fn (ClientInvoiceLine $line): bool => $line->total_amount === 0 && $line->timeEntries->isEmpty() && $line->tasks->isEmpty(),
        );
    }
}
