<?php

namespace App\Models;

use App\Contracts\WorkspaceOwned;
use App\Models\Concerns\BelongsToWorkspace;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\IncrementsAgentRevision;
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
 */
#[Fillable([
    'workspace_id', 'client_company_id', 'client_agreement_id', 'client_billing_schedule_id',
    'invoice_number', 'status', 'issue_date', 'due_date', 'service_period_start',
    'service_period_end', 'currency', 'subtotal_amount', 'tax_amount', 'total_amount',
    'paid_amount', 'balance_amount', 'notes', 'void_reason', 'is_visible_to_client', 'issued_at', 'voided_at',
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
}
