<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['public_id', 'workspace_id', 'client_proposal_id', 'description', 'quantity', 'unit_amount', 'cadence', 'sort_order'])]
#[Hidden(['id', 'workspace_id', 'client_proposal_id'])]
class ClientProposalItem extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected static function booted(): void
    {
        static::creating(function (ClientProposalItem $item): void {
            if ($item->proposal()->where('status', 'accepted')->exists()) {
                throw new \LogicException('Items cannot be added to accepted proposals.');
            }
        });

        static::updating(function (ClientProposalItem $item): void {
            if ($item->proposal()->where('status', 'accepted')->exists()) {
                throw new \LogicException('Items belonging to accepted proposals are immutable.');
            }
        });

        static::deleting(function (ClientProposalItem $item): void {
            if ($item->proposal()->where('status', 'accepted')->exists()) {
                throw new \LogicException('Items belonging to accepted proposals are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_amount' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientProposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ClientProposal::class, 'client_proposal_id');
    }
}
