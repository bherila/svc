<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Engagement\Cancellation\CancellationRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company contact's request to end an agreement, in `request` mode (CAN-3),
 * and the decision on it (CAN-4): approved on the date an owner or admin
 * chose - which schedules the end - declined with a note, or withdrawn by the
 * client while it was pending. Written only by AgreementCancellations.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_company_id
 * @property int $client_agreement_id
 * @property string $status
 * @property CarbonImmutable $requested_end_on
 * @property string|null $reason
 * @property int|null $requested_by_user_id
 * @property CarbonImmutable $requested_at
 * @property int|null $decided_by_user_id
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $decided_end_on
 * @property bool $notice_waived
 * @property string|null $decision_note
 * @property int $lock_version
 */
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_agreement_id', 'requested_by_user_id', 'decided_by_user_id', 'lock_version'])]
class AgreementCancellationRequest extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'requested_end_on' => DateOnly::class,
            'decided_end_on' => DateOnly::class,
            'requested_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
            'notice_waived' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function requestStatus(): CancellationRequestStatus
    {
        return CancellationRequestStatus::from($this->status);
    }

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }

    /** @return BelongsTo<ClientCompany, $this> */
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
