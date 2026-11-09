<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a package's terms: a draft until published, then frozen (PKG-2).
 *
 * Term columns carry the names and units of `client_agreements`; PackageTerms
 * is the one reading of them. Written only by ServicePackageWorkflow.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $service_package_id
 * @property int $number
 * @property int|null $draft_marker
 * @property string $name
 * @property string|null $summary
 * @property string|null $body_markdown
 * @property string|null $agreement_text
 * @property string $currency
 * @property string $billing_cadence
 * @property int|null $hourly_rate_amount
 * @property int|null $retainer_amount
 * @property int|null $retainer_minutes
 * @property int|null $rollover_months
 * @property string|null $first_cycle_proration
 * @property bool $charge_setup_fee_at_signup
 * @property bool $charge_first_period
 * @property bool $require_autopay
 * @property int|null $autopay_limit_amount
 * @property bool $pay_before_activation
 * @property int $payment_lapse_days
 * @property string $activation_mode
 * @property string $invoice_issuing
 * @property int $notice_days
 * @property int|null $minimum_term_months
 * @property string $cancellation_mode
 * @property list<array{key: string, label: string, kind: string, required: bool}>|null $intake_questions
 * @property CarbonImmutable|null $published_at
 * @property int|null $published_by_user_id
 */
#[Hidden(['id', 'workspace_id', 'service_package_id', 'draft_marker', 'published_by_user_id'])]
class ServicePackageVersion extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'draft_marker' => 'integer',
            'hourly_rate_amount' => 'integer',
            'retainer_amount' => 'integer',
            'retainer_minutes' => 'integer',
            'rollover_months' => 'integer',
            'charge_setup_fee_at_signup' => 'boolean',
            'charge_first_period' => 'boolean',
            'require_autopay' => 'boolean',
            'autopay_limit_amount' => 'integer',
            'pay_before_activation' => 'boolean',
            'payment_lapse_days' => 'integer',
            'notice_days' => 'integer',
            'minimum_term_months' => 'integer',
            'intake_questions' => 'array',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    /** @return BelongsTo<ServicePackage, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    /** @return HasMany<ServicePackageComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(ServicePackageComponent::class)->orderBy('sort_order')->orderBy('id');
    }
}
