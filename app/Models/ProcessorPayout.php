<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Services\Finance\ProcessorSettlementRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment-processor payout, as one workspace sees it: the signed amount
 * that reached (or left) the bank, the day it arrives, how it was started and
 * where it stands. Written only by {@see ProcessorSettlementRecorder}, from the
 * processor's own reports; the workspace's payments name it by
 * `provider_payout_identifier`.
 *
 * @property string $public_id
 * @property string $provider
 * @property string $provider_payout_identifier
 * @property int $amount
 * @property string $currency
 * @property string $type
 * @property string $status
 * @property CarbonImmutable|null $arrival_date
 * @property int $provider_updated_at_ms
 * @property int $lock_version
 */
#[Fillable([
    'workspace_id', 'provider', 'provider_payout_identifier', 'amount', 'currency', 'arrival_date',
    'type', 'status', 'provider_updated_at_ms',
])]
#[Hidden(['id', 'workspace_id', 'provider_updated_at_ms', 'lock_version'])]
class ProcessorPayout extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'arrival_date' => DateOnly::class,
            'provider_updated_at_ms' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
