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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int $client_project_id
 * @property int|null $client_task_id
 * @property int $user_id
 * @property CarbonImmutable $worked_on
 * @property int $minutes
 * @property bool $is_billable
 * @property bool $is_deferred
 * @property int|null $billing_rate_amount
 * @property string|null $currency
 * @property string $status
 */
#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'user_id',
    'worked_on', 'minutes', 'description', 'client_visible_description', 'is_billable', 'is_deferred', 'is_visible_to_client', 'billing_rate_amount', 'currency',
    'status', 'approved_by_user_id', 'approved_at', 'subcontractor_cost_amount', 'subcontractor_cost_currency',
    'subcontractor_cost_metadata',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'client_task_id', 'user_id', 'approved_by_user_id'])]
class ClientTimeEntry extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, SoftDeletes;

    protected function casts(): array
    {
        return [
            'worked_on' => 'immutable_date',
            'minutes' => 'integer',
            'is_billable' => 'boolean',
            'is_deferred' => 'boolean',
            'is_visible_to_client' => 'boolean',
            'billing_rate_amount' => 'integer',
            'approved_at' => 'immutable_datetime',
            'subcontractor_cost_amount' => 'integer',
            'subcontractor_cost_metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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

    /** @return BelongsTo<ClientTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ClientTask::class, 'client_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
