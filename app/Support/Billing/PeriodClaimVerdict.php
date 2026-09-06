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
     * A refusal is always *safe* - the transaction rolls back and
     * `next_run_on` does not move, so no period is billed twice and none is
     * skipped - but it is not always cheaply recoverable, and the message must
     * not pretend otherwise. Whether the run can simply be repeated depends on
     * the row it names: a draft can be discarded and an issued invoice voided,
     * but `InvoiceLifecycleService::void()` throws once `paid_amount > 0`, and
     * `updateDraft()` rewrites no period or lineage column at any status. A
     * refusal naming a paid invoice therefore halts that schedule on every run
     * until a financial correction is made outside this code path.
     *
     * That is still the right answer - the alternatives are charging the
     * client twice or silently skipping a period nobody billed - but it is a
     * halt, so every refusal message says what would actually clear it rather
     * than offering a repair the application refuses to perform. See
     * {@see BillingPeriodCollisionResolver} `remedy()`.
     */
    case Refused;
}
