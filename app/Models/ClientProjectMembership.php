<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use App\Support\AgentApi\ProjectRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** @property ProjectRole $role */
#[Fillable(['public_id', 'workspace_id', 'client_project_id', 'user_id', 'role'])]
#[Hidden(['id', 'workspace_id', 'client_project_id', 'user_id'])]
class ClientProjectMembership extends Pivot
{
    use HasPublicId;

    public $incrementing = true;

    protected $table = 'client_project_memberships';

    protected function casts(): array
    {
        return ['role' => ProjectRole::class];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
