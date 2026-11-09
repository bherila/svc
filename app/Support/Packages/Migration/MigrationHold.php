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
    /**
     * The last period on the old terms has something to invoice (work,
     * expenses) on the invoice that would also open the new agreement's first
     * period, which the billing engine then refuses.
     */
    case LastPeriodToSettle = 'last_period_to_settle';
    /** The last period on the old terms could not be invoiced on the agreement that ends. */
    case SettlementRefused = 'settlement_refused';
    /**
     * The agreement's billing cadence was changed after the move was
     * scheduled and no longer matches the new version's (a move keeps the
     * cadence, so every period is billed once).
     */
    case CadenceChanged = 'cadence_changed';
    /**
     * The first period on the new terms ended before the move could apply.
     * Started now, the new agreement would begin in the past with that
     * period never invoiced, so the subscriber stays on the current terms,
     * which bill every period, until an owner cancels this move and
     * schedules another.
     */
    case EffectiveDatePassed = 'effective_date_passed';
    /**
     * The agreement still owes a period an earlier move held back from
     * automatic invoicing and never applied to, and it is not invoiced yet
     * (its late invoice was refused). Ending the agreement now would leave
     * that period to no agreement at all.
     */
    case OwedPeriodUnbilled = 'owed_period_unbilled';
    /** The agreement now has a recurring item on another cadence than its billing (an annual licence on a monthly service), whose billing anchor a new agreement would restart. */
    case OffCycleComponent = 'off_cycle_component';
    /** The agreement's recurring items no longer follow one charge schedule, which the new agreement would continue. */
    case AmbiguousRecurrence = 'ambiguous_recurrence';
    /** The agreement was linked to a Square series after the move was scheduled; SVC never reshapes what Square collects. */
    case SquareCollected = 'square_collected';
    /** Something unexpected; the error was reported. */
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::PeriodInvoiced => 'An issued invoice already bills a period from the effective date on. Void it to let the move apply, or cancel the move.',
            self::CancellationPending => 'The client asked to end the agreement before the effective date. Approve or decline the request; the move applies after a decline.',
            self::DraftKept => 'A draft invoice for a period from the effective date on could not be discarded. Discard it or let it be issued, then the move applies on the next run.',
            self::LastPeriodToSettle => 'The last period on the current terms still has work or expenses to invoice, on the invoice that would also open the new terms\' first period. Invoice them separately (an ad hoc invoice), or cancel the move for this subscriber; then the move applies on the next run.',
            self::SettlementRefused => 'The last period on the current terms could not be invoiced, so the move waits rather than leave that work to the new terms. Resolve what the billing engine reports, then the move applies on the next run.',
            self::CadenceChanged => 'The agreement now bills on another cadence than the new version, so moving it would leave a period unbilled. Change its cadence back, or cancel the move for this subscriber.',
            self::EffectiveDatePassed => 'The first billing period on the new terms ended before the move could apply, so the client stays on the current terms, which keep invoicing every period, including that one. Cancel this move for the client and schedule a new one.',
            self::OwedPeriodUnbilled => 'An earlier billing period that waited for a move is still not invoiced (its invoice was refused; Needs you shows why). Resolve that so it is invoiced on the current terms; the move then applies on the next run.',
            self::OffCycleComponent => 'The agreement now has a recurring charge on another cadence than its billing (an annual licence on a monthly service, or a one-time charge), which a new agreement would charge again from its own start. Remove it, or cancel the move for this subscriber.',
            self::AmbiguousRecurrence => 'The agreement\'s recurring charges no longer follow one schedule (an item was added or re-anchored by hand, or starts later), so the new agreement could not continue it without charging a period again or skipping one. Align the items, or cancel the move for this subscriber.',
            self::SquareCollected => 'Square now collects this agreement\'s invoices, and SVC never reshapes what Square collects. Cancel the move for this subscriber.',
            self::Failed => 'The move could not be applied. It is tried again every hour.',
        };
    }
}
