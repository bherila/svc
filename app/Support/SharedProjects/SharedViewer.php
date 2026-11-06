<?php

namespace App\Support\SharedProjects;

use App\Support\Access\StaffRole;
use App\Support\AgentApi\ProjectRole;

/**
 * Who is looking at a shared project, as facts, and everything they may do
 * with it. Database-free: SharedProjectAccess reads the facts once and these
 * rules decide, so "may this person see or do X on a shared project" is one
 * place with exact-boundary tests.
 *
 * Every rule defaults to no. A participant sees a task only if the task is
 * shared and either they manage their own workspace or it is assigned to them;
 * the host side keeps what it already has.
 *
 * `$role` is the viewer's role in the workspace they are standing in: the host
 * for a host viewer, their own workspace for a participant.
 * `$projectRole` is their role on the host's project, and is only ever read on
 * the host side - a participant holds no role on the host's project.
 */
final readonly class SharedViewer
{
    public function __construct(
        public ShareSide $side,
        public ?StaffRole $role,
        public ?ProjectRole $projectRole = null,
    ) {}

    private function manages(): bool
    {
        return $this->role?->managesWorkspace() === true;
    }

    private function isHost(): bool
    {
        return $this->side === ShareSide::Host;
    }

    /** Whether the viewer may open the project at all. */
    public function canViewProject(): bool
    {
        if ($this->role === null) {
            return false;
        }

        return ! $this->isHost() || $this->manages() || $this->projectRole !== null;
    }

    /** Whether the whole project is open to them, rather than only the tasks given to them. */
    public function seesWholeProject(): bool
    {
        if (! $this->canViewProject()) {
            return false;
        }

        return $this->isHost() || $this->manages();
    }

    /** The participating workspaces, and the invitations still pending, are the host's to read. */
    public function canSeeParticipants(): bool
    {
        return $this->isHost() && $this->canViewProject();
    }

    /**
     * @param  bool  $taskIsShared  the task is one a participant may be shown (see SharedTaskScope)
     * @param  bool  $assignedToViewer  the viewer is on the task's assignee list
     */
    public function canSeeTask(bool $taskIsShared, bool $assignedToViewer): bool
    {
        if (! $this->canViewProject()) {
            return false;
        }
        if ($this->isHost()) {
            return true;
        }

        return $taskIsShared && ($this->manages() || $assignedToViewer);
    }

    /** @param bool $sharedWithParticipants the comment was written into, or published to, the shared conversation */
    public function canSeeComment(bool $sharedWithParticipants): bool
    {
        if (! $this->canViewProject()) {
            return false;
        }

        return $this->isHost() || $sharedWithParticipants;
    }

    /** A comment goes with seeing the task, and a host viewer with no write role only reads. */
    public function canComment(bool $taskIsShared, bool $assignedToViewer): bool
    {
        if (! $this->canSeeTask($taskIsShared, $assignedToViewer)) {
            return false;
        }

        return ! $this->isHost() || $this->manages() || $this->projectRole?->canLogTime() === true;
    }

    /** Only the host assigns, and only someone who may already manage its tasks. */
    public function canAssign(): bool
    {
        return $this->isHost() && $this->role !== null && ($this->manages() || $this->projectRole?->canManageTasks() === true);
    }

    /** Inviting, revoking an invitation and revoking a participant: the host's owners and admins, nobody else. */
    public function canManageParticipants(): bool
    {
        return $this->isHost() && $this->manages();
    }

    /** Leaving and linking a local project belong to the participant workspace's owners and admins. */
    public function canActForParticipant(): bool
    {
        return ! $this->isHost() && $this->manages();
    }
}
