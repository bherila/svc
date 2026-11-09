<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Packages\PackageRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visitor's request for a package, written by the public form and nothing
 * else (PUB-4). Everything in it a visitor typed is untrusted (PUB-6).
 * Written only by PackageRequestWorkflow, through forceFill.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_id
 * @property int $service_package_version_id
 * @property string $status
 * @property string|null $company_name
 * @property string|null $contact_name
 * @property string|null $email
 * @property string $email_hash
 * @property string|null $phone
 * @property array<string, string>|null $answers
 * @property int|null $requester_user_id
 * @property string|null $client_fingerprint
 * @property CarbonImmutable $submitted_at
 * @property int|null $client_company_id
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decline_reason
 * @property CarbonImmutable|null $erased_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'service_package_id', 'service_package_version_id', 'email_hash', 'requester_user_id', 'client_fingerprint', 'client_company_id', 'decided_by_user_id', 'lock_version'])]
class ServicePackageRequest extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'answers' => 'array',
            'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'erased_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function requestStatus(): PackageRequestStatus
    {
        return PackageRequestStatus::from($this->status);
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
}
