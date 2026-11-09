<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Packages\Migration\MigrationRules;
use App\Support\Packages\Migration\MigrationTargetStatus;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One subscriber of a scheduled move (MIG-1, MIG-3): the enrollment, the
 * agreement it moves from, and once applied the agreement that carries the
 * new version's terms from the effective date. `outcome_code` says why it was
 * skipped, or - while still scheduled - why it is waiting. The new price and
 * the company's autopay limit at scheduling are what MIG-4 flags. Written only
 * by the package migration services.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_migration_id
 * @property int $service_package_enrollment_id
 * @property int $client_company_id
 * @property int $client_agreement_id
 * @property int|null $successor_agreement_id
 * @property CarbonImmutable $effective_on
 * @property string $status
 * @property string|null $outcome_code
 * @property string|null $outcome_detail
 * @property CarbonImmutable|null $outcome_recorded_at
 * @property int $new_price_amount
 * @property string $new_price_currency
 * @property int|null $autopay_limit_amount
 * @property CarbonImmutable|null $settled_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'service_package_migration_id', 'service_package_enrollment_id', 'client_company_id', 'client_agreement_id', 'successor_agreement_id', 'lock_version'])]
class ServicePackageMigrationTarget extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'effective_on' => DateOnly::class,
            'outcome_recorded_at' => 'immutable_datetime',
            'new_price_amount' => 'integer',
            'autopay_limit_amount' => 'integer',
            'settled_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function targetStatus(): MigrationTargetStatus
    {
        return MigrationTargetStatus::from($this->status);
    }

    /** Whether this move held its first period back from automatic invoicing ({@see MigrationRules::deferredFirstPeriod()}), on the workspace's calendar in `$timezone`. */
    public function deferredFirstPeriod(DateTimeZone|string $timezone): bool
    {
        return MigrationRules::deferredFirstPeriod($this->targetStatus(), $this->settled_at?->setTimezone($timezone), $this->effective_on);
    }

    /** @return BelongsTo<ServicePackageMigration, $this> */
    public function migration(): BelongsTo
    {
        return $this->belongsTo(ServicePackageMigration::class, 'service_package_migration_id');
    }

    /** @return BelongsTo<ServicePackageEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(ServicePackageEnrollment::class, 'service_package_enrollment_id');
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<ClientAgreement, $this> the agreement it moves from */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }

    /** @return BelongsTo<ClientAgreement, $this> the agreement carrying the new terms, once applied */
    public function successorAgreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'successor_agreement_id');
    }
}
