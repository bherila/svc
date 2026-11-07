<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Support\Billing\Autopay\AutopayAttemptState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invoice's one autopay attempt (#636), or the provider's decision to skip
 * autopay for it. `(workspace_id, client_invoice_id)` is unique, so claiming
 * the charge is an insert and a second claimant finds the row taken.
 *
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $client_invoice_id
 * @property int $client_company_id
 * @property AutopayAttemptState $state
 * @property int|null $amount
 * @property string|null $currency
 * @property int|null $limit_amount
 * @property string|null $consent_public_id the consent record in force when the charge was claimed
 * @property string|null $payment_method_label
 * @property string|null $payment_intent_id
 * @property string|null $failure_code
 * @property string|null $failure_reason
 * @property int|null $skipped_by_user_id
 * @property CarbonImmutable|null $skipped_at
 * @property CarbonImmutable $claimed_at
 * @property CarbonImmutable|null $resolved_at
 * @property int|null $resolved_by_user_id who closed out a held attempt after checking Stripe (#641)
 * @property string|null $resolution_note
 * @property-read User|null $skippedBy
 * @property-read User|null $resolvedBy
 */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'client_company_id', 'state', 'amount', 'currency', 'limit_amount',
    'consent_public_id', 'payment_method_label', 'payment_intent_id', 'failure_code', 'failure_reason',
    'skipped_by_user_id', 'skipped_at', 'claimed_at', 'resolved_at', 'resolved_by_user_id', 'resolution_note',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'client_company_id', 'skipped_by_user_id', 'resolved_by_user_id'])]
class InvoiceAutopayAttempt extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'state' => AutopayAttemptState::class,
            'amount' => 'integer',
            'limit_amount' => 'integer',
            'skipped_at' => 'immutable_datetime',
            'claimed_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function skippedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'skipped_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    protected function workspaceOwnershipIsImmutable(): bool
    {
        return true;
    }
}
