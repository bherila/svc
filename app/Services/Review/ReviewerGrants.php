<?php

namespace App\Services\Review;

use App\Models\ClientCompany;
use App\Models\ClientCompanyMembership;
use App\Models\ClientContact;
use App\Models\ClientProject;
use App\Models\ClientProjectParticipation;
use App\Models\ReviewerGrant;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\Access\AccessAuditLog;
use App\Services\Authorization\ProjectAccess;
use App\Support\Access\AccessAuditAction;
use App\Support\Access\AccessMutationContext;
use App\Support\Access\StaffRole;
use App\Support\Concurrency\Locks;
use App\Support\Review\GranteeKind;
use App\Support\Review\ReviewerEligibility;
use App\Support\Review\ReviewerGrantee;
use App\Support\Review\ReviewerGrantRules;
use App\Support\Review\ReviewerGrantScope;
use App\Support\SharedProjects\InviteeAddress;
use App\Support\WorkspaceClock;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reviewer grants: giving, revoking, and finding the ones that reach a person
 * (docs/ux-overhaul.md section 7d). Browser-only by decision: letting someone
 * outside the business vouch for its hours is a person's act, so nothing here is
 * a REST or MCP operation.
 *
 * A grant is the whole permission. Without one nothing crosses a workspace
 * boundary, and a grant never reaches further than the client (and optionally
 * the one project) it names.
 *
 * Every change runs in one transaction under the granting workspace's row lock,
 * the same row the shared-project and access writers serialise on, and decides
 * again from what it reads there. It is written to the access audit in the
 * granting workspace. Which grants reach a person is read live, from the facts,
 * on every request ({@see ReviewerEligibility}); nothing about a grant is cached,
 * so revoking stops the reviewer on their very next request.
 */
final class ReviewerGrants
{
    /** The most grants one person is shown at once; the queue is bounded by it. */
    public const int LIMIT = 50;

    public function __construct(
        private readonly ProjectAccess $roles,
        private readonly AccessAuditLog $audit,
        private readonly WorkspaceClock $clock = new WorkspaceClock,
    ) {}

    /**
     * @param  GranteeKind  $kind  who the grant is written to
     * @param  string  $target  the client contact's public id (a contact grant) or the workspace's (a workspace grant)
     */
    public function grant(User $actor, Workspace $workspace, string $companyPublicId, ?string $projectPublicId, GranteeKind $kind, string $target): ReviewerGrant
    {
        $this->requireManager($actor, $workspace);

        return $this->locked($workspace, function (Workspace $locked) use ($actor, $companyPublicId, $projectPublicId, $kind, $target): ReviewerGrant {
            $this->requireManager($actor, $locked);
            $company = ClientCompany::query()->where('workspace_id', $locked->id)->where('public_id', $companyPublicId)->first();
            abort_if($company === null, 404);

            $project = null;
            if ($projectPublicId !== null) {
                $project = ClientProject::query()->where('workspace_id', $locked->id)->where('client_company_id', $company->id)->where('public_id', $projectPublicId)->first();
                if ($project === null) {
                    throw ValidationException::withMessages(['project_id' => 'Choose one of this client\'s projects, or all of them.']);
                }
            }

            [$grantee, $label, $field] = $this->grantee($locked, $company, $kind, $target);
            $refusal = ReviewerGrantRules::refusal(
                $grantee,
                $locked->id,
                $grantee->email !== null && $this->isStaff($locked, $grantee->email),
                $grantee->workspaceId !== null && $this->sharesProject($locked->id, $grantee->workspaceId),
                $grantee->email !== null && $this->holdsPortalAccess($locked, $company, $grantee->email),
            );
            if ($refusal !== null) {
                throw ValidationException::withMessages([$field => $refusal->message()]);
            }

            $existing = ReviewerGrant::query()
                ->where('granter_workspace_id', $locked->id)->where('client_company_id', $company->id)
                ->where('project_key', ReviewerGrantScope::projectKey($project?->id))
                ->where('grantee_key', $grantee->key())->whereNotNull('active_marker')->exists();
            if ($existing) {
                throw ValidationException::withMessages([$field => 'That grant already exists.']);
            }

            $grant = new ReviewerGrant;
            $grant->forceFill([
                'granter_workspace_id' => $locked->id,
                'client_company_id' => $company->id,
                'client_project_id' => $project?->id,
                'project_key' => ReviewerGrantScope::projectKey($project?->id),
                'grantee_kind' => $grantee->kind->value,
                'grantee_key' => $grantee->key(),
                'reviewer_email' => $grantee->email,
                'reviewer_workspace_id' => $grantee->workspaceId,
                'reviewer_label' => $label,
                'granted_by_user_id' => $actor->id,
                'granted_at' => $this->clock->instant(),
                'active_marker' => 1,
            ])->save();

            $this->audit->record(AccessMutationContext::user($actor), $locked, AccessAuditAction::ReviewerGranted, null, null,
                ['grant' => $grant->public_id, 'kind' => $grantee->kind->value, 'project' => $project?->public_id], $company->public_id);

            return $grant;
        });
    }

    public function revoke(User $actor, Workspace $workspace, string $grantPublicId): ReviewerGrant
    {
        $this->requireManager($actor, $workspace);

        return $this->locked($workspace, function (Workspace $locked) use ($actor, $grantPublicId): ReviewerGrant {
            $this->requireManager($actor, $locked);
            $grant = ReviewerGrant::query()->where('granter_workspace_id', $locked->id)->where('public_id', $grantPublicId)->first();
            abort_if($grant === null || ! $grant->state()->reaches(), 404);

            $grant->forceFill(['revoked_at' => $this->clock->instant(), 'revoked_by_user_id' => $actor->id, 'active_marker' => null])->save();
            $company = ClientCompany::query()->where('workspace_id', $locked->id)->whereKey($grant->client_company_id)->first();
            $this->audit->record(AccessMutationContext::user($actor), $locked, AccessAuditAction::ReviewerRevoked, null,
                ['grant' => $grant->public_id, 'kind' => $grant->grantee_kind], null, $company?->public_id);

            return $grant;
        });
    }

    /**
     * The grants that reach this person right now, oldest first and bounded.
     *
     * One query for the candidates, then three small lookups of the person's
     * own memberships; the decision for each is {@see ReviewerEligibility}.
     *
     * @return Collection<int, ReviewerGrant>
     */
    public function reaching(User $viewer): Collection
    {
        $email = InviteeAddress::normalize((string) $viewer->email);
        $managed = $this->managedWorkspaceIds($viewer);

        $candidates = ReviewerGrant::query()
            ->whereNotNull('active_marker')
            ->where(function ($query) use ($email, $managed): void {
                $query->whereIn('reviewer_workspace_id', $managed);
                if ($email !== '') {
                    $query->orWhere('reviewer_email', $email);
                }
            })
            ->with(['company', 'granterWorkspace', 'project'])
            ->orderBy('id')
            ->limit(self::LIMIT * 4)
            ->get();
        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $staffOf = WorkspaceMembership::query()->where('user_id', $viewer->id)->pluck('workspace_id')
            ->map(static fn (mixed $id): int => (int) $id)->flip();
        $portalOf = ClientCompanyMembership::query()->where('user_id', $viewer->id)->get(['workspace_id', 'client_company_id'])
            ->mapWithKeys(static fn (ClientCompanyMembership $row): array => [((int) $row->workspace_id).':'.((int) $row->client_company_id) => true]);

        return $candidates
            ->filter(fn (ReviewerGrant $grant): bool => $grant->company !== null && $grant->granterWorkspace !== null && ReviewerEligibility::reaches(
                $grant->state(),
                $grant->grantee(),
                $email,
                $managed,
                $staffOf->has((int) $grant->granter_workspace_id),
                $portalOf->has(((int) $grant->granter_workspace_id).':'.((int) $grant->client_company_id)),
            ))
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Write down that this person was shown rows through this grant, in the
     * workspace that gave it. Counts only: never what the rows said.
     */
    public function recordRead(User $viewer, ReviewerGrant $grant, int $rows): void
    {
        $workspace = $grant->granterWorkspace;
        abort_if($workspace === null, 404);

        $this->audit->record(AccessMutationContext::user($viewer), $workspace, AccessAuditAction::ReviewerRead, $viewer, null,
            ['grant' => $grant->public_id, 'rows' => $rows], $grant->company?->public_id);
    }

    /** The one grant, if it reaches this person; any other answer is "not found". */
    public function reachingOne(User $viewer, string $grantPublicId): ?ReviewerGrant
    {
        return $this->reaching($viewer)->first(static fn (ReviewerGrant $grant): bool => $grant->public_id === $grantPublicId);
    }

    /**
     * Whether a grant could reach this person: an active one is written to their
     * address or to a workspace they manage. Two cheap lookups, for the one link
     * that offers the queue. It does not apply the exclusions, so it can be yes
     * where {@see self::reaching()} is empty; the page then says nothing is waiting.
     */
    public function mayHaveAny(User $viewer): bool
    {
        $email = InviteeAddress::normalize((string) $viewer->email);
        $managed = $this->managedWorkspaceIds($viewer);

        return ReviewerGrant::query()->whereNotNull('active_marker')
            ->where(function ($query) use ($email, $managed): void {
                $query->whereIn('reviewer_workspace_id', $managed);
                if ($email !== '') {
                    $query->orWhere('reviewer_email', $email);
                }
            })->exists();
    }

    /**
     * Whether the cross-workspace queue is worth offering: a grant could reach
     * this person, or they hold a portal membership of more than one client (so
     * the single-client home is not everything). The queue itself decides what is
     * actually waiting.
     */
    public function approvalsAvailable(User $viewer): bool
    {
        return $this->mayHaveAny($viewer)
            || ClientCompanyMembership::query()->where('user_id', $viewer->id)->limit(2)->count() > 1;
    }

    /** @return list<int> */
    private function managedWorkspaceIds(User $viewer): array
    {
        $ids = WorkspaceMembership::query()->where('user_id', $viewer->id)
            ->whereIn('role', array_map(static fn (StaffRole $role): string => $role->value, array_filter(StaffRole::cases(), static fn (StaffRole $role): bool => $role->managesWorkspace())))
            ->pluck('workspace_id')->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values($ids);
    }

    /**
     * The workspaces that take part in a shared project with this one, either way round.
     *
     * @return list<int>
     */
    public function sharingWorkspaceIds(Workspace $workspace): array
    {
        $rows = ClientProjectParticipation::query()
            ->whereNotNull('active_marker')
            ->where(fn ($query) => $query->where('host_workspace_id', $workspace->id)->orWhere('participant_workspace_id', $workspace->id))
            ->limit(500)
            ->get(['host_workspace_id', 'participant_workspace_id']);

        $ids = [];
        foreach ($rows as $row) {
            $ids[(int) $row->host_workspace_id === (int) $workspace->id ? (int) $row->participant_workspace_id : (int) $row->host_workspace_id] = true;
        }

        return array_keys($ids);
    }

    private function sharesProject(int $workspaceId, int $otherId): bool
    {
        return ClientProjectParticipation::query()->whereNotNull('active_marker')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('host_workspace_id', $workspaceId)->where('participant_workspace_id', $otherId))
                ->orWhere(fn ($pair) => $pair->where('host_workspace_id', $otherId)->where('participant_workspace_id', $workspaceId)))
            ->exists();
    }

    /**
     * @return array{0: ReviewerGrantee, 1: string|null, 2: string} the grantee, a label to show, and the field a refusal belongs to
     */
    private function grantee(Workspace $workspace, ClientCompany $company, GranteeKind $kind, string $target): array
    {
        return match ($kind) {
            GranteeKind::Contact => $this->contactGrantee($workspace, $company, $target),
            GranteeKind::Workspace => $this->workspaceGrantee($target),
        };
    }

    /** @return array{0: ReviewerGrantee, 1: string|null, 2: string} */
    private function contactGrantee(Workspace $workspace, ClientCompany $company, string $target): array
    {
        $contact = ClientContact::query()->where('workspace_id', $workspace->id)->where('client_company_id', $company->id)
            ->where('public_id', $target)->where('is_archived', false)->first();
        if ($contact === null || trim((string) $contact->email) === '') {
            throw ValidationException::withMessages(['contact_id' => 'Choose one of this client\'s contacts that has an email address.']);
        }
        $label = trim($contact->first_name.' '.$contact->last_name);

        return [ReviewerGrantee::contact((string) $contact->email), $label === '' ? null : $label, 'contact_id'];
    }

    /** @return array{0: ReviewerGrantee, 1: string|null, 2: string} */
    private function workspaceGrantee(string $target): array
    {
        $other = Workspace::query()->where('public_id', $target)->first();
        // A workspace that does not exist is refused in the same words as one that
        // shares nothing, so the answer does not say which workspaces exist.
        if ($other === null) {
            throw ValidationException::withMessages(['workspace_id' => 'Choose a workspace that takes part in one of your shared projects.']);
        }

        return [ReviewerGrantee::workspace($other->id), $other->name, 'workspace_id'];
    }

    /**
     * The user ids that sign in with this address.
     *
     * @return list<int>
     */
    private function userIdsAt(string $email): array
    {
        $ids = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [InviteeAddress::normalize($email)])
            ->limit(20)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        return array_values($ids);
    }

    private function isStaff(Workspace $workspace, string $email): bool
    {
        $ids = $this->userIdsAt($email);

        return $ids !== [] && WorkspaceMembership::query()->where('workspace_id', $workspace->id)->whereIn('user_id', $ids)->exists();
    }

    private function holdsPortalAccess(Workspace $workspace, ClientCompany $company, string $email): bool
    {
        $ids = $this->userIdsAt($email);

        return $ids !== [] && ClientCompanyMembership::query()->where('workspace_id', $workspace->id)
            ->where('client_company_id', $company->id)->whereIn('user_id', $ids)->exists();
    }

    private function requireManager(User $actor, Workspace $workspace): void
    {
        $role = StaffRole::tryFrom((string) $this->roles->workspaceRole($actor, $workspace));
        abort_if($role === null, 404);
        abort_unless($role->managesWorkspace(), 403);
    }

    /**
     * @template TResult
     *
     * @param  Closure(Workspace): TResult  $work
     * @return TResult
     */
    private function locked(Workspace $workspace, Closure $work): mixed
    {
        return DB::transaction(function () use ($workspace, $work): mixed {
            $locked = Workspace::query()->whereKey($workspace->id)->tap(Locks::forUpdate())->firstOrFail();

            return $work($locked);
        });
    }
}
