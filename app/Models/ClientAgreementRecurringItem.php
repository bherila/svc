<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Billing\ChargeCadence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'public_id', 'workspace_id', 'client_agreement_id', 'description', 'cadence', 'anchor_month', 'anchor_day',
    'effective_on', 'expires_on', 'quantity', 'amount', 'currency', 'is_taxable', 'is_active', 'sort_order',
])]
#[Hidden(['id', 'workspace_id', 'client_agreement_id'])]
/**
 * @property-read ChargeCadence|null $charge_cadence
 * @property-read CarbonImmutable|null $start_date
 * @property-read CarbonImmutable|null $end_date
 * @property-read float $charge_amount
 */
class ClientAgreementRecurringItem extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

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

    // ── Billing engine surface ───────────────────────────────────────────────
    //
    // The recurring-item biller was ported rather than rewritten, so the model
    // presents the names that arithmetic reads. See ClientAgreement for the
    // same seam on retainer terms.

    /**
     * The engine's name for the charge cadence, as the enum it matches on.
     *
     * The predecessor cast this column to the enum; here the column stays a
     * string and the conversion happens at this seam.
     */
    public function getChargeCadenceAttribute(): ?ChargeCadence
    {
        return ChargeCadence::tryFrom((string) $this->cadence);
    }

    /** The engine's name for the first eligible billing date. */
    public function getStartDateAttribute(): ?CarbonImmutable
    {
        return $this->effective_on === null ? null : CarbonImmutable::parse($this->effective_on);
    }

    /** The engine's name for the last eligible billing date. */
    public function getEndDateAttribute(): ?CarbonImmutable
    {
        return $this->expires_on === null ? null : CarbonImmutable::parse($this->expires_on);
    }

    /** The engine reasons in whole currency units; this column is minor units. */
    public function getChargeAmountAttribute(): float
    {
        return ((int) ($this->amount ?? 0)) / 100;
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
