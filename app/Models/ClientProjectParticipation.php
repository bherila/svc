<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\SharedProjects\ParticipationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace taking part in another workspace's project.
 *
 * It belongs to two workspaces, so it has no `workspace_id` and does not use
 * BelongsToWorkspace: it is read only through SharedProjectAccess, and written
 * only by SharedProjectParticipations under the host's workspace lock. Nothing
 * is fillable; every write names its columns through forceFill.
 *
 * @property int $id
 * @property string $public_id
 * @property int $host_workspace_id
 * @property int $client_project_id
 * @property int $participant_workspace_id
 * @property int|null $invitation_id
 * @property int|null $granted_by_user_id
 * @property CarbonImmutable $granted_at
 * @property int|null $accepted_by_user_id
 * @property CarbonImmutable $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $revoked_by_user_id
 * @property CarbonImmutable|null $left_at
 * @property int|null $left_by_user_id
 * @property int|null $active_marker
 */
#[Hidden(['id', 'host_workspace_id', 'client_project_id', 'participant_workspace_id', 'invitation_id', 'granted_by_user_id', 'accepted_by_user_id', 'revoked_by_user_id', 'left_by_user_id', 'active_marker'])]
class ClientProjectParticipation extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'granted_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'left_at' => 'immutable_datetime',
        ];
    }

    public function state(): ParticipationState
    {
        return ParticipationState::of($this->revoked_at, $this->left_at);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function hostWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'host_workspace_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function participantWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'participant_workspace_id');
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
