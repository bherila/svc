<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'name', 'name_key', 'is_archived'])]
#[Hidden(['id', 'workspace_id', 'name_key', 'lock_version'])]
final class ClientTaskLabel extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return ['is_archived' => 'boolean', 'lock_version' => 'integer'];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
