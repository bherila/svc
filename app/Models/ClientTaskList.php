<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Retained project list; organization writers remain behind their deployment flag.
 * @property int $id
 * @property int $workspace_id
 * @property int $client_project_id
 * @property string $public_id
 * @property string $name
 * @property bool $is_visible_to_client
 * @property int $lock_version
 */
#[Fillable(['workspace_id', 'client_project_id', 'name', 'is_visible_to_client'])]
#[Hidden(['organization_position', 'id', 'workspace_id', 'client_project_id'])]
class ClientTaskList extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return ['is_visible_to_client' => 'boolean', 'lock_version' => 'integer'];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
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
}
