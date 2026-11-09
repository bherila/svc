<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Engagement\Cancellation\ScheduledTerminationSource;
use App\Support\Engagement\Cancellation\ScheduledTerminationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accepted end of an agreement that has not been reached (EDGE-9): the
 * agreement stays active with `ends_on` set to this date, and the end it
 * replaced is kept here so undoing it restores that. On the date the
 * scheduled command terminates the agreement (CAN-5). At most one is
 * `scheduled` per agreement; AgreementCancellations holds that under the
 * agreement's lock. Written only by AgreementCancellations and
 * AgreementWorkflow::terminate().
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_agreement_id
 * @property int|null $agreement_cancellation_request_id
 * @property string $status
 * @property string $source
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $previous_ends_on
 * @property int|null $scheduled_by_user_id
 * @property CarbonImmutable $scheduled_at
 * @property int|null $undone_by_user_id
 * @property CarbonImmutable|null $undone_at
 * @property CarbonImmutable|null $terminated_at
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'client_agreement_id', 'agreement_cancellation_request_id', 'scheduled_by_user_id', 'undone_by_user_id', 'lock_version'])]
class AgreementScheduledTermination extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'ends_on' => DateOnly::class,
            'previous_ends_on' => DateOnly::class,
            'scheduled_at' => 'immutable_datetime',
            'undone_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
            'lock_version' => 'integer',
        ];
    }

    public function terminationStatus(): ScheduledTerminationStatus
    {
        return ScheduledTerminationStatus::from($this->status);
    }

    public function terminationSource(): ScheduledTerminationSource
    {
        return ScheduledTerminationSource::from($this->source);
    }

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }

    /** @return BelongsTo<AgreementCancellationRequest, $this> the request whose approval scheduled it, if any */
    public function cancellationRequest(): BelongsTo
    {
        return $this->belongsTo(AgreementCancellationRequest::class, 'agreement_cancellation_request_id');
    }
}
