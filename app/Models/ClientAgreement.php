<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'source_proposal_id', 'title', 'status',
    'starts_on', 'ends_on', 'agreement_text', 'is_visible_to_client', 'currency', 'hourly_rate_amount',
    'retainer_amount', 'retainer_minutes', 'billing_cadence', 'rollover_policy', 'activated_at', 'signed_at',
    'signed_by_user_id', 'signer_name', 'signer_title', 'terminated_at',
])]
class ClientAgreement extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_visible_to_client' => 'boolean',
            'hourly_rate_amount' => 'integer',
            'retainer_amount' => 'integer',
            'retainer_minutes' => 'integer',
            'activated_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
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

    /** @return BelongsTo<ClientProposal, $this> */
    public function sourceProposal(): BelongsTo
    {
        return $this->belongsTo(ClientProposal::class, 'source_proposal_id');
    }

    /** @return HasMany<ClientAgreementRecurringItem, $this> */
    public function recurringItems(): HasMany
    {
        return $this->hasMany(ClientAgreementRecurringItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function signedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }
}
