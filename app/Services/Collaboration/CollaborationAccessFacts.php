<?php

namespace App\Services\Collaboration;

use App\Support\AgentApi\ProjectRole;

/** Current locking reads are loaded once, after all domain locks. */
final readonly class CollaborationAccessFacts
{
    public function __construct(public ?string $workspaceRole, public ?ProjectRole $projectRole, public bool $companyWide, public bool $portalMember) {}

    public function canView(): bool
    {
        return $this->manager() || (! $this->companyWide && $this->workspaceRole !== null && $this->projectRole !== null);
    }

    public function canPublish(): bool
    {
        return $this->manager() || (! $this->companyWide && $this->workspaceRole !== null && ($this->projectRole?->canManageTasks() ?? false));
    }

    public function canComment(): bool
    {
        return $this->manager() || (! $this->companyWide && $this->workspaceRole !== null && in_array($this->projectRole, [ProjectRole::Owner, ProjectRole::Manager, ProjectRole::Contributor], true));
    }

    private function manager(): bool
    {
        return in_array($this->workspaceRole, ['owner', 'admin'], true);
    }
}
