<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
use App\Support\Billing\InvoiceKind;
use App\Support\Billing\InvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property CarbonImmutable|null $issue_date
 * @property CarbonImmutable|null $due_date
 * @property CarbonImmutable|null $service_period_start
 * @property CarbonImmutable|null $service_period_end
 * @property string|null $invoice_kind
 * @property CarbonImmutable|null $cycle_start
 * @property CarbonImmutable|null $cycle_end
 * @property CarbonImmutable|null $paid_on
 * @property numeric-string|null $retainer_hours_included
 * @property numeric-string|null $hours_worked
 * @property numeric-string|null $rollover_hours_used
 * @property numeric-string|null $unused_hours_balance
 * @property numeric-string|null $negative_hours_balance
 * @property numeric-string|null $hours_billed_at_rate
 * @property numeric-string|null $starting_unused_hours
 * @property numeric-string|null $starting_negative_hours
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'client_agreement_id', 'client_billing_schedule_id',
    'invoice_number', 'status', 'issue_date', 'due_date', 'service_period_start',
    'service_period_end', 'currency', 'subtotal_amount', 'tax_amount', 'total_amount',
    'paid_amount', 'balance_amount', 'notes', 'void_reason', 'is_visible_to_client', 'issued_at', 'voided_at',
    // Restored ledger detail. These have casts but had no place in the fillable
    // list, so every generator write was silently discarded before reaching the
    // row - the same fault that hid the recurring-item quantity and the invoice
    // line hours.
    'invoice_kind', 'cycle_start', 'cycle_end', 'paid_on', 'retainer_hours_included', 'hours_worked',
    'rollover_hours_used', 'unused_hours_balance', 'negative_hours_balance', 'hours_billed_at_rate',
    'starting_unused_hours', 'starting_negative_hours',
])]
#[Hidden(['id', 'workspace_id', 'client_company_id', 'client_agreement_id', 'client_billing_schedule_id', 'notes', 'void_reason'])]
class ClientInvoice extends Model implements WorkspaceOwned
{
    use BelongsToWorkspace, HasPublicId, IncrementsAgentRevision;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'cycle_start' => 'date',
            'cycle_end' => 'date',
            'paid_on' => 'date',
            'retainer_hours_included' => 'decimal:2',
            'hours_worked' => 'decimal:2',
            'rollover_hours_used' => 'decimal:2',
            'unused_hours_balance' => 'decimal:2',
            'negative_hours_balance' => 'decimal:2',
            'hours_billed_at_rate' => 'decimal:2',
            'starting_unused_hours' => 'decimal:4',
            'starting_negative_hours' => 'decimal:4',
            'issued_at' => 'datetime',
            'voided_at' => 'datetime',
            'is_visible_to_client' => 'boolean',
            'subtotal_amount' => 'integer',
            'tax_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'balance_amount' => 'integer',
        ];
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

    /** @return BelongsTo<ClientAgreement, $this> */
    public function agreement(): BelongsTo
    {
        return $this->belongsTo(ClientAgreement::class, 'client_agreement_id');
    }

    /** @return HasMany<ClientInvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ClientInvoiceLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<ClientInvoicePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(ClientInvoicePayment::class);
    }

    /** @return HasMany<ClientInvoiceEmailDelivery, $this> */
    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(ClientInvoiceEmailDelivery::class);
    }

    /**
     * Whether this invoice has left draft and may no longer be rewritten.
     *
     * Keyed on status rather than on `issued_at`: a draft can be marked paid
     * directly, leaving `issued_at` null, and such an invoice must never be
     * silently regenerated back into a draft.
     */
    public function isImmutable(): bool
    {
        // `partially_paid` belongs here: money has changed hands, so resetting
        // the invoice to draft and rebuilding its lines would rewrite what the
        // client has already paid against.
        return InvoiceStatus::fromStored($this->status)->isSettled();
    }

    /** Classification, defaulting to the ordinary full-cycle invoice. */
    public function invoiceKindValue(): string
    {
        $kind = InvoiceKind::tryFrom((string) $this->invoice_kind);

        return ($kind ?? InvoiceKind::CadencePeriod)->value;
    }

    /**
     * Re-derive the money totals from the lines currently attached.
     *
     * Deliberately a sum of each line's own `total_amount` rather than
     * {@see MoneyService::invoiceTotals()}: generated lines legitimately carry a
     * zero or empty quantity (a retainer draw-down bills hours at no charge) and
     * a negative amount (an applied overpayment credit), both of which that
     * helper rejects by design for operator-entered lines.
     */
    public function recalculateTotals(): void
    {
        $subtotal = 0;
        $tax = 0;
        foreach ($this->lines()->get() as $line) {
            $subtotal += (int) $line->total_amount;
            $tax += (int) $line->tax_amount;
        }

        $total = $subtotal + $tax;
        $this->forceFill([
            'subtotal_amount' => $subtotal,
            'tax_amount' => $tax,
            'total_amount' => $total,
            'balance_amount' => $total - (int) $this->paid_amount,
        ])->save();
    }
}
