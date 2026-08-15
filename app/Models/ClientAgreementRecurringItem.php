<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'workspace_id', 'client_agreement_id', 'description', 'cadence', 'anchor_month', 'anchor_day',
    'effective_on', 'expires_on', 'amount', 'currency', 'is_taxable', 'is_active', 'sort_order',
])]
#[Hidden(['id', 'workspace_id', 'client_agreement_id'])]
class ClientAgreementRecurringItem extends Model
{
    use HasPublicId;

    protected function casts(): array
    {
        return [
            'anchor_month' => 'integer',
            'anchor_day' => 'integer',
            'effective_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'quantity' => 'decimal:3',
            'amount' => 'integer',
            'is_taxable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }
}
