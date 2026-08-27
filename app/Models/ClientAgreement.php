<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Billing\BillingCadence;
use App\Support\Billing\FirstCycleProration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $signed_at
 */
#[Fillable([
    'public_id', 'workspace_id', 'client_company_id', 'client_project_id', 'source_proposal_id', 'title', 'status',
    'starts_on', 'ends_on', 'agreement_text', 'is_visible_to_client', 'currency', 'hourly_rate_amount',
    'retainer_amount', 'retainer_minutes', 'billing_cadence', 'rollover_policy', 'activated_at', 'signed_at',
    'signed_by_user_id', 'signer_name', 'signer_title', 'terminated_at',
    // Retainer and rollover terms the billing engine reads.
    'catch_up_threshold_minutes', 'period_retainer_minutes', 'period_retainer_amount',
    'rollover_months', 'initial_rollover_minutes', 'bill_overage_interim',
    'first_cycle_proration', 'agreement_link',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_project_id', 'source_proposal_id', 'signed_by_user_id'])]
/**
 * @property-read float $monthly_retainer_hours
 * @property-read float $monthly_retainer_fee
 * @property-read float|null $retainer_hours
 * @property-read float|null $retainer_fee
 * @property-read CarbonImmutable|null $active_date
 * @property-read CarbonImmutable|null $termination_date
 */
class ClientAgreement extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'is_visible_to_client' => 'boolean',
            'hourly_rate_amount' => 'integer',
            'retainer_amount' => 'integer',
            'retainer_minutes' => 'integer',
            'catch_up_threshold_minutes' => 'integer',
            'period_retainer_minutes' => 'integer',
            'period_retainer_amount' => 'integer',
            'rollover_months' => 'integer',
            'initial_rollover_minutes' => 'integer',
            'bill_overage_interim' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'signed_at' => 'immutable_datetime',
            'terminated_at' => 'immutable_datetime',
        ];
    }

    // ── Billing engine surface ───────────────────────────────────────────────
    //
    // The retainer and rollover engine was ported from the predecessor
    // implementation, where these terms lived under different names and in
    // different units. Rather than rewrite that arithmetic - the FIFO expiry,
    // carry-forward and carry-back rules are exactly where a transcription slip
    // would be expensive and silent - the model presents the shape it expects
    // and the conversion happens here, once.
    //
    // The engine reasons in decimal hours and whole currency units. This schema
    // stores integer minutes and minor units. Conversion belongs at this seam,
    // never inside the arithmetic.

    /** Cycle grouping policy; an unset or unrecognised cadence bills monthly. */
    public function effectiveBillingCadence(): BillingCadence
    {
        return BillingCadence::tryFrom((string) $this->billing_cadence) ?? BillingCadence::Monthly;
    }

    /** How the opening cycle is treated when the agreement starts mid-period. */
    public function effectiveFirstCycleProration(): FirstCycleProration
    {
        return FirstCycleProration::tryFrom((string) $this->first_cycle_proration)
            ?? FirstCycleProration::ProrateHours;
    }

    /** Retainer hours granted per calendar month. */
    public function getMonthlyRetainerHoursAttribute(): float
    {
        return ((int) ($this->retainer_minutes ?? 0)) / 60;
    }

    /** Retainer fee per calendar month, in whole currency units. */
    public function getMonthlyRetainerFeeAttribute(): float
    {
        return ((int) ($this->retainer_amount ?? 0)) / 100;
    }

    /**
     * Retainer for one whole billing cycle, when the agreement overrides it.
     *
     * The engine prefers this over the monthly figure times the cycle length.
     * Six of the nine source agreements set the hours override, so this is the
     * ordinary path rather than an edge case.
     */
    public function getRetainerHoursAttribute(): ?float
    {
        return $this->period_retainer_minutes === null ? null : $this->period_retainer_minutes / 60;
    }

    public function getRetainerFeeAttribute(): ?float
    {
        return $this->period_retainer_amount === null ? null : $this->period_retainer_amount / 100;
    }

    /**
     * Retainer hours for one whole billing cycle.
     *
     * Prefers the period-level override when the agreement carries one,
     * otherwise scales the monthly figure by the cycle length. Six of the nine
     * source agreements set the override, so the first branch is the ordinary
     * path.
     */
    public function periodRetainerHours(): float
    {
        return $this->retainer_hours ?? $this->monthly_retainer_hours * $this->effectiveBillingCadence()->monthsInCycle();
    }

    /** Retainer fee for one whole billing cycle, in whole currency units. */
    public function periodRetainerFee(): float
    {
        return $this->retainer_fee ?? $this->monthly_retainer_fee * $this->effectiveBillingCadence()->monthsInCycle();
    }

    /**
     * Spare capacity the next cycle must open with.
     *
     * When allocation would leave less than this available, the shortfall is
     * billed as catch-up hours so the client is never left with a retainer that
     * cannot absorb ordinary work.
     *
     * Unset means one hour, capped at the retainer itself. The cap matters: an
     * agreement with no retainer would otherwise inherit a one-hour buffer it
     * has no capacity to hold, and every invoice would bill an hour of catch-up
     * for a threshold that should not exist.
     */
    public function getCatchUpThresholdHoursAttribute(): float
    {
        return $this->catch_up_threshold_minutes === null
            ? min(1.0, $this->monthly_retainer_hours)
            : ((int) $this->catch_up_threshold_minutes) / 60;
    }

    /**
     * A threshold larger than the retainer can never be satisfied, so every
     * invoice would bill catch-up hours to restore a buffer that does not fit.
     * Rejected on save rather than tolerated, because the symptom - a client
     * billed extra every single cycle - reads as a pricing decision.
     *
     * @throws InvalidArgumentException
     */
    public function assertCatchUpThresholdFitsRetainer(): void
    {
        $threshold = $this->catch_up_threshold_hours;
        $retainerHours = $this->periodRetainerHours();

        if ($threshold < 0 || $threshold > $retainerHours) {
            throw new InvalidArgumentException(
                "catch_up_threshold_hours must be between 0 and period retainer hours ({$retainerHours}). Got: {$threshold}",
            );
        }
    }

    protected static function booted(): void
    {
        static::saving(static function (self $agreement): void {
            $agreement->assertCatchUpThresholdFitsRetainer();
        });
    }

    /** The engine's name for the hourly rate, in whole currency units. */
    public function getHourlyRateAttribute(): float
    {
        return ((int) ($this->hourly_rate_amount ?? 0)) / 100;
    }

    /** The engine's name for the date the agreement takes effect. */
    public function getActiveDateAttribute(): ?CarbonImmutable
    {
        return $this->starts_on;
    }

    /** The engine's name for the date the agreement ends. */
    public function getTerminationDateAttribute(): ?CarbonImmutable
    {
        return $this->ends_on;
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
