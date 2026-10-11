<?php

namespace App\Services\Access;

use App\Models\Workspace;
use App\Support\Access\StaffRole;

/** A workspace a delegated access actor owns or administers, and their role there. */
final readonly class ManagedWorkspace
{
    public function __construct(
        public Workspace $workspace,
        public StaffRole $actorRole,
    ) {}
}
