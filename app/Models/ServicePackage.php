<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Packages\PackageStatus;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service package's stable identity (docs/service-packages.md).
 *
 * Its terms live on its versions; this row holds what is true of the package
 * across versions. Written only by ServicePackageWorkflow, through forceFill,
 * so nothing is mass-assignable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $link_name
 * @property string $status
 * @property bool $is_listed
 * @property bool $portal_addable
 * @property int|null $published_version_id
 * @property int|null $created_by_user_id
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'published_version_id', 'created_by_user_id', 'lock_version'])]
class ServicePackage extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'is_listed' => 'boolean',
            'portal_addable' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function packageStatus(): PackageStatus
    {
        return PackageStatus::from($this->status);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<ServicePackageVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ServicePackageVersion::class);
    }

    /** @return BelongsTo<ServicePackageVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'published_version_id');
    }
}
