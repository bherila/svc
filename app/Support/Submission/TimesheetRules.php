<?php

declare(strict_types=1);

namespace App\Support\Submission;

use App\Support\Approval\ClientApprovalSource;
use App\Support\Approval\ClientApprovalVerdict;

/**
 * What the approved timesheet holds (docs/ux-overhaul.md section 7e).
 *
 * The timesheet is a record of what a person said: hours that a reviewer or
 * the client's own contact approved. An approval implied by paying an invoice
 * names nobody and holds only while that invoice stays paid, so it is not a
 * line on a statement of who approved what. A pending or disputed row is not
 * approved at all. Written as exhaustive `match` expressions, so a new verdict
 * has to be decided here before it reaches a timesheet.
 */
final class TimesheetRules
{
    public static function includes(ClientApprovalVerdict $verdict): bool
    {
        return match ($verdict) {
            ClientApprovalVerdict::Approved => true,
            ClientApprovalVerdict::ApprovedByPayment => false,
            ClientApprovalVerdict::Pending => false,
            ClientApprovalVerdict::Disputed => false,
        };
    }

    /** How the approval was given, in words, for the line that names who gave it. */
    public static function via(?string $source): string
    {
        return match (ClientApprovalSource::tryFrom((string) $source)) {
            ClientApprovalSource::Reviewer => 'Reviewer',
            ClientApprovalSource::Portal => 'Client',
            ClientApprovalSource::Payment => 'Payment',
            null => 'Unknown',
        };
    }
}
