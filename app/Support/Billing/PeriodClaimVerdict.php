<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingPeriodCollisionResolver;

/**
 * What a billing schedule should do about the period it is about to bill.
 *
 * Four answers rather than two, and the two extra ones are the point. A guard
 * that can only say "billed" or "not billed" has to pick one of them for a row
 * it cannot attribute, and both picks are wrong in a way nothing notices:
 * answering "billed" advances `next_run_on` past a period nothing charged,
 * answering "not billed" issues a second invoice for a period already covered.
 * #219 and #224 are each one half of that.
 *
 * So an undecidable row resolves to neither, and neither does a row that has
 * reserved the period without charging for it. See
 * {@see BillingPeriodCollisionResolver} for which shapes are which and why.
 */
enum PeriodClaimVerdict
{
    /**
     * Nothing covers this period. Generate it.
     */
    case Clear;

    /**
     * A draft covers exactly this period, and it has charged nobody yet.
     *
     * The distinction this case exists to draw is between a period that has
     * been *billed* and one that has merely been *claimed*. A draft is a
     * proposal: `InvoiceStatus` says in as many words that a draft has charged
     * nobody. Returning `AlreadyBilled` for one - which is what an earlier
     * revision did - advanced `next_run_on` past a period no money had been
     * asked for, and there was no automatic way back:
     * `InvoiceLifecycleService::discardDraft()` turns the draft into a *void*
     * invoice while keeping its period, so even rewinding the cursor produced
     * an exact void, which is read as a deliberate waiver. The period was
     * silently never billed.
     *
     * Creating a second invoice is equally wrong - two invoices for one period
     * is the defect this whole class of guard exists to prevent - so the
     * schedule does neither and says so. Issue the draft and the next run
     * advances normally; void it deliberately and the waiver is honoured.
     *
     * That advice is only sound when the draft is the *lone* claim on the
     * period, so this verdict is reached only then. A draft alongside another
     * invoice covering the period exactly refuses instead - see
     * {@see PeriodRefusalReason::ConflictingExactClaims} - because there the
     * same sentence would be telling an operator to bill a period already
     * billed, or to undo a waiver.
     */
    case PendingDraft;

    /**
     * An invoice this schedule owns already covers exactly this period, and it
     * is not a draft.
     *
     * Issued, partially paid and paid have all asked the client for money. A
     * voided invoice answers this too, deliberately: voiding a cadence invoice
     * is the documented way to waive its own period, and regenerating it would
     * collide with the unique index anyway - see
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
