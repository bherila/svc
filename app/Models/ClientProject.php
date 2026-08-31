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
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `lock_version` is maintained by {@see IncrementsAgentRevision} and never
 * written directly. It is declared below because it is now read outside the
 * Agent API - the Manage form round-trips it so a save composed against stale
 * values can be refused - and an undeclared property is one PHPStan cannot
 * check the comparison of.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property string $name
 * @property string|null $description
 * @property string $status
 * @property bool $is_visible_to_client
 * @property int $lock_version
 */
#[Fillable(['workspace_id', 'client_company_id', 'name', 'description', 'status', 'is_visible_to_client'])]
#[Hidden(['id', 'workspace_id', 'client_company_id'])]
class ClientProject extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return ['is_visible_to_client' => 'boolean'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return HasMany<ClientTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ClientTask::class);
    }

    /** @return BelongsToMany<User, $this, ClientProjectMembership, 'pivot'> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_project_memberships')
            ->using(ClientProjectMembership::class)
            ->withPivot(['public_id', 'workspace_id', 'role'])
            ->withTimestamps();
    }
}
