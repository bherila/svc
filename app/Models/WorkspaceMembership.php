<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $role
 */
#[Fillable(['public_id', 'workspace_id', 'user_id', 'role'])]
#[Hidden(['id', 'workspace_id', 'user_id'])]
class WorkspaceMembership extends Pivot
{
    use HasPublicId;

    public $incrementing = true;

    protected $table = 'workspace_memberships';

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
