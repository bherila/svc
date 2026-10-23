<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/** Project organization mutex; its revision survives application rollback.
 * @property int $id
 * @property int $workspace_id
 * @property int $client_project_id
 * @property int $lock_version
 */
#[Fillable(['workspace_id', 'client_project_id'])]
#[Hidden(['id', 'workspace_id', 'client_project_id'])]
final class ClientTaskGraphState extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function casts(): array
    {
        return ['lock_version' => 'integer'];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
