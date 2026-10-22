<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Assignment records responsibility, independently of all access grants. */
#[Fillable(['workspace_id', 'client_task_id', 'user_id'])]
#[Hidden(['id', 'workspace_id', 'client_task_id', 'user_id'])]
class ClientTaskAssignment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }

    /** @return BelongsTo<ClientTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ClientTask::class, 'client_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
