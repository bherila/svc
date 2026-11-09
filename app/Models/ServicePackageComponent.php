<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A priced line of a package version: a recurring charge or a setup fee.
 *
 * Named like `client_agreement_recurring_items` (description, cadence, amount,
 * quantity, is_taxable, sort_order), which acceptance copies it onto.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_version_id
 * @property string $kind
 * @property string $description
 * @property string $cadence
 * @property int $amount
 * @property numeric-string $quantity
 * @property bool $is_taxable
 * @property int $sort_order
 */
#[Hidden(['id', 'workspace_id', 'service_package_version_id'])]
class ServicePackageComponent extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_taxable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ServicePackageVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePackageVersion::class, 'service_package_version_id');
    }
}
