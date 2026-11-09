<?php

namespace App\Support\Packages\Migration;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The decisions of a move, pure (MIG-1, MIG-3, MIG-5): whether a subscriber
 * due to move is moved, skipped or held, whether a move is due, and whether
 * it may still be cancelled. No database, no clock: the caller reads the
 * facts under its locks and the workspace's today.
 */
final class MigrationRules
{
    /**
     * One subscriber on or after the effective date (MIG-3). In order: a
     * service or agreement that has stopped is skipped for good, as is one
     * ending before the new terms would start; a client's request to leave
     * before the date waits for its answer; and an issued invoice already
     * selling a period from the date on waits for an owner, since applying
     * would bill that period twice.
     *
     * @param  CarbonInterface|null  $endsOn  the agreement's `ends_on`, a scheduled end included
     * @param  CarbonInterface|null  $pendingEndOn  the end a pending cancellation request asks for, if any
     */
    public static function decide(
        bool $enrollmentActive,
        bool $carriesFromVersion,
        string $agreementStatus,
        ?CarbonInterface $endsOn,
        CarbonInterface $effectiveOn,
        ?CarbonInterface $pendingEndOn,
        bool $periodInvoiced,
    ): MigrationDecision {
        $effective = $effectiveOn->toDateString();

        return match (true) {
            ! $enrollmentActive => MigrationDecision::skip(MigrationSkip::EnrollmentInactive),
            ! $carriesFromVersion => MigrationDecision::skip(MigrationSkip::VersionMoved),
            $agreementStatus !== 'active' => MigrationDecision::skip(MigrationSkip::AgreementEnded),
            $endsOn !== null && $endsOn->toDateString() < $effective => MigrationDecision::skip(MigrationSkip::EndsBeforeEffective),
            $pendingEndOn !== null && $pendingEndOn->toDateString() < $effective => MigrationDecision::hold(MigrationHold::CancellationPending),
            $periodInvoiced => MigrationDecision::hold(MigrationHold::PeriodInvoiced),
            default => MigrationDecision::apply(),
        };
    }

    /**
     * Why one subscriber cannot be part of a move on `$chosen`, or null when
     * it can (MIG-1): it must be an active service on the version moved from,
     * in no other scheduled move, with the date among those its notice and
     * its invoices allow. Without a date, only whether it could be moved at all.
     */
    public static function eligibility(bool $activeOnFromVersion, bool $alreadyScheduled, ?MigrationEffectiveDates $dates, ?CarbonInterface $chosen, bool $billsByUsage = false): ?MigrationRefusal
    {
        return match (true) {
            ! $activeOnFromVersion => MigrationRefusal::NotASubscriber,
            $alreadyScheduled => MigrationRefusal::AlreadyScheduled,
            $billsByUsage => MigrationRefusal::BillsByUsage,
            $dates === null => MigrationRefusal::NotRecurring,
            $dates->offered === [] => MigrationRefusal::EndsFirst,
            $chosen !== null && ! $dates->offers($chosen) => MigrationRefusal::DateNotOffered,
            default => null,
        };
    }

    /** Whether a subscriber's move is due on the workspace's today: from its effective date on, while scheduled. */
    public static function isDue(MigrationTargetStatus $status, CarbonInterface $effectiveOn, CarbonInterface $today): bool
    {
        return $status === MigrationTargetStatus::Scheduled && $today->toDateString() >= $effectiveOn->toDateString();
    }

    /**
     * Why a move cannot be cancelled now, or null when it can (MIG-5): while
     * it is scheduled. Before its date that stops the whole move; from it on,
     * only the subscribers still waiting, the ones already moved staying moved.
     */
    public static function cancelRefusal(MigrationStatus $status): ?MigrationRefusal
    {
        return $status === MigrationStatus::Scheduled ? null : MigrationRefusal::NotScheduled;
    }

    /**
     * Whether an agreement bills by usage: an hourly rate, or a retainer (whose
     * hours are reconciled after the period, as overage). Such an agreement's
     * last period is settled on the invoice that opens the next, which a new
     * agreement on the same project would also claim, so a move refuses it
     * (MIG-1).
     */
    public static function billsByUsage(?int $hourlyRate, ?int $retainerAmount, ?int $retainerMinutes, ?int $periodRetainerAmount, ?int $periodRetainerMinutes): bool
    {
        return $hourlyRate !== null || ($retainerAmount ?? 0) > 0 || ($retainerMinutes ?? 0) > 0 || ($periodRetainerAmount ?? 0) > 0 || ($periodRetainerMinutes ?? 0) > 0;
    }

    /**
     * Whether a company's autopay would stop short of the new price (MIG-4):
     * autopay is on with a limit below it. SVC never raises the limit; this
     * only says so. Off, there is no limit to fall short.
     */
    public static function autopayBelowPrice(?int $autopayLimit, int $newPrice): bool
    {
        return $autopayLimit !== null && $autopayLimit < $newPrice;
    }

    /**
     * What is left of the agreement's minimum term on the effective date, in
     * whole months from it: the agreement that carries the new terms keeps the
     * old commitment and never starts a new one. Null when none is left.
     */
    public static function remainingMinimumTerm(CarbonInterface $startsOn, ?int $minimumTermMonths, CarbonInterface $effectiveOn): ?int
    {
        if ($minimumTermMonths === null || $minimumTermMonths < 1) {
            return null;
        }
        $start = CarbonImmutable::parse($startsOn->toDateString(), 'UTC');
        $effective = CarbonImmutable::parse($effectiveOn->toDateString(), 'UTC');
        $termEnds = $start->addMonthsNoOverflow($minimumTermMonths)->subDay();
        if ($termEnds->lt($effective)) {
            return null;
        }
        $months = 1;
        while ($effective->addMonthsNoOverflow($months)->subDay()->lt($termEnds)) {
            $months++;
        }

        return $months;
    }
}
