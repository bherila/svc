<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Services\Access\AccessAuditLog;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One workspace access change, written by {@see AccessAuditLog} in the same
 * transaction as the change itself. Append-only: nothing updates a row.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $actor_type
 * @property int|null $actor_user_id
 * @property int|null $subject_user_id
 * @property string $action
 * @property string|null $client_company_public_id
 * @property array<string, mixed>|null $before_state
 * @property array<string, mixed>|null $after_state
 * @property string|null $operation_id
 * @property string|null $request_id
 * @property Carbon $created_at
 */
#[Fillable([
    'workspace_id', 'actor_type', 'actor_user_id', 'subject_user_id', 'action',
    'client_company_public_id', 'before_state', 'after_state', 'operation_id', 'request_id', 'created_at',
])]
#[Hidden(['id', 'workspace_id', 'actor_user_id', 'subject_user_id'])]
class AccessAuditEvent extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    public const UPDATED_AT = null;

    public const ACTOR_USER = 'user';

    public const ACTOR_SYSTEM = 'system';

    protected function casts(): array
    {
        return ['before_state' => 'array', 'after_state' => 'array', 'created_at' => 'datetime'];
    }
}
