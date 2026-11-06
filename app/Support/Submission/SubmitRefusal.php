<?php

declare(strict_types=1);

namespace App\Support\Submission;

/** Why a row cannot be submitted for review. */
enum SubmitRefusal: string
{
    /** Only the person who did the work (or spent the money) submits it. */
    case NotYours = 'not_yours';

    case AlreadySubmitted = 'already_submitted';

    /** Only draft rows wait on a reviewer: approved ones are already on offer, invoiced ones are billed. */
    case NotDraft = 'not_draft';

    case NotBillable = 'not_billable';

    /** A reviewer reads only what the client was meant to read. */
    case NotShownToClient = 'not_shown_to_client';

    /** Direct subcontractor time is tracked for the sub's own billing and has no client to approve it. */
    case DirectTime = 'direct_time';

    public function message(): string
    {
        return match ($this) {
            self::NotYours => 'Only the person who logged this submits it.',
            self::AlreadySubmitted => 'This is already submitted. Withdraw it first to change it.',
            self::NotDraft => 'This is already marked ready to bill or has been billed, so it is not waiting on a submission.',
            self::NotBillable => 'Only billable time is submitted for review.',
            self::NotShownToClient => 'Add the client-facing description and show it to the client before submitting: a reviewer reads only that.',
            self::DirectTime => 'Direct subcontractor time is not billed to this client, so there is nothing to submit.',
        };
    }
}
