<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A business contact, independent of authenticating users and access grants. */
#[Fillable(['workspace_id', 'client_company_id', 'first_name', 'last_name', 'title', 'email', 'phone', 'notes', 'is_archived'])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'email', 'phone', 'notes', 'lock_version'])]
class ClientContact extends Model implements WorkspaceOwned
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

    /** @return BelongsTo<ClientCompany, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class, 'client_company_id');
    }
}
