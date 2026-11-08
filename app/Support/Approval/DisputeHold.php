<?php

declare(strict_types=1);

namespace App\Support\Approval;

use App\Models\ClientTimeEntry;

/**
 * Is a row held out of billing by a client's dispute (docs/ux-overhaul.md 7b″)?
 *
 * A dispute is a client's statement that a row should not be charged as it
 * stands, so nothing bills it until the provider settles the dispute: no draft
 * takes it, and a draft that already carries it is rebuilt without it. Billing
 * is still not gated on approval - an undecided row bills on schedule - only a
 * dispute stops it.
 *
 * A row an issued invoice already charges is not held: that charge stands until
 * the correction flow changes it, and the retainer ledger keeps counting it.
 * Held is never stored; it is read from the dispute and the invoice each time,
 * so settling the dispute, or a void that returns the row, decides it again.
 *
 * Held is not deferred. `is_deferred` is retainer work set aside for the
 * allocator to bill later on its own, which a disputed row must not be.
 *
 * Pure and database-free. The query counterpart is
 * {@see ClientTimeEntry::scopeNotHeldForDispute()}.
 */
final class DisputeHold
{
    /** The refusal code an issue of a draft still charging a held row carries. */
    public const string ISSUE_REFUSAL_CODE = 'disputed_on_draft';

    /**
     * @param  bool  $disputed  `client_disputed_at` is set
     * @param  bool  $onChargedInvoice  an issued, partially paid, paid or disputed invoice carries it
     */
    public static function holds(bool $disputed, bool $onChargedInvoice): bool
    {
        return $disputed && ! $onChargedInvoice;
    }
}
