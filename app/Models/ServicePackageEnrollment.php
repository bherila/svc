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
 * A client company's subscription to a package: its project, its agreement,
 * the version it was offered and, once a company contact accepts, who signed
 * and the exact version they signed (ACC-2), and the invoice sign-up charged
 * (ACC-3). Written only by the package services; the acceptance facts and the
 * sign-up invoice are written once and never changed.
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
 * @property CarbonImmutable|null $accepted_at
 * @property int|null $accepted_by_user_id
 * @property string|null $signer_name
 * @property string|null $signer_title
 * @property int|null $accepted_version_id
 * @property int|null $signup_invoice_id
 * @property CarbonImmutable|null $activated_at
 * @property int|null $activated_by_user_id
 * @property CarbonImmutable|null $lapsed_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'service_package_id', 'service_package_version_id', 'client_company_id', 'client_project_id', 'client_agreement_id', 'service_package_request_id', 'offered_by_user_id', 'accepted_by_user_id', 'accepted_version_id', 'signup_invoice_id', 'activated_by_user_id', 'lock_version'])]
class ServicePackageEnrollment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'offered_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'lapsed_at' => 'immutable_datetime',
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

    /** @return BelongsTo<ServicePackageVersion, $this> the version offered */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }

    /** @return BelongsTo<ServicePackageVersion, $this> the version signed, once accepted */
    public function acceptedVersion(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'accepted_version_id');
    }

    /** The version the enrollment's agreement carries: the one signed, or until then the one offered. */
    public function carriedVersionId(): int
    {
        return $this->accepted_version_id ?? $this->service_package_version_id;
    }

    /** @return BelongsTo<ClientInvoice, $this> the invoice acceptance issued for what sign-up charges, if any */
    public function signupInvoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'signup_invoice_id');
    }

    /** @return BelongsTo<ServicePackageRequest, $this> the sign-up request it answers, if any */
    public function packageRequest(): BelongsTo
    {
        return $this->belongsTo(ServicePackageRequest::class, 'service_package_request_id');
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
