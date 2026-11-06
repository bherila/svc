<?php

declare(strict_types=1);

namespace App\Support\Review;

/**
 * Who a provider may write a grant to. Database-free: the caller reads the
 * facts once and this decides, so every refusal has an exact-boundary test.
 */
final class ReviewerGrantRules
{
    /**
     * @param  bool  $granteeIsGranterStaff  the address belongs to someone on the granting workspace's team
     * @param  bool  $sharesProject  the workspace takes part in a shared project with the granter, either way round
     * @param  bool  $contactHasPortalAccess  the address already holds a portal membership of this client
     */
    public static function refusal(
        ReviewerGrantee $grantee,
        int $granterWorkspaceId,
        bool $granteeIsGranterStaff,
        bool $sharesProject,
        bool $contactHasPortalAccess,
    ): ?GrantRefusal {
        return match ($grantee->kind) {
            GranteeKind::Workspace => match (true) {
                $grantee->workspaceId === $granterWorkspaceId => GrantRefusal::OwnWorkspace,
                ! $sharesProject => GrantRefusal::NoSharedProject,
                default => null,
            },
            GranteeKind::Contact => match (true) {
                $granteeIsGranterStaff => GrantRefusal::OwnStaff,
                $contactHasPortalAccess => GrantRefusal::HasPortalAccess,
                default => null,
            },
        };
    }
}
