<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $name
 * @property string $slug
 * @property string|null $billing_email
 * @property bool $is_active
 */
#[Fillable(['workspace_id', 'name', 'slug', 'billing_email', 'is_active'])]
#[Hidden(['id', 'workspace_id'])]
class ClientCompany extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsToMany<User, $this, ClientCompanyMembership, 'pivot'> */
    public function portalUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_company_memberships')
            ->using(ClientCompanyMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<ClientProject, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(ClientProject::class);
    }
}
