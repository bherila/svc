<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Square\Mirror\SquareMirrorRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Square location as the last sync saw it (#438). Read-only mirror data:
 * written only by {@see SquareMirrorRepository}.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $square_location_id
 * @property string|null $name
 * @property string|null $business_name
 * @property string|null $status
 * @property string|null $currency
 * @property string|null $timezone
 * @property string $fingerprint
 * @property CarbonImmutable $synced_at
 * @property CarbonImmutable $last_seen_at
 * @property int|null $last_sync_run_id
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['workspace_id', 'square_location_id', 'name', 'business_name', 'status', 'currency', 'timezone', 'fingerprint', 'synced_at', 'last_seen_at', 'last_sync_run_id'])]
#[Hidden(['id', 'workspace_id', 'fingerprint', 'deleted_at', 'last_sync_run_id'])]
class SquareLocationSnapshot extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'synced_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'last_sync_run_id' => 'integer',
            'deleted_at' => 'immutable_datetime',
        ];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
