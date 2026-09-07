<?php

namespace App\Support\Billing;

/**
 * Whether an invoice of a given kind must state a complete service period
 * before it may be issued.
 *
 * This exists so the question is asked in one place. `service_period_start`
 * and `service_period_end` are nullable for every kind, and the answer to
 * "may this row be null here" differs by kind - so scattering
 * `invoice_kind === 'ad_hoc'` comparisons across the guards that care is how
 * the two halves drift apart.
 *
 * It reads the **raw** column rather than {@see ClientInvoice::invoiceKindValue()},
 * which collapses a null kind and an unrecognised one into `cadence_period`.
 * That default is right where it is used - a migrated row with no kind is an
 * ordinary full-cycle invoice - but it is wrong here, because it would answer
 * this question for a value nobody has classified. The two cases are separated
 * below, and only one of them is a decision.
 */
enum ServicePeriodRequirement
{
    /**
     * The kind is a claim about a span, so both boundaries must be present.
     *
     * A null kind lands here too. It reads as `cadence_period` everywhere else
     * in the application, and there is no production row that argues otherwise:
     * the census taken for #251 found no invoice with a null kind at all.
     */
    case Required;

    /**
     * The kind bills a thing rather than a span.
     *
     * Only `ad_hoc`. No period guard reads it - `cycleGuardExclusions()` keeps
     * it from blocking cadence generation, and `matchedByCycle()` keeps it out
     * of the cycle lookups - so a null boundary on one of these is invisible to
     * the guards rather than dangerous to them.
     */
    case Exempt;

    /**
     * A non-null kind that {@see InvoiceKind} does not recognise.
     *
     * Deliberately not folded into `Required`, though it is treated as such
     * below. The distinction is that `Required` is a decision taken about a
     * kind we know, and this is the absence of one: a value that reached the
     * column from an import or a hand-edit and that nothing here has
     * classified. Naming it keeps a future reader from mistaking the
     * conservative fallback for a considered answer.
     *
     * Requiring the period rather than refusing the issue outright is the
     * narrow reading on purpose. This invariant is about the period, not about
     * kind hygiene, and an unrecognised kind that does state a complete span
     * breaks nothing this guard exists to prevent.
     */
    case Undecidable;

    /**
     * Resolve the requirement from the raw `invoice_kind` column.
     */
    public static function for(?string $rawKind): self
    {
        if ($rawKind === null) {
            return self::Required;
        }

        $kind = InvoiceKind::tryFrom($rawKind);

        if ($kind === null) {
            return self::Undecidable;
        }

        return $kind->requiresCompleteServicePeriod() ? self::Required : self::Exempt;
    }

    /** Whether both service-period boundaries must be present. */
    public function requiresBothBoundaries(): bool
    {
        return $this !== self::Exempt;
    }
}
