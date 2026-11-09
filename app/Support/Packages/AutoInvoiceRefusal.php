<?php

namespace App\Support\Packages;

/**
 * Why automatic recurring invoicing could not invoice a package agreement's
 * billing period (INV-1, INV-3). Codes are part of the agent API.
 */
enum AutoInvoiceRefusal: string
{
    /** INV-3, X-2: the agreement carries the billed-in-Square marker; SVC issues nothing Square collects. */
    case CollectedInSquare = 'collected_in_square';

    /** The cadence engine refused the period - most often another invoice already covers its work period. */
    case PeriodUnbillable = 'period_unbillable';

    /** The invoice was generated but issuing it was refused. */
    case IssueRefused = 'issue_refused';

    /** Something unexpected failed; the details are in the application log. */
    case Failed = 'failed';

    public function message(): string
    {
        return match ($this) {
            self::CollectedInSquare => 'Square collects this agreement, so SVC does not invoice it.',
            self::PeriodUnbillable => 'The billing engine refused this period.',
            self::IssueRefused => 'The invoice could not be issued.',
            self::Failed => 'Automatic invoicing failed unexpectedly.',
        };
    }
}
