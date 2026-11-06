<?php

declare(strict_types=1);

namespace App\Support\Approval;

/** How a row came to be approved by its client. */
enum ClientApprovalSource: string
{
    /** A person approved it in the portal. Stands until a person changes it. */
    case Portal = 'portal';

    /** The invoice carrying it was paid in full. Stands while that stays true. */
    case Payment = 'payment';

    /**
     * A person approved it through a reviewer grant: the provider's own say-so
     * that this person may vouch for its hours and expenses (UX overhaul 7d).
     * Stands until a person changes it, like a portal approval.
     */
    case Reviewer = 'reviewer';
}
