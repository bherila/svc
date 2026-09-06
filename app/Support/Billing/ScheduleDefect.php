<?php

namespace App\Support\Billing;

use App\Services\Billing\BillingScheduleService;
use App\Services\Billing\ScheduleGenerationPreflight;

/**
 * Something wrong with the schedule itself, rather than with an invoice.
 *
 * {@see BillingScheduleService::generateDue()} reads two things off the
 * schedule before it can classify anything - the cadence, to cut a period out
 * of the calendar, and the line template, to have something to bill - and
 * throws on either rather than guessing. Both halts happen before
 * {@see BillingPeriodCollisionResolver} is consulted, and neither has an
 * invoice to name.
 *
 * Kept apart from {@see PeriodRefusalReason} deliberately. An earlier revision
 * folded an unreadable cadence in there and counted it as a refusal, which made
 * a report say a refusal had fired when no `PeriodClaim::refused()` had ever
 * been constructed - and put a case into a type whose documented invariant is
 * that its cases are the resolver's refusal sites. Two questions, two
 * vocabularies; {@see ScheduleGenerationPreflight} counts both and reports them
 * under their own names.
 */
enum ScheduleDefect: string
{
    /**
     * The cadence is a value this application cannot turn into a span.
     *
     * `BillingPeriod::beginningAt()` throws on one rather than guessing a month
     * count, so the schedule halts at its first due period.
     */
    case UnreadableCadence = 'unreadable_cadence';

    /**
     * The line template is not a list of objects, so there is nothing to bill.
     *
     * `generateDue()` normalises it *before* its loop, so this halts a schedule
     * whether or not it has a period due - which is why the preflight reads it
     * for every active schedule rather than only for the due ones.
     */
    case UnreadableLineTemplate = 'unreadable_line_template';

    /**
     * A one-line description for an operator, with no identifiers in it.
     */
    public function summary(): string
    {
        return match ($this) {
            self::UnreadableCadence => 'whose cadence this application cannot read',
            self::UnreadableLineTemplate => 'whose line template is not a list of billable lines',
        };
    }
}
