<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * One pass of the Square mirror sync (#438): who or what asked for it, the
 * Square-Version it sent, what it saw and changed, and how it ended.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property string $trigger
 * @property string $status
 * @property int|null $requested_by_user_id
 * @property string $api_version
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int $locations_seen
 * @property int $customers_seen
 * @property int $customers_changed
 * @property int $invoices_seen
 * @property int $invoices_changed
 * @property int $payments_resolved
 * @property CarbonImmutable|null $high_water_mark
 * @property bool $truncated
 * @property string|null $error_code
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'workspace_id', 'trigger', 'status', 'requested_by_user_id', 'api_version', 'started_at', 'finished_at',
    'locations_seen', 'customers_seen', 'customers_changed', 'invoices_seen', 'invoices_changed', 'payments_resolved',
    'high_water_mark', 'truncated', 'error_code',
])]
#[Hidden(['id', 'workspace_id', 'requested_by_user_id'])]
class SquareSyncRun extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    public const string REQUESTED = 'requested';

    public const string RUNNING = 'running';

    public const string COMPLETED = 'completed';

    public const string FAILED = 'failed';

    public const string TRIGGER_SCHEDULE = 'schedule';

    public const string TRIGGER_REQUEST = 'request';

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'high_water_mark' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'truncated' => 'boolean',
            'locations_seen' => 'integer',
            'customers_seen' => 'integer',
            'customers_changed' => 'integer',
            'invoices_seen' => 'integer',
            'invoices_changed' => 'integer',
            'payments_resolved' => 'integer',
        ];
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
