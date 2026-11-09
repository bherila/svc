<?php

namespace App\Support\Engagement\Cancellation;

use App\Support\Packages\CancellationMode;
use Carbon\CarbonInterface;

/**
 * Who may cancel, decide and undo, and when (CAN-3, CAN-4, EDGE-9), as pure
 * decisions over facts read under the agreement's lock. Each answers the
 * first reason to refuse, or null when the act may go ahead.
 */
final class CancellationRules
{
    /**
     * A company contact asking to end an agreement from the portal (CAN-3).
     * `none` mode offers nothing; an agreement already ending, or already
     * asked about, is not asked about again.
     */
    public static function clientRefusal(CancellationMode $mode, string $agreementStatus, bool $recurring, bool $hasScheduledEnd, bool $hasPendingRequest): ?CancellationRefusal
    {
        if ($agreementStatus !== 'active') {
            return CancellationRefusal::AgreementNotActive;
        }
        if ($mode === CancellationMode::None) {
            return CancellationRefusal::NotOffered;
        }
        if ($hasPendingRequest) {
            return CancellationRefusal::RequestPending;
        }

        return self::scheduleRefusal($agreementStatus, $recurring, $hasScheduledEnd);
    }

    /**
     * An owner or admin scheduling an end, directly or by approving a
     * request (CAN-4). Any mode: the portal mode is what the client may do.
     */
    public static function scheduleRefusal(string $agreementStatus, bool $recurring, bool $hasScheduledEnd): ?CancellationRefusal
    {
        if ($agreementStatus !== 'active') {
            return CancellationRefusal::AgreementNotActive;
        }
        if (! $recurring) {
            return CancellationRefusal::NotRecurring;
        }

        return $hasScheduledEnd ? CancellationRefusal::AlreadyScheduled : null;
    }

    /**
     * Undoing a scheduled end, which restores the end it replaced (EDGE-9).
     * Possible until its date: on the date the scheduled command ends the
     * agreement. An owner or admin may undo any; a company contact only an
     * end they scheduled themselves in `self_serve` mode, while the agreement
     * is still in that mode - never one an owner or admin decided.
     */
    public static function undoRefusal(?ScheduledTerminationStatus $status, ?ScheduledTerminationSource $source, CancellationMode $mode, bool $byManager, CarbonInterface $today, ?CarbonInterface $endsOn): ?CancellationRefusal
    {
        if ($status !== ScheduledTerminationStatus::Scheduled || $endsOn === null) {
            return CancellationRefusal::NotScheduled;
        }
        if ($today->toDateString() > $endsOn->toDateString()) {
            return CancellationRefusal::EndReached;
        }
        if (! $byManager && ($source !== ScheduledTerminationSource::SelfServe || $mode !== CancellationMode::SelfServe)) {
            return CancellationRefusal::UndoNotSelfServe;
        }

        return null;
    }

    /** Whether a scheduled end is due to be carried out on `$today`, the workspace's (CAN-5). */
    public static function isDue(ScheduledTerminationStatus $status, CarbonInterface $endsOn, CarbonInterface $today): bool
    {
        return $status === ScheduledTerminationStatus::Scheduled && $endsOn->toDateString() <= $today->toDateString();
    }
}
