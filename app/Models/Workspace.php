<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 */
#[Fillable(['name', 'slug', 'timezone', 'default_currency'])]
class Workspace extends Model
{
    use HasPublicId;

    /** @return HasMany<WorkspaceMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class);
    }

    /** @return BelongsToMany<User, $this, WorkspaceMembership, 'pivot'> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships')
            ->using(WorkspaceMembership::class)
            ->withPivot(['public_id', 'role'])
            ->withTimestamps();
    }

    /** @return HasMany<ClientCompany, $this> */
    public function clientCompanies(): HasMany
    {
        return $this->hasMany(ClientCompany::class);
    }

    /** @return HasMany<ClientProjectMembership, $this> */
    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ClientProjectMembership::class);
    }

    /** @return HasMany<PaymentReconciliation, $this> */
    public function paymentReconciliations(): HasMany
    {
        return $this->hasMany(PaymentReconciliation::class);
    }
}
