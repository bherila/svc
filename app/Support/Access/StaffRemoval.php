<?php

namespace App\Support\Access;

/**
 * What removing a person's staff access took away, so a caller can disclose
 * and audit it: the role they held and every project assignment that went
 * with it.
 */
final readonly class StaffRemoval
{
    /** @param list<ProjectAssignment> $removedAssignments in project order */
    public function __construct(
        public string $userPublicId,
        public StaffRole $role,
        public array $removedAssignments,
    ) {}
}
