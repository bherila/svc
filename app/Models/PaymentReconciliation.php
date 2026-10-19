<?php

namespace App\Models;

use App\Casts\DateOnly;
use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Services\Billing\InvoiceLifecycleService;
use App\Services\Finance\PaymentReconciliationService;
use App\Support\Finance\ReconciliationReviewReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An allocation linking one external finance transaction to one SVC payment.
 * The same external transaction may therefore have multiple allocations.
 *
 * `review_reason` and `review_flagged_at` are not fillable: a processor's
 * refund or dispute sets them ({@see InvoiceLifecycleService})
 * and a finance write that fits the payment clears them
 * ({@see PaymentReconciliationService::upsert()}).
 *
 * @property CarbonImmutable|null $reconciled_on
 * @property ReconciliationReviewReason|null $review_reason
 * @property CarbonImmutable|null $review_flagged_at
 */
#[Fillable([
    'workspace_id', 'client_invoice_payment_id', 'external_system_slug', 'external_transaction_uuid',
    'allocated_amount', 'currency', 'reconciled_on', 'created_by_user_id', 'is_active',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_payment_id', 'created_by_user_id'])]
class PaymentReconciliation extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'integer',
            'reconciled_on' => DateOnly::class,
            'is_active' => 'boolean',
            'review_reason' => ReconciliationReviewReason::class,
            'review_flagged_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<ClientInvoicePayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(ClientInvoicePayment::class, 'client_invoice_payment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
