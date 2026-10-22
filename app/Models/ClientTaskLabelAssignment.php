<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'client_task_id', 'client_task_label_id'])]
#[Hidden(['id', 'workspace_id', 'client_task_id', 'client_task_label_id'])]
final class ClientTaskLabelAssignment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
