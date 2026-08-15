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

/**
 * An allocation linking one external finance transaction to one SVC payment.
 * The same external transaction may therefore have multiple allocations.
 *
 * @property CarbonImmutable|null $reconciled_on
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
            'reconciled_on' => 'immutable_date',
            'is_active' => 'boolean',
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
