<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingPeriodCollisionResolver;
use App\Services\Billing\ScheduleGenerationPreflight;

/**
 * Which of the resolver's refusals fired, as a value rather than as prose.
 *
 * The message an operator reads has to name the invoice and say what to do
 * about it, so it is a sentence. Anything that wants to *count* refusals needs
 * the category without the identifiers - {@see ScheduleGenerationPreflight}
 * reports how many of each fired across a whole database, and printing counts
 * only is what makes that output safe to paste into a public issue.
 *
 * Deriving the category by matching on the message would put an operator-facing
 * string into a machine contract, where rewording it for clarity silently
 * changes the numbers. So the reason travels with the claim.
 *
 * The cases are exactly the refusal sites in
 * {@see BillingPeriodCollisionResolver}, in the order they are evaluated.
 */
enum PeriodRefusalReason: string
{
    /** Names a billing schedule that does not resolve in the invoice's own tenant. */
    case DanglingSchedule = 'dangling_schedule_link';

    /** Names an agreement that does not resolve in the invoice's own tenant. */
    case DanglingAgreement = 'dangling_agreement_link';

    /** Names a schedule and an agreement that do not belong together. */
    case ContradictoryLineage = 'contradictory_lineage';

    /** Names neither owner, on a client where something else could own it. */
    case Unattributed = 'unattributed_and_contested';

    /** Carries a status no case matches, so it cannot be shown to have charged nobody. */
    case UnknownStatus = 'unknown_status';

    /** This schedule's, but missing a boundary, so no comparison can place it. */
    case IncompletePeriod = 'incomplete_period';

    /** This schedule's, and covering part of the period without matching it. */
    case PartialOverlap = 'partial_overlap';

    /**
     * Not the resolver's, but the schedule's own: its cadence is a value this
     * application cannot turn into a span.
     *
     * `BillingPeriod::beginningAt()` throws on one rather than guessing a month
     * count, so such a schedule halts before the resolver is ever consulted. It
     * belongs here because the question a preflight answers is "what stops this
     * schedule", and this stops it.
     */
    case UnreadableCadence = 'unreadable_cadence';

    /**
     * A one-line description for an operator, with no identifiers in it.
     */
    public function summary(): string
    {
        return match ($this) {
            self::DanglingSchedule => 'naming a billing schedule that does not resolve',
            self::DanglingAgreement => 'naming an agreement that does not resolve',
            self::ContradictoryLineage => 'whose schedule and agreement disagree',
            self::Unattributed => 'unattributed where another owner could claim them',
            self::UnknownStatus => 'carrying a status this application cannot read',
            self::IncompletePeriod => 'owned but missing a service period boundary',
            self::PartialOverlap => 'overlapping the period without matching it',
            self::UnreadableCadence => 'on a schedule whose cadence this application cannot read',
        };
    }
}
