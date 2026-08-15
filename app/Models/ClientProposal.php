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
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $status
 * @property CarbonImmutable|null $valid_until
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $accepted_at
 */
#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'created_by_user_id', 'title', 'summary',
    'terms', 'currency', 'is_visible_to_client', 'valid_until', 'status', 'sent_at', 'accepted_at', 'declined_at',
    'expired_at', 'accepted_by_user_id', 'acceptance_signer_name', 'acceptance_signer_title',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'created_by_user_id', 'accepted_by_user_id'])]
class ClientProposal extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected static function booted(): void
    {
        static::updating(function (ClientProposal $proposal): void {
            $dirty = array_diff(array_keys($proposal->getDirty()), ['updated_at']);

            if ($proposal->getOriginal('status') === 'accepted' && $dirty !== []) {
                throw new \LogicException('Accepted proposals are immutable.');
            }
        });

        static::deleting(function (ClientProposal $proposal): void {
            if ($proposal->status === 'accepted') {
                throw new \LogicException('Accepted proposals are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_visible_to_client' => 'boolean',
            'valid_until' => 'immutable_date',
            'sent_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
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

    /** @return HasMany<ClientProposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ClientProposalItem::class);
    }

    /** @return HasMany<ClientAgreement, $this> */
    public function agreements(): HasMany
    {
        return $this->hasMany(ClientAgreement::class, 'source_proposal_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function totalAmount(): int
    {
        return $this->items->sum(function (ClientProposalItem $item): int {
            $quantity = (string) $item->quantity;
            [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');
            $thousandths = ((int) $whole * 1000) + (int) str_pad(substr($fraction, 0, 3), 3, '0');

            $wholeAmount = intdiv($thousandths, 1000) * $item->unit_amount;
            $fractionProduct = ($thousandths % 1000) * $item->unit_amount;

            return $wholeAmount + intdiv($fractionProduct + 500, 1000);
        });
    }
}
