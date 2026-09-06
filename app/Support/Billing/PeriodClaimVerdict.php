<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingPeriodCollisionResolver;

/**
 * What a billing schedule should do about the period it is about to bill.
 *
 * Three answers rather than two, and the third is the point. A guard that can
 * only say "billed" or "not billed" has to pick one of them for a row it cannot
 * attribute, and both picks are wrong in a way nothing notices: answering
 * "billed" advances `next_run_on` past a period nothing charged, answering "not
 * billed" issues a second invoice for a period already covered. #219 and #224
 * are each one half of that.
 *
 * So an undecidable row resolves to neither. See
 * {@see BillingPeriodCollisionResolver} for which shapes are undecidable and
 * why each one is.
 */
enum PeriodClaimVerdict
{
    /**
     * Nothing covers this period. Generate it.
     */
    case Clear;

    /**
     * An invoice this schedule owns already covers exactly this period.
     *
     * Any status. A voided invoice still answers this, deliberately - see
     * {@see BillingPeriodCollisionResolver::resolve()}.
     */
    case AlreadyBilled;

    /**
     * The period cannot be decided here, and nothing is created or advanced.
     *
     * A refusal is recoverable by definition: the transaction rolls back,
     * `next_run_on` does not move, and the run can simply be repeated once a
     * person has attributed or repaired the row the message names.
     */
    case Refused;
}
