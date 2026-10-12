<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A client company's confirmed Square customer (#438).
 *
 * Resolved once per company, never per invoice. The `active_*` slots hold the
 * company and customer while the mapping is current and are cleared with it,
 * which is what keeps one active mapping per side - see the migration.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property string $square_customer_id
 * @property int|null $active_company_slot
 * @property string|null $active_customer_slot
 * @property int|null $confirmed_by_user_id
 * @property CarbonImmutable $confirmed_at
 * @property int|null $cleared_by_user_id
 * @property CarbonImmutable|null $cleared_at
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'square_customer_id', 'active_company_slot', 'active_customer_slot',
    'confirmed_by_user_id', 'confirmed_at', 'cleared_by_user_id', 'cleared_at',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'active_company_slot', 'active_customer_slot', 'confirmed_by_user_id', 'cleared_by_user_id'])]
class CompanySquareCustomer extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
