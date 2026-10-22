<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_contact_id'])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'client_contact_id'])]
final class ClientTaskContactResponsibility extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
