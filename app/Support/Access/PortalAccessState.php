<?php

namespace App\Support\Access;

/**
 * One portal membership's reach: its scope and, when that scope is
 * `projects`, the public ids of the projects it is limited to.
 */
final readonly class PortalAccessState
{
    /** @param list<string> $projectPublicIds sorted */
    public function __construct(
        public PortalAccessScope $scope,
        public array $projectPublicIds,
    ) {}

    /** @return array{scope: string, projects: list<string>} */
    public function toArray(): array
    {
        return ['scope' => $this->scope->value, 'projects' => $this->projectPublicIds];
    }
}
