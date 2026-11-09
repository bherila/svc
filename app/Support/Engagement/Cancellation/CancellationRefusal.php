<?php

namespace App\Support\Engagement\Cancellation;

/**
 * Why a cancellation, a decision on one or an undo was refused (CAN-2 to
 * CAN-4, EDGE-9). Codes are part of the agent API and never change.
 */
enum CancellationRefusal: string
{
    case NotOffered = 'cancellation_not_offered';
    case NotRecurring = 'cancellation_not_recurring';
    case AgreementNotActive = 'cancellation_agreement_not_active';
    case DateNotOffered = 'cancellation_date_not_offered';
    case NoDatesOffered = 'cancellation_no_dates_offered';
    case AlreadyScheduled = 'cancellation_already_scheduled';
    case RequestPending = 'cancellation_request_pending';
    case RequestNotPending = 'cancellation_request_not_pending';
    case NotScheduled = 'cancellation_not_scheduled';
    case UndoNotSelfServe = 'cancellation_undo_not_self_serve';
    case EndReached = 'cancellation_end_reached';
    case EndScheduled = 'cancellation_end_scheduled';

    public function message(): string
    {
        return match ($this) {
            self::NotOffered => 'This agreement cannot be cancelled from the portal. Please contact us to end it.',
            self::NotRecurring => 'This agreement does not bill in periods, so it has no end dates to offer. End it by terminating it instead.',
            self::AgreementNotActive => 'Only an active agreement can be cancelled.',
            self::DateNotOffered => 'That end date is not one of the dates offered. Choose one of the offered dates.',
            self::NoDatesOffered => 'This agreement already ends on or before the earliest date a cancellation could take effect, so there is nothing to cancel.',
            self::AlreadyScheduled => 'This agreement already has an end scheduled. Undo it first to choose another date.',
            self::RequestPending => 'A cancellation request for this agreement is already waiting for a decision.',
            self::RequestNotPending => 'This cancellation request has already been decided or withdrawn.',
            self::NotScheduled => 'This agreement has no scheduled end to undo.',
            self::UndoNotSelfServe => 'This cancellation was decided by us, so only we can undo it. Please contact us to keep the service.',
            self::EndReached => 'The scheduled end date has been reached, so it can no longer be undone.',
            self::EndScheduled => 'This agreement has an end scheduled by a cancellation. Undo the scheduled end before changing its end date.',
        };
    }
}
