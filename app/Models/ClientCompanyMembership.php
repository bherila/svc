<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property int $id
 * @property int $client_company_id
 * @property int $user_id
 * @property string $role
 * @property string $access_scope
 */
#[Fillable(['public_id', 'client_company_id', 'user_id', 'role', 'access_scope'])]
#[Hidden(['id', 'client_company_id', 'user_id'])]
class ClientCompanyMembership extends Pivot
{
    use HasPublicId;

    public $incrementing = true;

    protected $table = 'client_company_memberships';

    /** Portal access limited to the projects this user belongs to. */
    public const SCOPE_PROJECTS = 'projects';

    /** Portal access covers every client-visible project the company owns. */
    public const SCOPE_COMPANY = 'company';

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /**
     * Projects this membership is limited to, when its scope is `projects`.
     *
     * Deliberately not client_project_memberships: that table requires a
     * workspace membership, which an external portal user never has.
     *
     * @return BelongsToMany<ClientProject, $this>
     */
    public function scopedProjects(): BelongsToMany
    {
        return $this->belongsToMany(
            ClientProject::class,
            'client_portal_project_access',
            'client_company_membership_id',
            'client_project_id',
        )->withPivot('workspace_id')->withTimestamps();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
