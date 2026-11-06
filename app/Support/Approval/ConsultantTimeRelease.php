<?php

declare(strict_types=1);

namespace App\Support\Approval;

use App\Support\Billing\SubcontractorBillingMode;

/**
 * Which logged time a draft invoice pulls in without anyone approving it
 * (UX overhaul section 7b, "Invoiced").
 *
 * The consultant's own time is billed because it was worked, not because
 * somebody signed it off; the client approves afterwards. So generating a
 * draft takes every unbilled, billable hour the consultant logged up to today,
 * and the people who approve are the client's.
 *
 * Only consultant time. A subcontractor's hours - whatever their billing mode
 * - wait for the person who pays them, so a stored mode or cost keeps an entry
 * out. Pure and database-free; the caller supplies facts, not rows.
 */
final class ConsultantTimeRelease
{
    /**
     * @param  string  $status  the stored status
     * @param  string  $workedOn  `Y-m-d`
     * @param  string  $today  `Y-m-d`, the workspace's calendar
     * @param  bool  $onAnInvoiceLine  linked to any invoice line already
     */
    public static function releases(string $status, bool $billable, ?string $billingMode, bool $hasSubcontractorCost, string $workedOn, string $today, bool $onAnInvoiceLine): bool
    {
        return $status === 'draft'
            && $billable
            && $billingMode === null
            && ! $hasSubcontractorCost
            && ! $onAnInvoiceLine
            && $workedOn <= $today;
    }

    /**
     * May the client approve time stored with this subcontractor mode?
     *
     * Direct time is tracked for the sub's own billing and never appears on
     * this vendor's invoice, so there is nothing here for the client to
     * approve. Written as a `match` over every case, no default: a new mode
     * has to be decided here before it reaches a client.
     */
    public static function clientApproves(?SubcontractorBillingMode $mode): bool
    {
        if ($mode === null) {
            return true;
        }

        return match ($mode) {
            SubcontractorBillingMode::FlatHourly => true,
            SubcontractorBillingMode::Retainer => true,
            SubcontractorBillingMode::Direct => false,
        };
    }
}
