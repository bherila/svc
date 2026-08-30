<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** @property Carbon|null $received_on */
#[Fillable([
    'workspace_id', 'client_invoice_id', 'status', 'amount', 'refunded_amount', 'currency', 'received_on',
    'method', 'reference', 'notes', 'provider', 'provider_payment_identifier',
    'provider_event_created_at', 'provider_event_id', 'external_finance_transaction_uuid', 'idempotency_key',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'notes', 'provider_payment_identifier', 'provider_event_created_at', 'provider_event_id', 'external_finance_transaction_uuid', 'idempotency_key'])]
class ClientInvoicePayment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'received_on' => 'date',
            'provider_event_created_at' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<PaymentReconciliation, $this> */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(PaymentReconciliation::class, 'client_invoice_payment_id');
    }
}
