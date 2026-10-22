<?php

namespace App\Services\Tasks;

use App\Support\AgentApi\ProjectRole;

/** Database-free decisions over current, locked membership facts. */
final readonly class TaskAccessFacts
{
    /** @param array<string, int> $accounts public account IDs to database keys
     * @param  array<int, string>  $staffRoles
     * @param  array<int, ProjectRole>  $projectRoles
     */
    public function __construct(public array $accounts, private array $staffRoles, private array $projectRoles, public bool $portalMember = false) {}

    public function canManage(int $userId): bool
    {
        return isset($this->staffRoles[$userId]) && (in_array($this->staffRoles[$userId], ['owner', 'admin'], true)
            || ($this->projectRoles[$userId] ?? null)?->canManageTasks() === true);
    }

    public function canAssign(string $publicId): bool
    {
        $userId = $this->accounts[$publicId] ?? null;

        return $userId !== null && isset($this->staffRoles[$userId])
            && (in_array($this->staffRoles[$userId], ['owner', 'admin'], true) || isset($this->projectRoles[$userId]));
    }
}
