<?php

declare(strict_types=1);

namespace App\Support\Submission;

use App\Support\Approval\ConsultantTimeRelease;
use App\Support\Billing\SubcontractorBillingMode;

/**
 * Submitting hours and expenses for review (docs/ux-overhaul.md sections 7b
 * and 7e).
 *
 * Submitting is not approving. It is the author saying "this is ready for
 * whoever pays for it, or vouches for it, to look at", and it is the only
 * thing that puts a draft row on a reviewer's queue. Nothing here moves a
 * status or stamps an approval; the reviewer's own decision does that, in the
 * provider's workspace, and the author can never make it.
 *
 * Pure and database-free: callers read the facts once, and every refusal has
 * an exact-boundary test. Each rule is a `match`, and the refusals are checked
 * in a fixed order so a row that breaks several names the most useful one.
 */
final class SubmissionRules
{
    /** The words a refusal uses when the provider's own staff try to decide what the provider submitted. */
    public const string STAFF_MESSAGE = 'Hours and expenses your workspace submitted are approved by the person who pays for them or by a reviewer, not by your own team.';

    /** What an edit or delete of a submitted row is told. */
    public const string FROZEN_MESSAGE = 'This was submitted for review. Withdraw the submission to change it, then submit it again.';

    /** The status that waits on a submission; every other status is already on offer or billed. */
    public const string DRAFT = 'draft';

    /**
     * The statuses of rows the provider has already marked ready to bill.
     *
     * They are on offer to a reviewer without any submission, as slice 10 left
     * them. `invoiced` is here because issuing an invoice rewrites approved
     * work to it, and an issued invoice does not take the row back off the
     * client's list.
     *
     * @return list<string>
     */
    public static function readyStatuses(): array
    {
        return ['approved', 'invoiced'];
    }

    /**
     * Is a row on a reviewer's queue by its status and submission alone?
     *
     * The other conditions a queue imposes (billable, shown to the client,
     * not direct time) are the queue's own and apply to every row alike.
     */
    public static function onOffer(string $status, bool $submitted): bool
    {
        return in_array($status, self::readyStatuses(), true) || ($status === self::DRAFT && $submitted);
    }

    /**
     * May this person submit this logged time?
     *
     * @param  int|null  $authorId  who logged it; a row with no author is nobody's to submit
     */
    public static function timeRefusal(
        ?int $authorId,
        int $actorId,
        string $status,
        bool $alreadySubmitted,
        bool $billable,
        bool $shownToClient,
        ?SubcontractorBillingMode $mode,
    ): ?SubmitRefusal {
        return match (true) {
            $authorId === null || $authorId !== $actorId => SubmitRefusal::NotYours,
            $alreadySubmitted => SubmitRefusal::AlreadySubmitted,
            $status !== self::DRAFT => SubmitRefusal::NotDraft,
            ! $billable => SubmitRefusal::NotBillable,
            ! $shownToClient => SubmitRefusal::NotShownToClient,
            ! ConsultantTimeRelease::clientApproves($mode) => SubmitRefusal::DirectTime,
            default => null,
        };
    }

    /**
     * May this person submit this expense?
     *
     * @param  int|null  $authorId  who recorded it
     */
    public static function expenseRefusal(?int $authorId, int $actorId, string $status, bool $alreadySubmitted): ?SubmitRefusal
    {
        return match (true) {
            $authorId === null || $authorId !== $actorId => SubmitRefusal::NotYours,
            $alreadySubmitted => SubmitRefusal::AlreadySubmitted,
            $status !== self::DRAFT => SubmitRefusal::NotDraft,
            default => null,
        };
    }

    /**
     * May this person take back a submission?
     *
     * @param  int|null  $authorId  who logged the row
     * @param  bool  $clientApproved  an approval stands on it, whoever gave it
     */
    public static function withdrawRefusal(?int $authorId, int $actorId, string $status, bool $submitted, bool $clientApproved): ?WithdrawRefusal
    {
        return match (true) {
            $authorId === null || $authorId !== $actorId => WithdrawRefusal::NotYours,
            ! $submitted => WithdrawRefusal::NotSubmitted,
            $clientApproved => WithdrawRefusal::Approved,
            $status !== self::DRAFT => WithdrawRefusal::ReadyToBill,
            default => null,
        };
    }

    /**
     * May this person decide this row, given whether it is on offer only
     * because it was submitted?
     *
     * The provider's own staff do not approve what the provider submitted:
     * the point of submitting is that someone else vouches for it. A row the
     * provider has itself marked ready to bill is slice 8's and 10's business
     * and is unchanged.
     */
    public static function mayDecide(string $status, bool $deciderIsProviderStaff): bool
    {
        return ! ($status === self::DRAFT && $deciderIsProviderStaff);
    }
}
