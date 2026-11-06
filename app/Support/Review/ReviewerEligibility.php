<?php

declare(strict_types=1);

namespace App\Support\Review;

use App\Support\SharedProjects\InviteeAddress;

/**
 * Does this grant reach this person right now?
 *
 * Read from the facts every time and never cached, so revoking stops a
 * reviewer on their very next request. It fails closed, and has two exclusions
 * that are about who is paying, not about who is asking:
 *
 * - A member of the workspace that gave the grant is that provider's own staff.
 *   A client's approval is the payer's, and the provider's staff are not the
 *   payer, so a grant never reaches them whatever address or workspace it
 *   names.
 * - Someone who already holds a portal membership of the client the grant is
 *   for is decided by that membership alone (its own approval permission, an
 *   explicit no included). A grant never widens or overrides it.
 */
final class ReviewerEligibility
{
    /**
     * @param  list<int>  $managedWorkspaceIds  the workspaces this person owns or administers
     * @param  bool  $viewerIsGranterStaff  they belong to the workspace that gave the grant
     * @param  bool  $viewerHoldsPortalAccess  they hold a portal membership of the client the grant is for
     */
    public static function reaches(
        ReviewerGrantState $state,
        ReviewerGrantee $grantee,
        string $viewerEmail,
        array $managedWorkspaceIds,
        bool $viewerIsGranterStaff,
        bool $viewerHoldsPortalAccess,
    ): bool {
        if (! $state->reaches() || $viewerIsGranterStaff || $viewerHoldsPortalAccess) {
            return false;
        }

        return match ($grantee->kind) {
            GranteeKind::Contact => InviteeAddress::matches((string) $grantee->email, $viewerEmail),
            GranteeKind::Workspace => in_array($grantee->workspaceId, $managedWorkspaceIds, true),
        };
    }
}
