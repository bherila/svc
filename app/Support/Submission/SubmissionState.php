<?php

declare(strict_types=1);

namespace App\Support\Submission;

use App\Support\Approval\ClientApprovalVerdict;

/**
 * Where one hour or expense stands on its way to being approved, as its
 * author sees it. Read from the stored facts every time, never cached.
 *
 * Mirrors the lifecycle in docs/ux-overhaul.md 7b: a row is a draft, then
 * submitted, then either approved or disputed (sent back to its author).
 */
enum SubmissionState: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Disputed = 'disputed';

    public static function of(string $status, bool $submitted, ClientApprovalVerdict $verdict): self
    {
        return match ($verdict) {
            ClientApprovalVerdict::Disputed => self::Disputed,
            ClientApprovalVerdict::Approved, ClientApprovalVerdict::ApprovedByPayment => self::Approved,
            ClientApprovalVerdict::Pending => $submitted || $status !== SubmissionRules::DRAFT ? self::Submitted : self::Draft,
        };
    }
}
