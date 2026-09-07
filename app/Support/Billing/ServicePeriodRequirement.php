<?php

namespace App\Support\Billing;

/**
 * Whether an invoice must state a complete service period before it may be
 * issued - and whether it may be issued at all.
 *
 * This exists so the question is asked in one place. `service_period_start`
 * and `service_period_end` are nullable for every kind, and the answer to
 * "may this row be null here" differs by kind *and* by whether the row names a
 * billing schedule - so scattering `invoice_kind === 'ad_hoc'` comparisons
 * across the guards that care is how the two halves drift apart.
 *
 * It reads the **raw** column rather than {@see ClientInvoice::invoiceKindValue()},
 * which collapses a null kind and an unrecognised one into `cadence_period`.
 * That default is right where it is used - a migrated row with no kind is an
 * ordinary full-cycle invoice - and wrong here, because the two are not the
 * same fact. One is a legacy shape the whole application agrees about; the
 * other is a value nobody has classified, and the application does *not* agree
 * about it. See {@see self::UnsupportedKind}.
 */
enum ServicePeriodRequirement
{
    /**
     * The row is a claim about a span, so both boundaries must be present.
     *
     * A null kind lands here: it reads as `cadence_period` everywhere else, and
     * production holds no invoice with a null kind at all.
     *
     * So does **any** row naming a billing schedule, whatever kind it carries.
     * That is not a tightening for its own sake - it mirrors
     * `BillingPeriodCollisionResolver`, whose kind exemption is reached only
     * when `client_billing_schedule_id` is null, because "a row naming this
     * schedule is this schedule's whatever kind it carries". A schedule-linked
     * ad-hoc row with no period is read by that guard as unbounded, established
     * as the schedule's, and refused - so issuing one manufactures a live row
     * that halts the schedule's next run.
     */
    case Required;

    /**
     * The row bills a thing rather than a span, and no period guard reads it.
     *
     * Only an **unlinked** `ad_hoc` invoice. `cycleGuardExclusions()` keeps one
     * from blocking cadence generation and `matchedByCycle()` keeps it out of
     * the cycle lookups, but both exemptions are conditional on the row naming
     * no schedule. Linked, it is not exempt anywhere, so it is not exempt here.
     */
    case Exempt;

    /**
     * A non-null kind that {@see InvoiceKind} does not recognise. It may not be
     * issued, complete period or not.
     *
     * An earlier revision of this treated an unrecognised kind as merely
     * "period required", on the reading that this invariant is about the span
     * and not about kind hygiene. That reading is wrong, because the
     * application already gives such a row two incompatible identities:
     *
     * - {@see ClientInvoice::invoiceKindValue()} answers `cadence_period`, so
     *   the model, the UI and every activity payload call it a cadence invoice;
     * - the raw-column guards do not. `ClientInvoicingService::cycleAlreadySold()`
     *   matches `invoice_kind IS NULL OR invoice_kind = 'cadence_period'`, and
     *   an unrecognised value satisfies neither.
     *
     * So an issued row of an unknown kind is a cadence invoice to everything
     * that reads it through the model, and invisible to the guard that stops a
     * later correction selling the same retainer and recurring items a second
     * time. The service-period overlap guard will not catch that either: the
     * cycle guard exists precisely because a correction covering part of a
     * month derives the same retainer cycle without overlapping the earlier
     * invoice's service period.
     *
     * Refusing costs nothing to adopt - production holds no invoice of an
     * unrecognised kind - and it is the fail-closed reading recorded on #251.
     */
    case UnsupportedKind;

    /**
     * Resolve the requirement from the raw `invoice_kind` column and whether
     * the row names a billing schedule.
     */
    public static function for(?string $rawKind, bool $namesSchedule): self
    {
        if ($rawKind === null) {
            return self::Required;
        }

        $kind = InvoiceKind::tryFrom($rawKind);

        if ($kind === null) {
            return self::UnsupportedKind;
        }

        // Ownership before kind, exactly as the collision resolver orders it.
        if ($namesSchedule) {
            return self::Required;
        }

        return $kind->requiresCompleteServicePeriod() ? self::Required : self::Exempt;
    }

    /** Whether both service-period boundaries must be present. */
    public function requiresBothBoundaries(): bool
    {
        return $this === self::Required;
    }
}
