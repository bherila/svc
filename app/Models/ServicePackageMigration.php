<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Packages\Migration\MigrationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A scheduled move of a package's subscribers from one version to a newer one
 * (MIG-1): the effective date, the owner's message to the clients, and
 * whether it is still scheduled, completed or cancelled (MIG-5). Its targets
 * are the subscribers. Written only by the package migration services.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_id
 * @property int $from_version_id
 * @property int $to_version_id
 * @property CarbonImmutable $effective_on
 * @property string|null $message
 * @property string $status
 * @property int|null $scheduled_by_user_id
 * @property CarbonImmutable $scheduled_at
 * @property int|null $cancelled_by_user_id
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $completed_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'service_package_id', 'from_version_id', 'to_version_id', 'scheduled_by_user_id', 'cancelled_by_user_id', 'lock_version'])]
class ServicePackageMigration extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'effective_on' => DateOnly::class,
            'scheduled_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function migrationStatus(): MigrationStatus
    {
        return MigrationStatus::from($this->status);
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /** @return BelongsTo<ServicePackageVersion, $this> the version its subscribers move from */
    public function fromVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'from_version_id');
    }

    /** @return BelongsTo<ServicePackageVersion, $this> the version they move to */
    public function toVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'to_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function scheduledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scheduled_by_user_id');
    }

    /** @return HasMany<ServicePackageMigrationTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(ServicePackageMigrationTarget::class, 'service_package_migration_id');
    }
}
