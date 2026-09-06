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
 * {@see BillingPeriodCollisionResolver}, in the order they are evaluated, and
 * nothing else. A schedule can also halt for reasons that arise before the
 * resolver is consulted at all - see {@see ScheduleDefect} - and those are
 * counted separately rather than borrowed into this vocabulary, so that
 * "refusals" in a report means refusals.
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
     * Two or more invoices each cover exactly this period.
     *
     * The only case here that is a property of the *candidate set* rather than
     * of one row, which is why it is decided after every candidate has been
     * classified rather than at a refusal site. See
     * {@see BillingPeriodCollisionResolver::resolve()} for why a lone pending
     * draft and a pending draft alongside an already-billed row need opposite
     * advice.
     */
    case ConflictingExactClaims = 'conflicting_exact_claims';

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
            self::ConflictingExactClaims => 'duplicated by another invoice covering exactly the same period',
        };
    }
}
