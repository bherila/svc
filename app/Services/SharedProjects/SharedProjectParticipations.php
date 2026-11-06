<?php

namespace App\Services\SharedProjects;

use App\Actions\CreateWorkspace;
use App\Models\ClientCompany;
use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\ClientProjectParticipationInvitation;
use App\Models\ClientTask;
use App\Models\ClientTaskAssignment;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\AccessAuditLog;
use App\Services\Authorization\ProjectAccess;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\StaffRole;
use App\Support\Concurrency\Locks;
use App\Support\SharedProjects\InvitationState;
use App\Support\SharedProjects\InviteeAddress;
use App\Support\WorkspaceClock;
use App\Support\Workspaces\WorkspaceEdition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The lifecycle of a participation: invite, accept or decline, revoke, leave,
 * and link a local project. Browser-only by decision (see docs/ux-overhaul.md,
 * slice 9): handing another business access to a project is a person's act,
 * so none of these is a REST or MCP operation.
 *
 * Every change runs in one transaction under the host workspace's row lock -
 * the same row the access coordinator serialises on - and decides again from
 * what it reads there. The invitation and participation rows need no lock of
 * their own, since every writer to them holds the host lock. Each change is
 * written to the access audit, in the host and, where the participant's own
 * workspace changes, in the participant.
 *
 * Ending a participation (revoke or leave) in the same transaction:
 * clears the participant's link to the share, and removes the assignments it
 * held on the host's tasks. Comments it wrote stay: they are the host's record
 * of the conversation, and name the workspace that wrote them.
 */
final class SharedProjectParticipations
{
    /** An invitation is good for this many days. */
    public const int INVITATION_DAYS = 14;

    public function __construct(
        private readonly SharedProjectAccess $access,
        private readonly ProjectAccess $roles,
        private readonly AccessAuditLog $audit,
        private readonly CreateWorkspace $createWorkspace,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public function invite(User $actor, Workspace $host, string $projectPublicId, string $email): ClientProjectParticipationInvitation
    {
        $address = InviteeAddress::normalize($email);
        Validator::make(['email' => $address], ['email' => ['required', 'email:rfc', 'max:255']])->validate();
        $this->hostManager($actor, $host, $projectPublicId);

        return $this->locked($host, function (Workspace $locked) use ($actor, $projectPublicId, $address): ClientProjectParticipationInvitation {
            $context = $this->hostManager($actor, $locked, $projectPublicId);
            $now = $this->clock->now($locked);

            // A newer invitation to the same address replaces the pending one,
            // so there is exactly one live link per address and project.
            ClientProjectParticipationInvitation::query()
                ->where('host_workspace_id', $locked->id)->where('client_project_id', $context->project->id)
                ->where('email', $address)->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'resolved_by_user_id' => $actor->id]);

            $invitation = new ClientProjectParticipationInvitation;
            $invitation->forceFill([
                'host_workspace_id' => $locked->id,
                'client_project_id' => $context->project->id,
                'email' => $address,
                'invited_by_user_id' => $actor->id,
                'expires_at' => $now->addDays(self::INVITATION_DAYS),
            ])->save();

            $this->audit->record(AccessMutationContext::user($actor), $locked, AccessAuditAction::SharedProjectInvited, null, null,
                ['invitation' => $invitation->public_id, 'project' => $context->project->public_id]);

            return $invitation;
        });
    }

    public function revokeInvitation(User $actor, Workspace $host, string $projectPublicId, string $invitationPublicId): ClientProjectParticipationInvitation
    {
        return $this->locked($host, function (Workspace $locked) use ($actor, $projectPublicId, $invitationPublicId): ClientProjectParticipationInvitation {
            $invitation = ClientProjectParticipationInvitation::query()
                ->where('host_workspace_id', $locked->id)->where('public_id', $invitationPublicId)->first();
            abort_if($invitation === null, 404);
            $project = ClientProject::query()->where('workspace_id', $locked->id)->whereKey($invitation->client_project_id)->firstOrFail();
            abort_unless($project->public_id === $projectPublicId, 404);
            $this->hostManager($actor, $locked, $project->public_id);
            $now = $this->clock->now($locked);
            $this->requireOpen($invitation, $now);

            $invitation->forceFill(['revoked_at' => $now, 'resolved_by_user_id' => $actor->id])->save();
            $this->audit->record(AccessMutationContext::user($actor), $locked, AccessAuditAction::SharedProjectInvitationRevoked, null,
                ['invitation' => $invitation->public_id], null);

            return $invitation;
        });
    }

    /**
     * The invitation to this person, if one is addressed to them and still open.
     * An invitation for someone else's address answers exactly as a missing one.
     */
    public function openInvitationFor(User $user, string $invitationPublicId): ?ClientProjectParticipationInvitation
    {
        $invitation = ClientProjectParticipationInvitation::query()->where('public_id', $invitationPublicId)->first();
        if ($invitation === null || ! InviteeAddress::matches($invitation->email, $user->email)) {
            return null;
        }

        return $invitation->state($this->clock->now())->isOpen() ? $invitation : null;
    }

    /** @return Collection<int, ClientProjectParticipationInvitation> */
    public function openInvitationsFor(User $user): Collection
    {
        if (trim($user->email) === '') {
            return new Collection;
        }

        return ClientProjectParticipationInvitation::query()
            ->where('email', InviteeAddress::normalize($user->email))
            ->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->now())
            ->with(['hostWorkspace', 'project'])
            ->orderBy('id')->limit(50)->get();
    }

    public function accept(User $actor, string $invitationPublicId, Workspace $participant): ClientProjectParticipation
    {
        $invitation = $this->openInvitationFor($actor, $invitationPublicId);
        abort_if($invitation === null, 404);
        $this->requireOwnerOrAdmin($actor, $participant);

        return $this->locked($invitation->hostWorkspace, function (Workspace $locked) use ($actor, $invitation, $participant): ClientProjectParticipation {
            $current = ClientProjectParticipationInvitation::query()->where('host_workspace_id', $locked->id)->whereKey($invitation->id)->firstOrFail();
            $now = $this->clock->now($locked);
            abort_unless($current->state($now)->isOpen() && InviteeAddress::matches($current->email, $actor->email), 404);
            $this->requireOwnerOrAdmin($actor, $participant);
            $project = ClientProject::query()->where('workspace_id', $locked->id)->whereKey($current->client_project_id)->firstOrFail();

            if ((int) $participant->id === (int) $locked->id) {
                throw ValidationException::withMessages(['workspace' => 'The host workspace already has this project. Choose another workspace.']);
            }
            if ($this->access->activeParticipation($project, $participant) !== null) {
                throw ValidationException::withMessages(['workspace' => 'That workspace already takes part in this project.']);
            }

            return $this->join($locked, $actor, $current, $project, $participant);
        });
    }

    /**
     * Accept an invitation with a workspace made for the occasion: the partner's
     * light workspace (docs/ux-overhaul.md section 7e).
     *
     * For a person who has no workspace of their own to join with - owner or
     * admin of nothing but the host's - so it is refused for anyone who does:
     * they choose that one. The workspace is theirs alone, light, with them as
     * owner. It holds a client the person names, and a local project linked to
     * the share, and nothing of the host's: not the host's client, rates,
     * agreements, time or any other record, and not even the client's name,
     * which the invitation never told them. The participation is the only link.
     *
     * @return array{ClientProjectParticipation, Workspace}
     */
    public function acceptIntoNewLightWorkspace(User $actor, string $invitationPublicId, string $clientName, ?string $workspaceName = null): array
    {
        $invitation = $this->openInvitationFor($actor, $invitationPublicId);
        abort_if($invitation === null, 404);
        $input = Validator::make(
            ['client_name' => trim($clientName), 'workspace_name' => $workspaceName === null ? null : trim($workspaceName)],
            ['client_name' => ['required', 'string', 'max:120'], 'workspace_name' => ['nullable', 'string', 'max:120']],
        )->validate();

        return $this->locked($invitation->hostWorkspace, function (Workspace $locked) use ($actor, $invitation, $input): array {
            $current = ClientProjectParticipationInvitation::query()->where('host_workspace_id', $locked->id)->whereKey($invitation->id)->firstOrFail();
            $now = $this->clock->now($locked);
            abort_unless($current->state($now)->isOpen() && InviteeAddress::matches($current->email, $actor->email), 404);
            $project = ClientProject::query()->where('workspace_id', $locked->id)->whereKey($current->client_project_id)->firstOrFail();

            if ($this->managesAnotherWorkspace($actor, $locked)) {
                throw ValidationException::withMessages(['workspace' => 'You already have a workspace. Choose which of your workspaces joins.']);
            }

            $name = $input['workspace_name'] !== null && $input['workspace_name'] !== '' ? $input['workspace_name'] : $this->defaultWorkspaceName($actor);
            $workspace = $this->createWorkspace->handle($actor, $name);
            $workspace->forceFill(['edition' => WorkspaceEdition::Light->value])->save();

            $company = new ClientCompany;
            $company->forceFill(['workspace_id' => $workspace->id, 'name' => $input['client_name'], 'slug' => Str::slug($input['client_name']).'-'.Str::lower(Str::random(5))])->save();
            $local = new ClientProject;
            $local->forceFill(['workspace_id' => $workspace->id, 'client_company_id' => $company->id, 'name' => $project->name, 'is_visible_to_client' => false])->save();

            $participation = $this->join($locked, $actor, $current, $project, $workspace);
            $local->forceFill(['shared_participation_id' => $participation->id])->save();
            $context = AccessMutationContext::user($actor);
            $this->audit->record($context, $workspace, AccessAuditAction::LightWorkspaceCreated, $actor, null,
                ['participation' => $participation->public_id, 'project' => $project->public_id, 'local_project' => $local->public_id]);
            $this->audit->record($context, $workspace, AccessAuditAction::SharedProjectLinked, null, null, ['project' => $project->public_id, 'local_project' => $local->public_id]);

            return [$participation, $workspace];
        });
    }

    /**
     * Write the participation and close the invitation, audited in the host and
     * in the participant. Every caller holds the host's lock.
     */
    private function join(Workspace $locked, User $actor, ClientProjectParticipationInvitation $current, ClientProject $project, Workspace $participant): ClientProjectParticipation
    {
        $now = $this->clock->now($locked);
        $participation = new ClientProjectParticipation;
        $participation->forceFill([
            'host_workspace_id' => $locked->id,
            'client_project_id' => $project->id,
            'participant_workspace_id' => $participant->id,
            'invitation_id' => $current->id,
            'granted_by_user_id' => $current->invited_by_user_id,
            'granted_at' => $current->created_at,
            'accepted_by_user_id' => $actor->id,
            'accepted_at' => $now,
            'active_marker' => 1,
        ])->save();
        $current->forceFill(['accepted_at' => $now, 'resolved_by_user_id' => $actor->id])->save();

        $context = AccessMutationContext::user($actor);
        $after = ['participation' => $participation->public_id, 'project' => $project->public_id];
        $this->audit->record($context, $locked, AccessAuditAction::SharedProjectJoined, $actor, null, $after);
        $this->audit->record($context, $participant, AccessAuditAction::SharedProjectJoined, $actor, null, $after);

        return $participation;
    }

    /** Does the person own or administer a workspace other than the host's? */
    private function managesAnotherWorkspace(User $actor, Workspace $host): bool
    {
        return WorkspaceMembership::query()
            ->where('user_id', $actor->id)
            ->whereIn('role', [StaffRole::Owner->value, StaffRole::Admin->value])
            ->where('workspace_id', '!=', $host->id)
            ->exists();
    }

    private function defaultWorkspaceName(User $actor): string
    {
        $name = trim($actor->name);

        return $name === '' ? 'My workspace' : mb_substr($name, 0, 100).' (partner)';
    }

    public function decline(User $actor, string $invitationPublicId): ClientProjectParticipationInvitation
    {
        $invitation = $this->openInvitationFor($actor, $invitationPublicId);
        abort_if($invitation === null, 404);

        return $this->locked($invitation->hostWorkspace, function (Workspace $locked) use ($actor, $invitation): ClientProjectParticipationInvitation {
            $current = ClientProjectParticipationInvitation::query()->where('host_workspace_id', $locked->id)->whereKey($invitation->id)->firstOrFail();
            $now = $this->clock->now($locked);
            abort_unless($current->state($now)->isOpen(), 404);
            $current->forceFill(['declined_at' => $now, 'resolved_by_user_id' => $actor->id])->save();
            $this->audit->record(AccessMutationContext::user($actor), $locked, AccessAuditAction::SharedProjectInvitationDeclined, $actor, ['invitation' => $current->public_id], null);

            return $current;
        });
    }

    /** The host ends a participant's access. */
    public function revokeParticipant(User $actor, Workspace $host, string $projectPublicId, string $participationPublicId): ClientProjectParticipation
    {
        return $this->locked($host, function (Workspace $locked) use ($actor, $projectPublicId, $participationPublicId): ClientProjectParticipation {
            $participation = ClientProjectParticipation::query()->where('host_workspace_id', $locked->id)->where('public_id', $participationPublicId)->first();
            abort_if($participation === null, 404);
            $project = ClientProject::query()->where('workspace_id', $locked->id)->whereKey($participation->client_project_id)->firstOrFail();
            abort_unless($project->public_id === $projectPublicId, 404);
            $this->hostManager($actor, $locked, $project->public_id);
            abort_unless($participation->state()->grantsAccess(), 404);

            $now = $this->clock->now($locked);
            $participation->forceFill(['revoked_at' => $now, 'revoked_by_user_id' => $actor->id, 'active_marker' => null])->save();
            $this->end($participation, $project);
            $this->recordEnd($actor, $participation, $project, AccessAuditAction::SharedProjectParticipantRevoked);

            return $participation;
        });
    }

    /** The participant stops taking part. */
    public function leave(User $actor, Workspace $participant, string $projectPublicId): ClientProjectParticipation
    {
        $this->requireOwnerOrAdmin($actor, $participant);
        $project = ClientProject::query()->where('public_id', $projectPublicId)->first();
        abort_if($project === null, 404);
        $host = Workspace::query()->whereKey($project->workspace_id)->firstOrFail();

        return $this->locked($host, function (Workspace $locked) use ($actor, $participant, $project): ClientProjectParticipation {
            $this->requireOwnerOrAdmin($actor, $participant);
            $participation = $this->access->activeParticipation($project, $participant);
            abort_if($participation === null, 404);

            $now = $this->clock->now($locked);
            $participation->forceFill(['left_at' => $now, 'left_by_user_id' => $actor->id, 'active_marker' => null])->save();
            $this->end($participation, $project);
            $this->recordEnd($actor, $participation, $project, AccessAuditAction::SharedProjectLeft);

            return $participation;
        });
    }

    /** Point one of the participant's own projects at the share, or with null stop doing so. */
    public function linkLocalProject(User $actor, Workspace $participant, string $sharedProjectPublicId, ?string $localProjectPublicId): ?ClientProject
    {
        $this->requireOwnerOrAdmin($actor, $participant);
        $shared = ClientProject::query()->where('public_id', $sharedProjectPublicId)->first();
        abort_if($shared === null, 404);
        $host = Workspace::query()->whereKey($shared->workspace_id)->firstOrFail();

        return $this->locked($host, function (Workspace $locked) use ($actor, $participant, $shared, $localProjectPublicId): ?ClientProject {
            $this->requireOwnerOrAdmin($actor, $participant);
            $participation = $this->access->activeParticipation($shared, $participant);
            abort_if($participation === null, 404);

            // Only one local project stands for a participation at a time.
            $previous = ClientProject::query()->where('workspace_id', $participant->id)->where('shared_participation_id', $participation->id)->first();
            $local = null;
            if ($localProjectPublicId !== null) {
                $local = ClientProject::query()->where('workspace_id', $participant->id)->where('public_id', $localProjectPublicId)->first();
                if ($local === null) {
                    throw ValidationException::withMessages(['local_project_id' => 'Choose one of your own projects.']);
                }
                if ($local->shared_participation_id !== null && (int) $local->shared_participation_id !== (int) $participation->id) {
                    throw ValidationException::withMessages(['local_project_id' => 'That project is already linked to another shared project.']);
                }
            }
            if ($previous !== null && ($local === null || (int) $previous->id !== (int) $local->id)) {
                $previous->forceFill(['shared_participation_id' => null])->save();
            }
            $context = AccessMutationContext::user($actor);
            if ($local === null) {
                if ($previous !== null) {
                    $this->audit->record($context, $participant, AccessAuditAction::SharedProjectUnlinked, null, ['project' => $shared->public_id], null);
                }

                return null;
            }
            if ((int) $local->shared_participation_id !== (int) $participation->id) {
                $local->forceFill(['shared_participation_id' => $participation->id])->save();
                $this->audit->record($context, $participant, AccessAuditAction::SharedProjectLinked, null, null, ['project' => $shared->public_id, 'local_project' => $local->public_id]);
            }

            return $local;
        });
    }

    /** The participant-side project linked to this participation, if any. */
    public function localProject(ClientProjectParticipation $participation): ?ClientProject
    {
        return ClientProject::query()
            ->where('workspace_id', $participation->participant_workspace_id)
            ->where('shared_participation_id', $participation->id)
            ->first();
    }

    private function end(ClientProjectParticipation $participation, ClientProject $project): void
    {
        ClientProject::query()
            ->where('workspace_id', $participation->participant_workspace_id)
            ->where('shared_participation_id', $participation->id)
            ->update(['shared_participation_id' => null]);

        // Their hands come off the host's tasks; the tasks are untouched.
        ClientTaskAssignment::query()
            ->where('workspace_id', $project->workspace_id)
            ->where('assignee_workspace_id', $participation->participant_workspace_id)
            ->whereIn('client_task_id', ClientTask::query()->where('workspace_id', $project->workspace_id)
                ->where('client_project_id', $project->id)->select('id'))
            ->delete();
    }

    private function recordEnd(User $actor, ClientProjectParticipation $participation, ClientProject $project, AccessAuditAction $action): void
    {
        $context = AccessMutationContext::user($actor);
        $state = ['participation' => $participation->public_id, 'project' => $project->public_id];
        $host = Workspace::query()->whereKey($participation->host_workspace_id)->firstOrFail();
        $participant = Workspace::query()->whereKey($participation->participant_workspace_id)->firstOrFail();
        $this->audit->record($context, $host, $action, $actor, $state, null);
        $this->audit->record($context, $participant, $action, $actor, $state, null);
    }

    private function requireOpen(ClientProjectParticipationInvitation $invitation, CarbonImmutable $now): void
    {
        abort_unless($invitation->state($now) === InvitationState::Pending, 404);
    }

    /** The viewer must be the host's owner or admin, standing at a project of the host. */
    private function hostManager(User $actor, Workspace $host, string $projectPublicId): SharedProjectContext
    {
        $context = $this->access->resolve($actor, $host, $projectPublicId);
        abort_if($context === null || ! $context->isHost() || ! $context->viewer->canViewProject(), 404);
        abort_unless($context->viewer->canManageParticipants(), 403);

        return $context;
    }

    private function requireOwnerOrAdmin(User $actor, Workspace $workspace): void
    {
        $role = StaffRole::tryFrom((string) $this->roles->workspaceRole($actor, $workspace));
        abort_if($role === null, 404);
        abort_unless($role->managesWorkspace(), 403);
    }

    /**
     * @template TResult
     *
     * @param  \Closure(Workspace): TResult  $work
     * @return TResult
     */
    private function locked(Workspace $host, \Closure $work): mixed
    {
        return DB::transaction(function () use ($host, $work): mixed {
            $locked = Workspace::query()->whereKey($host->id)->tap(Locks::forUpdate())->firstOrFail();

            return $work($locked);
        });
    }
}
