<?php

namespace App\Support\Access;

/**
 * A person's staff access to one workspace: their membership and every
 * project they are assigned to.
 *
 * An owner or admin reaches every project whatever their assignments say;
 * assignments are kept anyway, so a later change back to `member` restores
 * them.
 */
final readonly class StaffAccess
{
    /** @param list<ProjectAssignment> $assignments in project order */
    public function __construct(
        public string $membershipPublicId,
        public StaffRole $role,
        public array $assignments,
    ) {}
}
