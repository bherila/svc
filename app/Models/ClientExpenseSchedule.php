<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int|null $client_project_id
 * @property CarbonImmutable $starts_on
 * @property string $cadence
 * @property int $next_occurrence
 * @property int $amount
 * @property string $currency
 * @property string $description
 * @property bool $is_active
 */
#[Fillable(['workspace_id', 'client_company_id', 'client_project_id', 'starts_on', 'cadence', 'next_occurrence', 'amount', 'currency', 'description', 'is_active'])]
final class ClientExpenseSchedule extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'next_occurrence' => 'integer', 'amount' => 'integer', 'is_active' => 'boolean'];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
