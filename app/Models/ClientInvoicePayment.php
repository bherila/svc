<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Services\Billing\InvoiceLifecycleService;
use App\Support\Billing\InvoicePaymentStatus;
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

    /**
     * Does this row carry a status the application cannot read?
     *
     * The column is an unconstrained `varchar(24)` and stays that way, because
     * an import carries whatever the source system called it. Every guard that
     * reads payment status is a *positive* filter - the succeeded ones, the
     * pending ones - so a row outside the vocabulary drops out of all of them
     * at once: it contributes nothing to a balance, reserves nothing against a
     * new charge, and blocks nothing from being voided. Each of those is a
     * money decision made on the strength of a value nobody read. This is how
     * a guard asks the opposite question.
     *
     * **Asked in PHP, deliberately, rather than as a `whereNotIn`.** The
     * connection collates `utf8mb4_unicode_ci` by default, so SQL reads
     * `SUCCEEDED` as equal to `succeeded` while
     * {@see InvoicePaymentStatus::tryFrom()} does not. A SQL predicate would
     * therefore clear exactly the rows this exists to catch, and disagree with
     * the recomputation that refuses them - the guard and the thing it guards
     * would be answering different questions on the same row.
     * {@see InvoiceLifecycleService::refreshStatus()}
     * already validates row by row for the same reason.
     *
     * The payment set of one invoice is small and every caller loads it anyway.
     */
    public function hasUnreadableStatus(): bool
    {
        return InvoicePaymentStatus::tryFrom((string) $this->getAttribute('status')) === null;
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
