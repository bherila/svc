<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id', 'client_invoice_id', 'status', 'amount', 'refunded_amount', 'currency', 'received_on',
    'method', 'reference', 'notes', 'provider', 'provider_payment_identifier',
    'external_finance_transaction_uuid', 'idempotency_key',
])]
#[Hidden(['id', 'workspace_id', 'client_invoice_id', 'notes', 'provider_payment_identifier', 'external_finance_transaction_uuid', 'idempotency_key'])]
class ClientInvoicePayment extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId;

    protected function casts(): array
    {
        return ['amount' => 'integer', 'refunded_amount' => 'integer', 'received_on' => 'date'];
    }

    /** @return BelongsTo<ClientInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ClientInvoice::class, 'client_invoice_id');
    }
}
