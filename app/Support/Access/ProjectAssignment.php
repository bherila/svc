<?php

namespace App\Support\Access;

use App\Support\AgentApi\ProjectRole;

/** One staff project assignment, named by the project's public id. */
final readonly class ProjectAssignment
{
    public function __construct(
        public string $projectPublicId,
        public ProjectRole $role,
    ) {}

    /** @return array{project: string, role: string} */
    public function toArray(): array
    {
        return ['project' => $this->projectPublicId, 'role' => $this->role->value];
    }
}
