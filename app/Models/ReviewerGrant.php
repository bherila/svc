<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\Review\GranteeKind;
use App\Support\Review\ReviewerGrantee;
use App\Support\Review\ReviewerGrantScope;
use App\Support\Review\ReviewerGrantState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider workspace's explicit, revocable permission for someone outside it
 * to review one of its clients' hours and expenses.
 *
 * It belongs to the granting workspace but is read from the grantee's side, so
 * it has no `workspace_id` and does not use BelongsToWorkspace: it is reached
 * only through ReviewerGrants, and written only by it under the granter's
 * workspace lock. Nothing is fillable; every write names its columns through
 * forceFill.
 *
 * @property int $id
 * @property string $public_id
 * @property int $granter_workspace_id
 * @property int $client_company_id
 * @property int|null $client_project_id
 * @property int $project_key
 * @property string $grantee_kind
 * @property string $grantee_key
 * @property string|null $reviewer_email
 * @property int|null $reviewer_workspace_id
 * @property string|null $reviewer_label
 * @property int|null $granted_by_user_id
 * @property CarbonImmutable $granted_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property int|null $active_marker
 */
#[Hidden(['id', 'granter_workspace_id', 'client_company_id', 'client_project_id', 'project_key', 'grantee_key', 'reviewer_email', 'reviewer_workspace_id', 'granted_by_user_id', 'revoked_by_user_id', 'active_marker'])]
class ReviewerGrant extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function state(): ReviewerGrantState
    {
        return ReviewerGrantState::of($this->revoked_at);
    }

    public function grantee(): ReviewerGrantee
    {
        return ReviewerGrantee::from($this->reviewer_email, $this->reviewer_workspace_id);
    }

    public function kind(): GranteeKind
    {
        return GranteeKind::from($this->grantee_kind);
    }

    /** @return list<int>|null the projects it reaches; null is the whole client */
    public function projectScope(): ?array
    {
        return ReviewerGrantScope::projectIds($this->client_project_id);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function granterWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'granter_workspace_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function reviewerWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'reviewer_workspace_id');
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
