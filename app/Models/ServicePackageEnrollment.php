<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Packages\EnrollmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client company's subscription to a package: its project, its agreement and
 * the version the agreement carries. Written only by the package services.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_id
 * @property int $service_package_version_id
 * @property int $client_company_id
 * @property int $client_project_id
 * @property int $client_agreement_id
 * @property int|null $service_package_request_id
 * @property string $status
 * @property int|null $offered_by_user_id
 * @property CarbonImmutable $offered_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'service_package_id', 'service_package_version_id', 'client_company_id', 'client_project_id', 'client_agreement_id', 'service_package_request_id', 'offered_by_user_id', 'lock_version'])]
class ServicePackageEnrollment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'offered_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function enrollmentStatus(): EnrollmentStatus
    {
        return EnrollmentStatus::from($this->status);
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /** @return BelongsTo<ServicePackageVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<ClientProject, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(ClientProject::class, 'client_project_id');
    }

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }
}
