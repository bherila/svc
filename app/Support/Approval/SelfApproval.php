<?php

declare(strict_types=1);

namespace App\Support\Approval;

/**
 * Nobody approves their own work (UX overhaul section 7b).
 *
 * One question, asked by every path that records an approval: REST, MCP, the
 * portal and the payment hook. It compares people, never roles, so being an
 * owner, a project manager or a client contact makes no difference.
 *
 * An unknown author is not the approver: an expense whose creator's account is
 * gone has nobody to be refused.
 */
final class SelfApproval
{
    /** The words every time refusal uses, so a caller is told what happens instead. */
    public const string TIME_MESSAGE = 'You cannot approve your own time. It is pulled onto the client\'s draft invoice, and the client approves it.';

    public const string EXPENSE_MESSAGE = 'You cannot approve your own expense. The client approves it.';

    public static function refuses(?int $authorId, int $approverId): bool
    {
        return $authorId !== null && $authorId === $approverId;
    }
}
