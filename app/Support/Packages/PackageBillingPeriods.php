<?php

namespace App\Support\Packages;

use App\Models\ClientAgreement;
use App\Services\Billing\BillingCycleResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * When a package agreement's next billing period begins: the day its next
 * cadence invoice is dated, and for one that issues automatically the day
 * `svc:packages:issue-invoices` issues it (INV-1).
 *
 * Pure: reads only the agreement given and the day given, through the same
 * cycle arithmetic the engine bills by ({@see BillingCycleResolver}).
 */
final class PackageBillingPeriods
{
    /**
     * The first day of the next billing period, or null when there is none to
     * come: the service is not running, the agreement does not bill on a
     * recurring cadence, or it ends before then.
     */
    public static function nextPeriodStart(EnrollmentStatus $status, ClientAgreement $agreement, CarbonImmutable $today): ?string
    {
        $running = match ($status) {
            EnrollmentStatus::Active => true,
            EnrollmentStatus::Offered, EnrollmentStatus::AwaitingPayment, EnrollmentStatus::AwaitingReview, EnrollmentStatus::Lapsed, EnrollmentStatus::Withdrawn => false,
        };
        if (! $running || $agreement->status !== 'active' || ! $agreement->billsOnARecurringCadence()) {
            return null;
        }
        $day = $today->toDateString();
        $cycles = new BillingCycleResolver;
        $startsOn = $agreement->retainerStartsOn()->toDateString();
        $next = $day < $startsOn
            ? $cycles->cycleContaining($agreement, Carbon::parse($startsOn))->start->toDateString()
            : $cycles->cycleContaining($agreement, Carbon::parse($day))->end->copy()->addDay()->toDateString();
        $endsOn = $agreement->retainerEndsOn()?->toDateString();

        return $endsOn !== null && $next > $endsOn ? null : $next;
    }
}
