<?php

namespace App\Support\Packages\Migration;

/**
 * Why a subscriber due to move has not moved yet (MIG-3). Recorded on its
 * target, which stays scheduled: each run tries again, and Needs you shows it
 * until an owner or admin settles it. Codes are part of the agent API.
 */
enum MigrationHold: string
{
    /** An issued invoice already sells a period from the effective date on. */
    case PeriodInvoiced = 'period_invoiced';
    /** The client asked to end the agreement before the effective date, and nobody has decided. */
    case CancellationPending = 'cancellation_pending';
    /** A draft for a period from the effective date on could not be discarded. */
    case DraftKept = 'draft_kept';
    /** The last period on the old terms could not be invoiced on the agreement that ends. */
    case SettlementRefused = 'settlement_refused';
    /** Something unexpected; the error was reported. */
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::PeriodInvoiced => 'An issued invoice already bills a period from the effective date on. Void it to let the move apply, or cancel the move.',
            self::CancellationPending => 'The client asked to end the agreement before the effective date. Approve or decline the request; the move applies after a decline.',
            self::DraftKept => 'A draft invoice for a period from the effective date on could not be discarded. Discard it or let it be issued, then the move applies on the next run.',
            self::SettlementRefused => 'The last period on the current terms could not be invoiced, so the move waits rather than leave that work to the new terms. Resolve what the billing engine reports, then the move applies on the next run.',
            self::Failed => 'The move could not be applied. It is tried again every hour.',
        };
    }
}
