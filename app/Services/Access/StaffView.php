<?php

namespace App\Services\Access;

use App\Models\ClientProjectMembership;
use App\Models\WorkspaceMembership;
use App\Support\Access\StaffAuthority;
use App\Support\Access\StaffRole;

/**
 * One person's staff access within a delegated access actor's reach, as read
 * together: the actor's roles, the person's memberships and their project
 * assignments. Database-free once built, so what is editable and what the
 * revision covers are decided from the same rows.
 */
final readonly class StaffView
{
    /**
     * @param  array<int, StaffRole>  $actorRoles  workspace id => the actor's role
     * @param  list<WorkspaceMembership>  $memberships  the person's, in workspace order
     * @param  list<ClientProjectMembership>  $assignments  the person's, in workspace and project order
     */
    public function __construct(
        public string $cascadeRules,
        public int $actorId,
        public int $targetId,
        public array $actorRoles,
        public array $memberships,
        public array $assignments,
    ) {}

    /**
     * Whether the actor may change or remove this membership, as
     * {@see StaffAuthority} decides it: never an owner or
     * the actor's own, an admin only by an owner, a member by an owner or
     * admin. A role this boundary does not know is never editable.
     */
    public function editable(WorkspaceMembership $membership): bool
    {
        $actorRole = $this->actorRoles[(int) $membership->workspace_id] ?? null;

        return (int) $membership->user_id !== $this->actorId && match (StaffRole::tryFrom($membership->role)) {
            StaffRole::Member => $actorRole?->managesWorkspace() === true,
            StaffRole::Admin => $actorRole === StaffRole::Owner,
            StaffRole::Owner, null => false,
        };
    }

    public function allEditable(): bool
    {
        return array_all($this->memberships, $this->editable(...));
    }

    /** The person's project assignments in one workspace, which removing that membership removes. */
    public function assignmentCount(int $workspaceId): int
    {
        return count(array_filter($this->assignments, static fn (ClientProjectMembership $assignment): bool => (int) $assignment->workspace_id === $workspaceId));
    }

    /**
     * A digest of what a read showed and what a write would take: the cascade
     * rules, the actor's reach (each workspace they manage and their role there,
     * which decides what is editable), and the person's memberships and every
     * assignment - which project and with which role, not only how many.
     */
    public function revision(): string
    {
        $roles = $this->actorRoles;
        ksort($roles);

        return hash('sha256', (string) json_encode([
            'rules' => $this->cascadeRules,
            'actor' => $this->actorId,
            'target' => $this->targetId,
            'reach' => array_map(static fn (StaffRole $role): string => $role->value, $roles),
            'memberships' => array_map(static fn (WorkspaceMembership $membership): array => [(int) $membership->workspace_id, $membership->role], $this->memberships),
            'assignments' => array_map(static fn (ClientProjectMembership $assignment): array => [
                (int) $assignment->workspace_id, (int) $assignment->getAttribute('client_project_id'), $assignment->role->value,
            ], $this->assignments),
        ], JSON_THROW_ON_ERROR));
    }
}
