<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Services\Access\PortalInvitations;
use App\Support\Access\PortalAccessScope;
use App\Support\Access\PortalInvitationNotApplied;
use App\Support\Access\PortalInvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Portal access promised to an address with no account here yet, applied at
 * that person's first sign-in. Written only by {@see PortalInvitations}.
 *
 * Nothing is fillable: every write names its columns through `forceFill`, so
 * no request payload can reach `link`, `status` or the tenant keys.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property string $email
 * @property string|null $pending_email
 * @property string $access_scope
 * @property list<int> $project_ids
 * @property int|null $inviter_user_id
 * @property int|null $provider_invitation_id
 * @property string|null $link
 * @property bool $email_sent
 * @property Carbon|null $expires_at
 * @property string $status
 * @property string|null $not_applied_reason
 * @property int|null $accepted_user_id
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Hidden(['id', 'workspace_id', 'client_company_id', 'inviter_user_id', 'accepted_user_id', 'provider_invitation_id', 'link', 'pending_email'])]
class ClientPortalInvitation extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected $table = 'client_portal_invitations';

    protected function casts(): array
    {
        return [
            'project_ids' => 'array',
            'link' => 'encrypted',
            'email_sent' => 'boolean',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
            'provider_invitation_id' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inviter_user_id');
    }

    public function scope(): PortalAccessScope
    {
        return $this->access_scope === PortalAccessScope::Projects->value ? PortalAccessScope::Projects : PortalAccessScope::Company;
    }

    /** @return list<int> */
    public function projectIds(): array
    {
        return array_map(intval(...), $this->project_ids);
    }

    /** The status as reported now: pending past its expiry reads as expired. */
    public function currentStatus(?\DateTimeInterface $now = null): PortalInvitationStatus
    {
        return PortalInvitationStatus::of($this->status, $this->expires_at, $now ?? Carbon::now());
    }

    public function notAppliedReason(): ?PortalInvitationNotApplied
    {
        return $this->not_applied_reason === null ? null : PortalInvitationNotApplied::tryFrom($this->not_applied_reason);
    }

    /** Finish the invitation: no longer pending, and its link forgotten. Not saved. */
    public function finish(PortalInvitationStatus $status, \DateTimeInterface $at): void
    {
        $this->forceFill([
            'status' => $status->value,
            'pending_email' => null,
            'link' => null,
            'resolved_at' => $at,
        ]);
    }

    /** The lower-cased form every invitation stores and every lookup compares. */
    public static function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
