<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\SharedProjects\InvitationState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host's invitation to an address to take part in a project. It grants
 * nothing: access exists only once acceptance writes a participation.
 *
 * The invitation's public id is the bearer of the accept link, so it is read
 * only together with a signed-in person whose address matches `email`.
 *
 * @property int $id
 * @property string $public_id
 * @property int $host_workspace_id
 * @property int $client_project_id
 * @property string $email
 * @property int|null $invited_by_user_id
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $declined_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $resolved_by_user_id
 * @property CarbonImmutable $created_at
 */
#[Hidden(['id', 'host_workspace_id', 'client_project_id', 'invited_by_user_id', 'resolved_by_user_id'])]
class ClientProjectParticipationInvitation extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function state(CarbonImmutable $now): InvitationState
    {
        return InvitationState::of($this->accepted_at, $this->declined_at, $this->revoked_at, $this->expires_at, $now);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function hostWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'host_workspace_id');
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }
}
