<?php

namespace App\Services\Access;

use App\Exceptions\AccessMutationRefused;
use App\Exceptions\PortalInvitationFailed;
use App\Models\ClientCompany;
use App\Models\ClientPortalInvitation;
use App\Models\ClientProject;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\AccessRefusal;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalInvitationStatus;
use App\Support\Access\StaffAuthority;
use App\Support\Concurrency\Locks;
use App\Support\WorkspaceClock;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationConflict;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationError;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationException;
use BWH\Auth\OAuth\Invitations\ApplicationInvitationNotFound;
use BWH\Auth\OAuth\Invitations\ApplicationInvitations;
use BWH\Auth\OAuth\Invitations\IssuedInvitation;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Portal access for someone who has never signed in here (#430).
 *
 * The identity provider has no open sign-up, so a manager's invitation is two
 * promises: the provider's, to admit the address to this application only,
 * and this application's, to give that person the invited portal access when
 * they first sign in with a matching address.
 *
 * ## Order of a change
 *
 * Each operation refuses early from an unlocked read, asks the provider with
 * no lock held - a network call never sits inside the workspace access lock -
 * and then records the result under that lock, deciding again from what it
 * reads there. If the decision under the lock refuses, a provider invitation
 * just issued is withdrawn again, so a refused invitation never admits anyone.
 *
 * Locks, in `LockResource` order: the workspace, the invited projects, the
 * actor's staff membership, then the invitation rows.
 *
 * ## The link
 *
 * The provider's acceptance link is a bearer credential. It is kept encrypted
 * while the invitation is pending so a manager can copy it from the client's
 * settings page, cleared the moment the invitation is accepted, not applied
 * or revoked, and never returned to agents.
 *
 * Applying an invitation at sign-in is {@see WorkspaceAccess::applyPortalInvitation()}.
 */
final class PortalInvitations
{
    public function __construct(
        private readonly AccessCoordinator $coordinator,
        private readonly StaffAuthority $authority,
        private readonly AccessAuditLog $audit,
        private readonly WorkspaceAccess $access,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('svc.portal_invitations.enabled', false);
    }

    /**
     * Invite an address with no account here to a client's portal.
     *
     * A newer invitation for the same client and address supersedes the
     * pending one, which is marked revoked; the provider supersedes its own.
     *
     * @param  list<string>  $projectPublicIds  the projects a `projects` scope is limited to; empty for `company`
     */
    public function invite(AccessMutationContext $context, ClientCompany $company, string $email, PortalAccessScope $scope, array $projectPublicIds = []): ClientPortalInvitation
    {
        $email = ClientPortalInvitation::normalize($email);
        $projectPublicIds = array_values(array_unique($projectPublicIds));
        $workspace = Workspace::query()->whereKey($company->workspace_id)->firstOrFail();

        $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $this->coordinator->staff($workspace, [$context->actorId()], lock: false))));
        $subject = $this->subject($context);
        $this->refuseIf($this->addressRefusal($email));
        $this->projects($workspace, $company, $scope, $projectPublicIds, lock: false);

        $issued = $this->ask(fn (ApplicationInvitations $provider): IssuedInvitation => $provider->create($subject, $email));

        return $this->recordOrWithdraw($subject, $issued, fn (): ClientPortalInvitation => $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $company, $email, $scope, $projectPublicIds, $issued): ClientPortalInvitation {
            $stored = ClientCompany::query()->where('workspace_id', $locked->id)->whereKey($company->id)->firstOrFail();
            $projects = $this->projects($locked, $stored, $scope, $projectPublicIds, lock: true);
            $this->authorizeLocked($context, $locked);
            $this->refuseIf($this->addressRefusal($email));
            $this->refuseIf($scope === PortalAccessScope::Projects && $projects->count() !== count($projectPublicIds) ? AccessRefusal::ProjectOutsideCompany : null);

            $invitation = new ClientPortalInvitation;
            $invitation->forceFill([
                'public_id' => (string) Str::uuid(),
                'workspace_id' => $locked->id,
                'client_company_id' => $stored->id,
                'email' => $email,
                'access_scope' => $scope->value,
                'project_ids' => array_values($projects->keys()->map(intval(...))->all()),
                'inviter_user_id' => $context->actorId(),
            ]);
            $superseded = $this->supersede($context, $locked, $stored, $email, $invitation->public_id);
            $this->issue($invitation, $issued);
            $invitation->save();

            $this->audit->record($context, $locked, AccessAuditAction::PortalInvited, null, $superseded === null ? null : ['invitation' => $superseded], [
                'invitation' => $invitation->public_id,
                'scope' => $scope->value,
                'projects' => array_values($projects->sortBy('public_id')->pluck('public_id')->map(strval(...))->all()),
            ], $stored->public_id);

            return $invitation;
        }));
    }

    /**
     * Send a pending or expired invitation again, with a new link and expiry.
     *
     * When the provider no longer holds a pending invitation for it - another
     * invitation to the same address superseded it there - a new one is issued
     * in its place.
     */
    public function resend(AccessMutationContext $context, ClientCompany $company, ClientPortalInvitation $invitation): ClientPortalInvitation
    {
        $workspace = Workspace::query()->whereKey($company->workspace_id)->firstOrFail();
        $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $this->coordinator->staff($workspace, [$context->actorId()], lock: false))));
        $this->refuseIf($invitation->currentStatus()->isOpen() ? null : AccessRefusal::InvitationNotOpen);
        $subject = $this->subject($context);

        $issued = $this->ask(function (ApplicationInvitations $provider) use ($subject, $invitation): IssuedInvitation {
            if ($invitation->provider_invitation_id === null) {
                return $provider->create($subject, $invitation->email);
            }
            try {
                return $provider->resend($subject, $invitation->provider_invitation_id);
            } catch (ApplicationInvitationNotFound|ApplicationInvitationConflict $gone) {
                if ($gone instanceof ApplicationInvitationConflict && $gone->error !== ApplicationInvitationError::InvitationNotPending) {
                    throw $gone;
                }

                return $provider->create($subject, $invitation->email);
            }
        });

        return $this->recordOrWithdraw($subject, $issued, fn (): ClientPortalInvitation => $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $company, $invitation, $issued): ClientPortalInvitation {
            $this->authorizeLocked($context, $locked);
            $current = $this->lockedInvitation($locked, $company, $invitation);
            $this->refuseIf(PortalInvitationStatus::from($current->status)->isOpen() ? null : AccessRefusal::InvitationNotOpen);

            $this->supersede($context, $locked, $company, $current->email, $current->public_id, except: $current);
            $this->issue($current, $issued);
            $current->forceFill(['resolved_at' => null, 'inviter_user_id' => $context->actorId()])->save();
            $this->audit->record($context, $locked, AccessAuditAction::PortalInvitationResent, null, null, ['invitation' => $current->public_id], $company->public_id);

            return $current;
        }));
    }

    /**
     * Withdraw an invitation, or dismiss one that was not applied.
     *
     * A pending or expired invitation is revoked at the provider first, so its
     * link stops admitting anyone; if the provider cannot be reached nothing
     * changes here. One the person has already accepted at the provider is
     * still revoked here, so signing in gives them nothing.
     */
    public function revoke(AccessMutationContext $context, ClientCompany $company, ClientPortalInvitation $invitation): ClientPortalInvitation
    {
        $workspace = Workspace::query()->whereKey($company->workspace_id)->firstOrFail();
        $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $this->coordinator->staff($workspace, [$context->actorId()], lock: false))));
        $status = PortalInvitationStatus::from($invitation->status);
        $this->refuseIf(in_array($status, [PortalInvitationStatus::Accepted, PortalInvitationStatus::Revoked], true) ? AccessRefusal::InvitationNotOpen : null);

        if ($status->isOpen() && $invitation->provider_invitation_id !== null) {
            $subject = $this->subject($context);
            $this->ask(function (ApplicationInvitations $provider) use ($subject, $invitation): void {
                try {
                    $provider->revoke($subject, (int) $invitation->provider_invitation_id);
                } catch (ApplicationInvitationNotFound) {
                    // Nothing left to admit with.
                } catch (ApplicationInvitationConflict $accepted) {
                    // Accepted at the provider: the person can sign in, but is given nothing here.
                    if ($accepted->error !== ApplicationInvitationError::InvitationAccepted) {
                        throw $accepted;
                    }
                }
            });
        }

        return $this->coordinator->locked($workspace, function (Workspace $locked) use ($context, $company, $invitation): ClientPortalInvitation {
            $this->authorizeLocked($context, $locked);
            $current = $this->lockedInvitation($locked, $company, $invitation);
            $before = PortalInvitationStatus::from($current->status);
            $this->refuseIf(in_array($before, [PortalInvitationStatus::Accepted, PortalInvitationStatus::Revoked], true) ? AccessRefusal::InvitationNotOpen : null);

            $current->finish(PortalInvitationStatus::Revoked, $this->clock->now());
            $current->save();
            $this->audit->record($context, $locked, AccessAuditAction::PortalInvitationRevoked, null, ['invitation' => $current->public_id, 'status' => $before->value], ['invitation' => $current->public_id, 'status' => PortalInvitationStatus::Revoked->value], $company->public_id);

            return $current;
        });
    }

    /**
     * Apply every pending invitation for this person's address, at sign-in.
     *
     * Invitations from any workspace apply: the address is what was invited.
     * Each is applied in its own workspace's access transaction; one that
     * fails is logged and stays pending for the next sign-in, and never stops
     * the sign-in itself.
     */
    public function applyAtSignIn(User $user): void
    {
        if (! self::enabled() || trim($user->email) === '') {
            return;
        }

        // Deliberately across workspaces: an invitation is to an address, and
        // this is the person who proved it at the provider.
        $invitations = ClientPortalInvitation::query()
            ->where('email', ClientPortalInvitation::normalize($user->email))
            ->where('status', PortalInvitationStatus::Pending->value)
            ->orderBy('id')
            ->lazyById(100);

        foreach ($invitations as $invitation) {
            try {
                $this->access->applyPortalInvitation($invitation, $user);
            } catch (Throwable $failure) {
                Log::warning('A portal invitation could not be applied at sign-in; it stays pending.', [
                    'invitation' => $invitation->public_id,
                    'exception' => $failure::class,
                ]);
            }
        }
    }

    /**
     * The provider subject to ask as. Only a person bound to the provider can
     * be named in an actor assertion; the system cannot invite.
     */
    private function subject(AccessMutationContext $context): string
    {
        $subject = (string) $context->actor?->oauth_subject;
        $bound = $context->actor instanceof User
            && $context->actor->oauth_provider === (string) config('bherila-auth.oauth_client.provider')
            && preg_match('/^[1-9][0-9]{0,19}$/D', $subject) === 1;

        $this->refuseIf($bound ? null : AccessRefusal::InviterNotBound);

        return $subject;
    }

    /**
     * Why this address cannot be invited, if a local account already holds it.
     *
     * Bound to the configured provider: that person already signs in, and is
     * added directly. Bound to nothing, or to another provider: sign-in
     * refuses an address owned by a different local account, so an invitation
     * could never be applied.
     */
    private function addressRefusal(string $email): ?AccessRefusal
    {
        $owner = User::query()->whereRaw('LOWER(email) = ?', [$email])->first(['id', 'oauth_provider', 'oauth_subject']);
        if (! $owner instanceof User) {
            return null;
        }
        $provider = (string) config('bherila-auth.oauth_client.provider');

        return $provider !== '' && $owner->oauth_provider === $provider && (string) $owner->oauth_subject !== ''
            ? AccessRefusal::AccountExists
            : AccessRefusal::AddressInUse;
    }

    /**
     * The invited projects, keyed by id, and refused unless the scope agrees
     * with them. Under the lock they are locked rows, in id order, and only
     * this company's.
     *
     * @param  list<string>  $publicIds
     * @return Collection<int, ClientProject>
     */
    private function projects(Workspace $workspace, ClientCompany $company, PortalAccessScope $scope, array $publicIds, bool $lock): Collection
    {
        if ($scope === PortalAccessScope::Company) {
            $this->refuseIf($publicIds === [] ? null : AccessRefusal::ProjectsWithCompanyScope);

            return new Collection;
        }

        $projects = ClientProject::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereIn('public_id', $publicIds)
            ->orderBy('id')
            ->when($lock, static fn (Builder $query): Builder => $query->tap(Locks::forUpdate()))
            ->get()
            ->keyBy('id');

        // Under the lock the count is checked after authority, so a refused
        // actor learns nothing about which projects exist.
        if (! $lock) {
            $this->refuseIf($publicIds !== [] && $projects->count() === count($publicIds) ? null : AccessRefusal::ProjectOutsideCompany);
        }

        return $projects;
    }

    private function authorizeLocked(AccessMutationContext $context, Workspace $locked): void
    {
        $staff = $this->coordinator->staff($locked, [$context->actorId()], lock: true);
        $this->refuseIf($this->authority->manage($context, $this->coordinator->actorRole($context, $staff)));
    }

    private function lockedInvitation(Workspace $workspace, ClientCompany $company, ClientPortalInvitation $invitation): ClientPortalInvitation
    {
        return ClientPortalInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->whereKey($invitation->id)
            ->tap(Locks::forUpdate())
            ->firstOrFail();
    }

    /**
     * Revoke the client's other pending invitation to this address, if any,
     * and return its public id.
     */
    private function supersede(AccessMutationContext $context, Workspace $workspace, ClientCompany $company, string $email, string $by, ?ClientPortalInvitation $except = null): ?string
    {
        $previous = ClientPortalInvitation::query()
            ->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)
            ->where('pending_email', $email)
            ->when($except !== null, static fn (Builder $query): Builder => $query->whereKeyNot($except?->id))
            ->tap(Locks::forUpdate())
            ->first();

        if (! $previous instanceof ClientPortalInvitation) {
            return null;
        }

        $previous->finish(PortalInvitationStatus::Revoked, $this->clock->now());
        $previous->save();
        $this->audit->record($context, $workspace, AccessAuditAction::PortalInvitationRevoked, null,
            ['invitation' => $previous->public_id, 'status' => PortalInvitationStatus::Pending->value],
            ['invitation' => $previous->public_id, 'status' => PortalInvitationStatus::Revoked->value, 'superseded_by' => $by],
            $company->public_id);

        return $previous->public_id;
    }

    /** Make the invitation pending on what the provider just issued. */
    private function issue(ClientPortalInvitation $invitation, IssuedInvitation $issued): void
    {
        $invitation->forceFill([
            'status' => PortalInvitationStatus::Pending->value,
            'pending_email' => $invitation->email,
            'not_applied_reason' => null,
            'provider_invitation_id' => $issued->id,
            'link' => $issued->link(),
            'email_sent' => $issued->emailSent,
            'expires_at' => CarbonImmutable::instance($issued->expiresAt)->utc(),
        ]);
    }

    /**
     * Ask the provider, turning its refusals into one exception callers map.
     *
     * @template TResult
     *
     * @param  Closure(ApplicationInvitations): TResult  $call
     * @return TResult
     */
    private function ask(Closure $call): mixed
    {
        try {
            return $call(app(ApplicationInvitations::class));
        } catch (ApplicationInvitationException $failure) {
            if (in_array($failure->error, [ApplicationInvitationError::NotConfigured, ApplicationInvitationError::TokenRefused, ApplicationInvitationError::InvalidToken,
                ApplicationInvitationError::InsufficientScope, ApplicationInvitationError::ApplicationNotEnabled, ApplicationInvitationError::InvalidActorAssertion,
                ApplicationInvitationError::ReplayedActorAssertion, ApplicationInvitationError::InvalidConfiguration], true)) {
                Log::warning('Portal invitations are misconfigured at the identity provider.', ['error' => $failure->error->value]);
            }

            throw PortalInvitationFailed::from($failure);
        }
    }

    /**
     * Record what the provider issued, or withdraw it there if recording is
     * refused or fails, so nothing this application refused can admit anyone.
     *
     * A caller's enclosing transaction - an agent receipt's - can still roll
     * back after the record is written: its response snapshot, receipt or
     * commit can fail. The withdrawal is registered on that transaction too,
     * and runs once whichever way the record is lost. With no enclosing
     * transaction the registration does nothing and the record commits
     * before this returns.
     *
     * @param  Closure(): ClientPortalInvitation  $record
     */
    private function recordOrWithdraw(string $subject, IssuedInvitation $issued, Closure $record): ClientPortalInvitation
    {
        $withdrawn = false;
        $withdraw = static function () use (&$withdrawn, $subject, $issued): void {
            if ($withdrawn) {
                return;
            }
            $withdrawn = true;
            try {
                app(ApplicationInvitations::class)->revoke($subject, $issued->id);
            } catch (Throwable) {
                Log::warning('A provider invitation that was not recorded here could not be withdrawn; it lapses on its own.', ['provider_invitation_id' => $issued->id]);
            }
        };
        DB::afterRollBack($withdraw);

        try {
            return $record();
        } catch (Throwable $failure) {
            $withdraw();

            throw $failure;
        }
    }

    private function refuseIf(?AccessRefusal $refusal): void
    {
        if ($refusal !== null) {
            throw new AccessMutationRefused($refusal);
        }
    }
}
